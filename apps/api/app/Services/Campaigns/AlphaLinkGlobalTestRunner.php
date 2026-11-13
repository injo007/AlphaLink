<?php

namespace App\Services\Campaigns;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;
use Throwable;

class AlphaLinkGlobalTestRunner
{
    public function __construct(
        private readonly PmtaCampaignTestTransport $transportFactory,
        private readonly AlphaLinkCampaignTagRenderer $tagRenderer,
    ) {}

    /**
     * @param  array<string, mixed>  $configuration
     * @return array{configured: bool, output: string, error: string, exit_code: int|null}
     */
    public function run(array $configuration): array
    {
        $port = filter_var($configuration['pmta_port'] ?? null, FILTER_VALIDATE_INT);
        if ($port === false || $port < 1 || $port > 65535 || ! $this->isConfigured($port)) {
            return $this->failure(false, 'The workspace PMTA SMTP listener is not configured with a valid port.');
        }

        $header = base64_decode((string) ($configuration['header'] ?? ''), true);
        $body = base64_decode((string) ($configuration['body'] ?? ''), true);
        if ($header === false || trim($header) === '') {
            return $this->failure(true, 'Enter a valid message header.');
        }
        if ($body === false) {
            return $this->failure(true, 'The message body is not valid encoded text.');
        }

        $recipients = array_values(array_unique(array_map(
            static fn (mixed $email): string => mb_strtolower(trim((string) $email)),
            is_array($configuration['email_tests'] ?? null) ? $configuration['email_tests'] : [],
        )));
        if ($recipients === []) {
            return $this->failure(true, 'Enter at least one valid test address.');
        }

        $targets = $this->targets($configuration);
        if ($targets === []) {
            return $this->failure(true, 'Select at least one active server route.');
        }

        $repeat = filter_var($configuration['repeat'] ?? 1, FILTER_VALIDATE_INT);
        $maxMessages = max(1, (int) config('global-tests.max_messages', 100));
        if ($repeat === false || $repeat < 1) {
            return $this->failure(true, 'Repeat must be a positive whole number.');
        }
        $totalMessages = count($targets) * count($recipients) * $repeat;
        if ($totalMessages > $maxMessages) {
            return $this->failure(true, "This test would send {$totalMessages} messages. Reduce the selection to {$maxMessages} or fewer per run.");
        }

        $accepted = 0;
        $rejected = [];
        $messageIndex = 0;
        foreach ($targets as $target) {
            foreach ($recipients as $recipient) {
                for ($copy = 0; $copy < $repeat; $copy++) {
                    $messageIndex++;
                    try {
                        $message = $this->renderMessage($header, $body, $configuration, $target, $recipient, $messageIndex);
                        $transport = $this->transportFactory->forAddress($target['server_ip'], (int) $port);
                        try {
                            $transport->start();
                            $raw = new RawMessage($message['header']."\r\n\r\n".$message['body']);
                            $envelope = new Envelope(new Address($message['envelope_from']), [new Address($recipient)]);
                            (new SymfonyMailer($transport))->send($raw, $envelope);
                            $accepted++;
                        } finally {
                            $this->stop($transport);
                        }
                    } catch (Throwable $exception) {
                        $rejected[] = $this->failureLabel($target, $exception);
                    }
                }
            }
        }

        if ($accepted === 0) {
            $error = $rejected[0] ?? 'PMTA did not accept a test message.';

            return $this->failure(true, $error);
        }

        $output = "PMTA accepted {$accepted} of {$totalMessages} test message(s). Inbox delivery is unconfirmed.";
        if ($rejected !== []) {
            $output .= "\n\nNot accepted: ".implode('; ', array_slice($rejected, 0, 5));
            if (count($rejected) > 5) {
                $output .= '; additional route results omitted';
            }
            $output .= '. Check the selected PMTA server logs before retrying those routes.';
        }

        return [
            'configured' => true,
            'output' => $output,
            'error' => '',
            'exit_code' => $rejected === [] ? 0 : 1,
        ];
    }

    public function isConfigured(?int $port): bool
    {
        return $port !== null && $port >= 1 && $port <= 65535;
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return list<array{server_id: string, server_name: string, server_ip: string, main_domain: string, ip: string, domain: string, rdns: string, vmta: string, username: string, ids: string, idi: string, idd: string}>
     */
    private function targets(array $configuration): array
    {
        $targets = [];
        foreach (($configuration['servers'] ?? []) as $server) {
            if (! is_array($server) || ! is_array($server['ips'] ?? null)) {
                continue;
            }
            $serverIp = (string) ($server['main_ip'] ?? '');
            if (filter_var($serverIp, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            foreach ($server['ips'] as $ip) {
                if (! is_array($ip) || filter_var((string) ($ip['ip'] ?? ''), FILTER_VALIDATE_IP) === false) {
                    continue;
                }
                $vmta = trim((string) ($ip['vmta'] ?? ''));
                if (preg_match('/\A[A-Za-z0-9_.:-]{1,255}\z/', $vmta) !== 1) {
                    continue;
                }
                $targets[] = [
                    'server_id' => (string) ($server['id'] ?? ''),
                    'server_name' => (string) ($server['name'] ?? 'PMTA server'),
                    'server_ip' => $serverIp,
                    'main_domain' => (string) ($server['main_domain'] ?? ''),
                    'ip' => (string) $ip['ip'],
                    'domain' => (string) ($ip['domain'] ?? ''),
                    'rdns' => (string) ($ip['rdns'] ?? ''),
                    'vmta' => $vmta,
                    'username' => (string) ($ip['username'] ?? ''),
                    'ids' => (string) ($ip['ids'] ?? $server['id'] ?? ''),
                    'idi' => (string) ($ip['idi'] ?? ''),
                    'idd' => (string) ($ip['idd'] ?? ''),
                ];
            }
        }

        return $targets;
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, string>  $target
     * @return array{header: string, body: string, envelope_from: string}
     */
    private function renderMessage(
        string $header,
        string $body,
        array $configuration,
        array $target,
        string $recipient,
        int $messageIndex,
    ): array {
        $tags = [
            'server' => $target['server_name'],
            'main_domain' => $target['main_domain'],
            'ip' => $target['ip'],
            'domain' => $target['domain'],
            'rdns' => $target['rdns'],
            'to' => $recipient,
            'name' => explode('@', $recipient)[0],
            'date' => now()->toRfc2822String(),
            'negative' => (string) ($configuration['negative'] ?? ''),
            'smtp' => $target['username'],
            'smtp_name' => $target['username'],
            'ids' => $target['ids'],
            'idi' => $target['idi'],
            'idd' => $target['idd'],
            'ide' => '0',
        ];
        $placeholders = is_array($configuration['placeholders'] ?? null) ? $configuration['placeholders'] : [];
        $boundaryCache = [];
        $renderedHeader = $this->tagRenderer->render($header, $tags, $placeholders, $messageIndex - 1, $boundaryCache);
        $renderedBody = $this->tagRenderer->render($body, $tags, $placeholders, $messageIndex - 1, $boundaryCache);
        $renderedReturnPath = $this->tagRenderer->render((string) ($configuration['return_path'] ?? ''), $tags, $placeholders, $messageIndex - 1, $boundaryCache);
        $from = $this->headerAddress($renderedHeader, 'from');
        $envelopeFrom = trim($renderedReturnPath) !== '' ? $this->mailbox($renderedReturnPath) : $from;
        if ($from === '' || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('The message From header must resolve to a valid email address.');
        }
        if ($envelopeFrom === '' || filter_var($envelopeFrom, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('The return path must resolve to a valid email address.');
        }

        $renderedHeader = $this->replaceHeader($renderedHeader, 'to', $recipient);
        $renderedHeader = $this->replaceHeader($renderedHeader, 'x-virtual-mta', $target['vmta']);

        return [
            'header' => str_replace(["\r\n", "\r"], "\n", trim($renderedHeader)),
            'body' => str_replace(["\r\n", "\r"], "\n", $renderedBody),
            'envelope_from' => $envelopeFrom,
        ];
    }

    private function headerAddress(string $header, string $name): string
    {
        $line = collect(preg_split('/\r\n|\r|\n/', $header) ?: [])
            ->first(fn (string $line): bool => preg_match('/^\s*'.preg_quote($name, '/').'\s*:/i', $line) === 1);

        return $line === null ? '' : $this->mailbox((string) preg_replace('/^\s*'.preg_quote($name, '/').'\s*:\s*/i', '', $line));
    }

    private function mailbox(string $value): string
    {
        if (preg_match('/<\s*([^<>]+)\s*>/', $value, $matches) === 1) {
            return trim($matches[1]);
        }

        return trim($value);
    }

    private function replaceHeader(string $header, string $name, string $value): string
    {
        $pattern = '/^\s*'.preg_quote($name, '/').'\s*:[^\r\n]*(?:\r?\n[ \t][^\r\n]*)*/im';
        $header = preg_replace($pattern, '', str_replace(["\r\n", "\r"], "\n", $header)) ?? $header;

        return rtrim($header, "\n")."\n".ucwords($name, '-').': '.$value;
    }

    private function failureLabel(array $target, Throwable $exception): string
    {
        $label = $target['server_name'].' / '.$target['ip'];
        if ($exception instanceof UnexpectedResponseException && $exception->getCode() >= 400 && $exception->getCode() <= 599) {
            return $label.' (SMTP '.$exception->getCode().' rejected)';
        }
        if ($exception instanceof \InvalidArgumentException) {
            return $label.' ('.$exception->getMessage().')';
        }

        return $label.' (PMTA connection or response was not confirmed)';
    }

    private function stop(SmtpTransport $transport): void
    {
        try {
            $transport->stop();
        } catch (Throwable) {
        }
    }

    /** @return array{configured: bool, output: string, error: string, exit_code: int|null} */
    private function failure(bool $configured, string $error): array
    {
        return ['configured' => $configured, 'output' => '', 'error' => $error, 'exit_code' => null];
    }
}
