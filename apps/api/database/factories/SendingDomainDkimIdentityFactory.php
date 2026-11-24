<?php

namespace Database\Factories;

use App\Models\SendingDomain;
use App\Models\SendingDomainDkimIdentity;
use App\Services\Infrastructure\DkimKeyMaterialManager;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SendingDomainDkimIdentity>
 */
class SendingDomainDkimIdentityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $keys = new DkimKeyMaterialManager;
        $privateKey = $keys->generatePrivateKey();

        return [
            'hostname' => 'mail.'.$this->faker->unique()->domainName(),
            'selector' => 'al'.bin2hex(random_bytes(8)),
            'private_key' => $privateKey,
            'public_record' => $keys->publicRecord($privateKey),
            'is_active' => true,
        ];
    }

    public function forDomain(SendingDomain $domain): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $domain->workspace_id,
            'sending_domain_id' => $domain->getKey(),
            'hostname' => (string) $domain->name,
        ]);
    }
}
