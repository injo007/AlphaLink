<?php

namespace Database\Factories;

use App\Models\ManagedServerIp;
use App\Models\ManagedServerIpHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ManagedServerIpHistory>
 */
class ManagedServerIpHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => null,
            'managed_server_ip_id' => null,
            'ptr' => 'NA',
            'ip_listed_spamhaus' => false,
            'ip_listed_spamcop' => false,
            'ip_listed_barracuda' => false,
            'sender_score' => 'NA',
            'domain_listed_hostkarma' => false,
            'domain_listed_spamhaus_dbl' => false,
            'domain_listed_surbl' => false,
        ];
    }

    public function forManagedIp(ManagedServerIp $ip): static
    {
        return $this->state([
            'workspace_id' => $ip->workspace_id,
            'managed_server_ip_id' => $ip->getKey(),
        ]);
    }
}
