<?php

use App\Services\Navigation\GlobalNavigationOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PROVIDER_GROUP = 'nav-v2-provider-network';

    private const PROVIDER_PAGES = [
        ['label' => 'SERVERS PROVIDERS', 'path' => '/infrastructure/server-providers'],
        ['label' => 'CLOUD PROVIDERS', 'path' => '/infrastructure/cloud-providers'],
        ['label' => 'DOMAINS PROVIDERS', 'path' => '/infrastructure/domain-providers'],
        ['label' => 'SPONSORS', 'path' => '/sponsors'],
        ['label' => 'LINODE', 'path' => '/clouds/linode'],
        ['label' => 'DIGITAL OCEAN', 'path' => '/clouds/digitalocean'],
    ];

    public function up(): void
    {
        $this->moveProviderPages(self::PROVIDER_GROUP);
    }

    public function down(): void
    {
        $this->moveProviderPages('nav-v2-mail-infrastructure', [
            'DOMAINS PROVIDERS' => 'nav-v2-domain-management',
            'SPONSORS' => 'nav-v2-offers-suppressions',
            'LINODE' => 'nav-clouds',
            'DIGITAL OCEAN' => 'nav-clouds',
        ]);
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function moveProviderPages(string $defaultGroup, array $overrides = []): void
    {
        if (! Schema::hasTable('app_global_preferences')) {
            return;
        }

        $preference = DB::table('app_global_preferences')->where('name', 'navigation_order')->first();
        if ($preference === null) {
            return;
        }

        $savedOrder = json_decode((string) $preference->value, true);
        if (! is_array($savedOrder)) {
            return;
        }

        $navigationOrder = new GlobalNavigationOrder;
        $groups = [];
        foreach (self::PROVIDER_PAGES as $page) {
            $groups[$overrides[$page['label']] ?? $defaultGroup][] = $navigationOrder->itemKey([
                'label' => $page['label'],
                'href' => $page['path'],
            ]);
        }

        foreach ($groups as $groupKey => $itemKeys) {
            $savedOrder = $navigationOrder->moveItemsIntoGroup($savedOrder, $groupKey, $itemKeys);
        }

        DB::table('app_global_preferences')
            ->where('name', 'navigation_order')
            ->update([
                'value' => json_encode($savedOrder, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }
};
