<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\Workspace;
use App\Services\Campaigns\PmtaCampaignTestTransport;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/** Sends outbound Webmail messages and replies natively through the IP's PowerMTA VMTA ingress. */
class AlphaLinkWebmailOutboundSender
{
    public function __construct(
        private readonly PmtaCampaignTestTransport $transportFactory,
        private readonly AlphaLinkBulkInstallVmtaPlanner $vmtaPlanner,
    ) {}

    public function resolveVmtaName(ManagedServerIp $ip): string
    {
        return $this->vmtaPlanner->ipVirtualMtaName((string) $ip->ip);
    }

    public function resolvePmtaPort(Workspace $workspace): int
    {
        $configuredPort = $workspace->infrastructureSettings?->pmta_port ?? config('infrastructure.pmta_port', 1212);
        $port = filter_var($configuredPort, FILTER_VALIDATE_INT);

        if ($port === false || (int) $port < 1 || (int) $port > 65535) {
            throw new RuntimeException('Configure a valid Workspace PMTA SMTP listener port in Settings before sending.');
        }

        return (int) $port;
    }

    /**
     * @param  array{from: string, to: list<string>, cc?: list<string>, subject: string, text: string, html?: string, in_reply_to?: string, references?: string}  $payload
     * @return array{vmta: string, port: int, message_id: string, raw_message: string}
     */
    public function send(Workspace $workspace, ManagedServer $server, ManagedServerIp $ip, array $payload): array
    {
        $host = (string) $server->ip_address;
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('Target PMTA server does not have a valid IP address.');
        }

        $port = $this->resolvePmtaPort($workspace);
        $vmtaName = $this->resolveVmtaName($ip);
        $messageId = sprintf('<%s@%s>', bin2hex(random_bytes(12)), $ip->dns ?: 'alphalink.local');

        $email = (new Email())
            ->from($payload['from'])
            ->to(...$payload['to'])
            ->subject($payload['subject'])
            ->date(new \DateTimeImmutable())
            ->text($payload['text']);

        if (! empty($payload['cc'])) {
            $email->cc(...$payload['cc']);
        }
        $html = trim((string) ($payload['html'] ?? ''));
        if ($html !== '') {
            $email->html($html);
        } else {
            $safeHtmlBody = nl2br(htmlspecialchars($payload['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            $email->html('<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; font-size: 14px; line-height: 1.55; color: #1e293b;">'.$safeHtmlBody.'</div>');
        }

        $headers = $email->getHeaders();
        $headers->addTextHeader('X-virtual-MTA', $vmtaName);
        $headers->addIdHeader('Message-ID', trim($messageId, '<>'));

        if (trim((string) ($payload['in_reply_to'] ?? '')) !== '') {
            $headers->addIdHeader('In-Reply-To', trim((string) $payload['in_reply_to'], "<> \t\r\n"));
        }
        if (trim((string) ($payload['references'] ?? '')) !== '') {
            $headers->addTextHeader('References', trim((string) $payload['references']));
        }

        $envelopeRecipients = array_map(
            static fn (string $addr): Address => new Address($addr),
            array_values(array_unique(array_merge($payload['to'], $payload['cc'] ?? []))),
        );
        $envelope = new Envelope(new Address($payload['from']), $envelopeRecipients);

        $transport = null;
        try {
            $transport = $this->transportFactory->forServer($server, $port);
            $transport->start();
            (new SymfonyMailer($transport))->send($email, $envelope);
        } catch (Throwable $exception) {
            throw new RuntimeException('PMTA outbound delivery failed: '.$this->sanitizeError($exception), 0, $exception);
        } finally {
            try {
                $transport?->stop();
            } catch (Throwable) {
                // Ignore transport teardown errors after send attempt.
            }
        }

        return [
            'vmta' => $vmtaName,
            'port' => $port,
            'message_id' => $messageId,
            'raw_message' => $email->toString(),
        ];
    }

    private function sanitizeError(Throwable $exception): string
    {
        $raw = $exception->getMessage();
        if ($exception instanceof UnexpectedResponseException && preg_match('/with message\s+"((?:\\\\.|[^"\\\\])*)"/s', $raw, $m) === 1) {
            $raw = stripcslashes($m[1]);
        }

        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $raw) ?? $raw;
        $clean = preg_replace('/\b[A-Za-z0-9+\/_=-]{40,}\b/', '[redacted]', trim($clean)) ?? $clean;

        return mb_substr($clean !== '' ? $clean : 'Unable to reach PMTA SMTP listener', 0, 220);
    }
}
