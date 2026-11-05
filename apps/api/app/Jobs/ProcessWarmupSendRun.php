<?php

namespace App\Jobs;

use App\Models\AuditEvent;
use App\Models\Campaign;
use App\Models\User;
use App\Models\WarmupSendRecipient;
use App\Models\WarmupSendRun;
use App\Models\Workspace;
use App\Services\Campaigns\PmtaCampaignTestTransport;
use App\Services\Campaigns\WarmupSendMessageBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;
use Throwable;

class ProcessWarmupSendRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public string $sendRunId)
    {
        $this->onQueue('campaign-sends');
    }

    public function handle(WarmupSendMessageBuilder $messageBuilder, PmtaCampaignTestTransport $transportFactory): void
    {
        $lock = Cache::lock('warmup-send-run:'.$this->sendRunId, 660);
        if (! $lock->get()) {
            $this->release(15);

            return;
        }

        $run = null;
        $transports = [];
        try {
            $run = WarmupSendRun::query()->find($this->sendRunId);
            if (! $run || ! in_array($run->state, ['planned', 'running'], true)) {
                return;
            }
            $options = (array) ($run->execution_options ?? []);
            $nextBatchAt = $options['next_batch_at'] ?? null;
            if (is_string($nextBatchAt) && $nextBatchAt !== '' && Carbon::parse($nextBatchAt)->isFuture()) {
                return;
            }
            unset($options['next_batch_at']);
            $run->execution_options = $options;
            $run->state = 'running';
            $run->started_at ??= now();
            $run->save();

            $campaign = Campaign::query()
                ->where('workspace_id', $run->workspace_id)
                ->whereKey($run->campaign_id)
                ->where('kind', 'warmup')
                ->firstOrFail();
            $workspace = Workspace::query()->whereKey($run->workspace_id)->where('status', 'active')->firstOrFail();
            $actor = User::query()->findOrFail($run->requested_by);
            $context = $messageBuilder->context($run, $campaign, $actor, $workspace);
            $emailSleep = max(0, (int) ($options['sleep_email_ms'] ?? 0));
            $ipSleep = max(0, (int) ($options['sleep_ip_ms'] ?? 0));
            $fraction = max(1, (int) ($options['fraction_ip'] ?? 1));
            $batchSize = $emailSleep > 0 || $ipSleep > 0 ? 1 : 25;
            $recipients = $run->recipients()->where('state', 'pending')->orderBy('position')->limit($batchSize)->get();

            foreach ($recipients as $recipientRow) {
                $run->refresh();
                if ($run->state !== 'running') {
                    break;
                }
                $recipient = Crypt::decryptString($recipientRow->recipient_ciphertext);
                $outcome = 'uncertain';
                $transportCode = null;
                $errorCode = null;
                $exceptionMessage = null;

                try {
                    $message = $messageBuilder->build($context, (int) $recipientRow->position, $recipient, $run);
                    $serverId = (string) $message['server']->getKey();
                    if (! isset($transports[$serverId])) {
                        $transports[$serverId] = $transportFactory->forServer($message['server'], (int) $context['port']);
                        $transports[$serverId]->start();
                    }
                    $raw = new RawMessage($message['header']."\r\n\r\n".$message['body']);
                    $envelope = new Envelope(new Address($message['envelope_from']), [new Address($recipient)]);
                    (new SymfonyMailer($transports[$serverId]))->send($raw, $envelope);
                    $outcome = 'accepted';
                    $transportCode = '250';
                } catch (Throwable $exception) {
                    $exceptionMessage = $exception->getMessage();
                    if ($exception instanceof UnexpectedResponseException && $exception->getCode() >= 400 && $exception->getCode() <= 599) {
                        $outcome = 'rejected';
                        $transportCode = (string) $exception->getCode();
                        $errorCode = 'SMTP_'.$exception->getCode();
                    } elseif ($exception instanceof \UnexpectedValueException || $exception instanceof ValidationException) {
                        $outcome = 'rejected';
                        $errorCode = 'MESSAGE_VALIDATION';
                    } else {
                        $outcome = 'uncertain';
                        $errorCode = 'SMTP_TRANSPORT_UNCERTAIN';
                    }
                    foreach ($transports as $serverId => $transport) {
                        try {
                            $transport->stop();
                        } catch (Throwable) {
                        }
                        unset($transports[$serverId]);
                    }
                }

                DB::transaction(function () use ($run, $recipientRow, $outcome, $transportCode, $errorCode, $exceptionMessage): void {
                    $lockedRun = WarmupSendRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();
                    $lockedRecipient = WarmupSendRecipient::query()->whereKey($recipientRow->getKey())->lockForUpdate()->firstOrFail();
                    if ($lockedRecipient->state !== 'pending') {
                        return;
                    }
                    $lockedRecipient->state = $outcome;
                    $lockedRecipient->transport_code = $transportCode;
                    $lockedRecipient->error_code = $errorCode;
                    $lockedRecipient->response_sha256 = $exceptionMessage === null ? null : hash('sha256', $exceptionMessage);
                    $lockedRecipient->finished_at = now();
                    $lockedRecipient->save();
                    $lockedRun->increment($outcome.'_count');
                    $lockedRun->next_position = max((int) $lockedRun->next_position, (int) $recipientRow->position + 1);
                    $lockedRun->save();
                }, attempts: 3);
            }

            $run->refresh();
            if (! in_array($run->state, ['planned', 'running'], true)) {
                return;
            }

            $hasPending = $run->recipients()->where('state', 'pending')->exists();
            if (! $hasPending) {
                $run->state = $run->uncertain_count > 0 ? 'needs_review' : 'completed';
                $run->completed_at = now();
                $run->save();
                AuditEvent::query()->create([
                    'workspace_id' => $run->workspace_id,
                    'actor_user_id' => $run->requested_by,
                    'action' => 'warmup.send_completed',
                    'subject_type' => WarmupSendRun::class,
                    'subject_id' => $run->getKey(),
                    'properties' => [
                        'campaign_id' => (string) $run->campaign_id,
                        'recipient_count' => (int) $run->recipient_count,
                        'accepted_count' => (int) $run->accepted_count,
                        'rejected_count' => (int) $run->rejected_count,
                        'uncertain_count' => (int) $run->uncertain_count,
                        'state' => $run->state,
                    ],
                    'occurred_at' => now(),
                ]);

                return;
            }

            $delay = $emailSleep;
            $lastPosition = max(0, (int) $run->next_position - 1);
            if ($ipSleep > 0 && $lastPosition % $fraction === 0) {
                $delay += $ipSleep;
            }
            if ($delay > 0) {
                $run->execution_options = [...(array) $run->execution_options, 'next_batch_at' => now()->addSeconds($delay)->toIso8601String()];
                $run->save();
                self::dispatch((string) $run->getKey())->onQueue('campaign-sends')->delay(now()->addSeconds($delay));
            } else {
                self::dispatch((string) $run->getKey())->onQueue('campaign-sends');
            }
        } catch (Throwable $exception) {
            report($exception);
            if ($run instanceof WarmupSendRun && in_array($run->state, ['planned', 'running'], true)) {
                $run->state = 'failed';
                $run->execution_options = [...(array) ($run->execution_options ?? []), 'failure_code' => 'WARMUP_SEND_WORKER_ERROR'];
                $run->completed_at = now();
                $run->save();
            }
        } finally {
            foreach ($transports as $transport) {
                try {
                    $transport->stop();
                } catch (Throwable) {
                }
            }
            $lock->release();
        }
    }
}
