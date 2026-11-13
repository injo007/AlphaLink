<?php

namespace App\Services\Campaigns;

use App\Models\ManagedServer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;

class PmtaCampaignTestTransport
{
    public function forServer(ManagedServer $server, int $port): SmtpTransport
    {
        return $this->forAddress($server->ip_address, $port);
    }

    public function forAddress(string $address, int $port): SmtpTransport
    {
        $transport = new EsmtpTransport($address, $port, false);
        $transport->getStream()->setTimeout(10);

        return $transport;
    }
}
