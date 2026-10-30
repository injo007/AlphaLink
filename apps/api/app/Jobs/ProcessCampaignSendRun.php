<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignRecipientSnapshotItem;
use App\Models\CampaignSendBatch;
use App\Models\CampaignSendRun;
use App\Services\Campaigns\CampaignDeliveryAttemptLifecycle;
use App\Services\Campaigns\CampaignSendMessageBuilder;
use App\Services\Campaigns\ClaimCampaignSendBatch;
use App\Services\Campaigns\FinalizeCampaignSendRun;
use App\Services\Campaigns\PmtaCampaignTestTransport;
use App\Services\Campaigns\ReclaimExpiredCampaignSendBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;
use Throwable;

/** Deliver one bounded batch; the remaining run is continued by a delayed follow-up job. */
class ProcessCampaignSendRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public string $sendRunId)
    {
        $this->onQueue('campaign-sends');
    }

    public function handle(
        CampaignSendMessageBuilder $messageBuilder,
        PmtaCampaignTestTransport $transportFactory,
        ClaimCampaignSendBatch $claimer,
        CampaignDeliveryAttemptLifecycle $attemptLifecycle,
        ReclaimExpiredCampaignSendBatch $reclaimer,
        FinalizeCampaignSendRun $finalizer,
    ): void {
        $lock = Cache::lock('campaign-send-run:'.$this->sendRunId, 660);
        if (! $lock->get()) {
            $this->release(15);

            return;
        }

        $run = null;
        try {
            $run = CampaignSendRun::query()->find($this->sendRunId);
            if (! $run || ! in_array($run->state, ['planned', 'running'], true)) {
                return;
            }

            try {
                $context = $messageBuilder->assertReady($run);
            } catch (Throwable $exception) {
                $this->failRun($run, $exception);

                return;
            }

            $workerId = 'campaign-send-'.$run->getKey();
            $batch = $this->claimOrReclaim($run, $workerId, $claimer, $reclaimer);
            if ($batch === null) {
                $run->refresh();
                $snapshot = $run->recipientSnapshot()->first();
                if ($snapshot) {
                    $finalizer->reconcile($run, $snapshot, now());
                    $run->refresh();
                }
                if (in_array($run->state, ['planned', 'running'], true)) {
                    $this->release(20);
                }

                return;
            }

            if ($batch->state !== 'leased' || ! is_string($batch->lease_token)) {
                $run->refresh();
                if (in_array($run->state, ['planned', 'running'], true)) {
                    $this->dispatchContinuation($run, $context, $batch);
                }

                return;
            }

            $leaseToken = $batch->lease_token;
            $transportByServer = [];
            $claims = $batch->recipientClaims()->where('state', 'claimed')->orderBy('position')->get();
            foreach ($claims as $claim) {
                $run->refresh();
                if ($run->state !== 'running') {
                    break;
                }

                $item = CampaignRecipientSnapshotItem::query()
                    ->where('workspace_id', $run->workspace_id)
                    ->where('snapshot_id', $run->recipient_snapshot_id)
                    ->whereKey($claim->snapshot_item_id)
                    ->with('address')
                    ->firstOrFail();
                $recipient = (string) ($item->address?->email ?? '');
                $attempt = $attemptLifecycle->start(
                    (string) $run->getKey(),
                    (string) $batch->getKey(),
                    (string) $item->getKey(),
                    $workerId,
                    $leaseToken,
                    (string) Str::uuid(),
                );
                $outcome = 'uncertain';
                $transportCode = null;
                $errorCode = null;
                $response = null;
                $attemptServerId = null;

                try {
                    if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                        throw new \UnexpectedValueException('The immutable recipient snapshot address is invalid.');
                    }
                    $message = $messageBuilder->build($context, $item, $recipient);
                    $serverId = (string) $message['server']->getKey();
                    $attemptServerId = $serverId;
                    if (! isset($transportByServer[$serverId])) {
                        $transport = $transportFactory->forServer($message['server'], (int) $context['port']);
                        $transport->start();
                        $transportByServer[$serverId] = $transport;
                    }

                    $raw = new RawMessage($message['header']."\r\n\r\n".$message['body']);
                    $envelope = new Envelope(new Address($message['envelope_from']), [new Address($recipient)]);
                    (new SymfonyMailer($transportByServer[$serverId]))->send($raw, $envelope);
                    $outcome = 'accepted';
                    $transportCode = '250';
                } catch (Throwable $exception) {
                    $response = $exception->getMessage();
                    if ($exception instanceof UnexpectedResponseException && $exception->getCode() >= 400 && $exception->getCode() <= 599) {
                        $outcome = 'rejected';
                        $transportCode = (string) $exception->getCode();
                        $errorCode = 'SMTP_'.$exception->getCode();
                    } elseif ($exception instanceof \UnexpectedValueException || $exception instanceof ValidationException) {
                        $outcome = 'rejected';
                        $errorCode = 'MESSAGE_VALIDATION';
                    } else {
                        // A network failure after SMTP DATA can mean PMTA accepted it; never retry an ambiguous attempt.
                        $outcome = 'uncertain';
                        $errorCode = 'SMTP_TRANSPORT_UNCERTAIN';
                    }
                    foreach ($transportByServer as $serverId => $transport) {
                        if ($attemptServerId === (string) $serverId) {
                            try {
                                $transport->stop();
                            } catch (Throwable) {
                            }
                            unset($transportByServer[$serverId]);
                        }
                    }
                }

                $attemptLifecycle->recordOutcome(
                    (string) $run->getKey(),
                    (string) $attempt->getKey(),
                    $workerId,
                    $leaseToken,
                    $outcome,
                    $transportCode,
                    $run->getKey().':'.$item->getKey(),
                    $response,
                    $errorCode,
                );
                $this->updateCampaignProgress($run);
            }

            foreach ($transportByServer as $transport) {
                try {
                    $transport->stop();
                } catch (Throwable) {
                }
            }

            $run->refresh();
            if (in_array($run->state, ['planned', 'running'], true)) {
                $snapshot = $run->recipientSnapshot()->first();
                if ($snapshot) {
                    $finalizer->reconcile($run, $snapshot, now());
                }
                $run->refresh();
            }
            if (in_array($run->state, ['planned', 'running'], true)) {
                $this->dispatchContinuation($run, $context, $batch);
            }
        } catch (Throwable $exception) {
            if ($run instanceof CampaignSendRun) {
                $this->failRun($run, $exception);
            }

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    private function claimOrReclaim(
        CampaignSendRun $run,
        string $workerId,
        ClaimCampaignSendBatch $claimer,
        ReclaimExpiredCampaignSendBatch $reclaimer,
    ): ?CampaignSendBatch {
        $expired = CampaignSendBatch::query()
            ->where('send_run_id', $run->getKey())
            ->where('state', 'leased')
            ->where('lease_expires_at', '<=', now())
            ->orderBy('batch_number')
            ->first();
        if ($expired) {
            $reclaimed = $reclaimer->reclaim((string) $expired->getKey(), $workerId, leaseSeconds: 900);
            if ($reclaimed) {
                return $reclaimed;
            }
        }

        $activeLease = CampaignSendBatch::query()
            ->where('send_run_id', $run->getKey())
            ->where('state', 'leased')
            ->where('lease_expires_at', '>', now())
            ->exists();
        if ($activeLease) {
            return null;
        }

        $setup = data_get($run->revision?->payload, 'send_setup', []);
        $parameters = [...$setup, ...(array) ($setup['parameters'] ?? [])];
        $sleepEmail = max(0, (int) ($parameters['sleep_email'] ?? 0));
        $batchSize = $sleepEmail > 0 || (int) ($parameters['sleep_ip'] ?? 0) > 0 ? 1 : 25;

        return $claimer->claim(
            (string) $run->getKey(),
            $workerId,
            (string) Str::uuid(),
            batchSize: $batchSize,
            leaseSeconds: 900,
        );
    }

    private function dispatchContinuation(CampaignSendRun $run, array $context, CampaignSendBatch $batch): void
    {
        $setup = $context['setup'];
        $parameters = [...$setup, ...(array) ($setup['parameters'] ?? [])];
        $delay = max(0, (int) ($parameters['sleep_email'] ?? 0));
        $fraction = max(1, (int) ($parameters['fraction_ip'] ?? 1));
        if ((int) ($parameters['sleep_ip'] ?? 0) > 0 && ((int) $batch->last_position - (int) $run->start_position + 1) % $fraction === 0) {
            $delay += (int) $parameters['sleep_ip'];
        }

        $job = self::dispatch((string) $run->getKey())->onQueue('campaign-sends');
        if ($delay > 0) {
            $job->delay(now()->addSeconds($delay));
        }
    }

    private function updateCampaignProgress(CampaignSendRun $run): void
    {
        $run->refresh();
        $processed = (int) $run->accepted_count + (int) $run->rejected_count
            + (int) $run->suppressed_count + (int) $run->uncertain_count;
        $percent = $run->recipient_count > 0 ? min(100, (int) floor($processed * 100 / $run->recipient_count)) : 0;
        Campaign::query()->where('workspace_id', $run->workspace_id)->whereKey($run->campaign_id)->update(['send_progress' => $percent]);
    }

    private function failRun(CampaignSendRun $run, Throwable $exception): void
    {
        report($exception);
        DB::transaction(function () use ($run, $exception): void {
            $locked = CampaignSendRun::query()->whereKey($run->getKey())->lockForUpdate()->first();
            if (! $locked || ! in_array($locked->state, ['planned', 'running'], true)) {
                return;
            }
            $options = $locked->execution_options ?? [];
            $options['failure_code'] = $exception instanceof ValidationException ? 'CAMPAIGN_SEND_NOT_READY' : 'CAMPAIGN_SEND_WORKER_ERROR';
            $locked->execution_options = $options;
            $hasDeliveryHistory = (int) $locked->accepted_count > 0
                || (int) $locked->rejected_count > 0
                || (int) $locked->suppressed_count > 0
                || (int) $locked->uncertain_count > 0
                || $locked->deliveryAttempts()->exists();
            $locked->state = $hasDeliveryHistory ? 'needs_review' : 'failed';
            $locked->completed_at = now();
            $locked->save();
        });
    }
}
