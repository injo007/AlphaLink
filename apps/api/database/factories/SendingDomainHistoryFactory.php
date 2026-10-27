<?php

namespace Database\Factories;

use App\Models\SendingDomain;
use App\Models\SendingDomainHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SendingDomainHistory>
 */
class SendingDomainHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'a_record' => fake()->ipv4(),
            'spf' => 'v=spf1 -all',
            'dkim' => null,
            'dmarc' => 'v=DMARC1; p=reject',
            'is_listed_spamhaus' => false,
            'is_listed_hostkarma' => false,
        ];
    }

    public function forDomain(SendingDomain $domain): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $domain->workspace_id,
            'sending_domain_id' => $domain->getKey(),
        ]);
    }
}
