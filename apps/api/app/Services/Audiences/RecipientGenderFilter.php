<?php

namespace App\Services\Audiences;

class RecipientGenderFilter
{
    /** @param array<string, mixed>|null $membershipProfile @param array<string, mixed>|null $contactProfile */
    public function matches(string $genderFilter, ?array $membershipProfile, ?array $contactProfile = null): bool
    {
        if ($genderFilter === 'all') {
            return true;
        }

        $profile = array_replace($contactProfile ?? [], $membershipProfile ?? []);
        $genderValue = trim((string) ($profile['sex'] ?? ''));
        if ($genderValue === '') {
            $genderValue = trim((string) ($profile['gender'] ?? ''));
        }
        $gender = strtolower($genderValue);
        $normalizedGender = match ($gender) {
            'male', 'm', 'man', 'men' => 'male',
            'female', 'f', 'woman', 'women' => 'female',
            default => null,
        };

        return $normalizedGender === $genderFilter;
    }
}
