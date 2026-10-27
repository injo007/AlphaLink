<?php

namespace App\Services\Campaigns;

use App\Models\ManagedServer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;

class PmtaCampaignTestTransport
{
    public function forServer(ManagedServer $server, int $port): SmtpTransport
    {
        $transport = new EsmtpTransport($server->ip_address, $port, false);
        $transport->getStream()->setTimeout(10);

        return $transport;
    }
}
