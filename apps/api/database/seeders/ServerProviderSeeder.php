<?php

namespace Database\Seeders;

use App\Models\ServerProvider;
use Illuminate\Database\Seeder;

class ServerProviderSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Digital', 'digital', 'Ovh', 'Linode', 'linode', 'ikoula', 'vultr', 'Azure', 'reg'] as $name) {
            ServerProvider::query()->firstOrCreate(['name' => $name]);
        }
    }
}
