<?php

namespace Tests\Unit;

use App\Services\Navigation\GlobalNavigationOrder;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

class GlobalNavigationOrderTest extends TestCase
{
    public function test_saved_subpages_can_be_reparented_and_keep_their_saved_order(): void
    {
        $order = new GlobalNavigationOrder;
        $groups = $this->navigationGroups();
        $sourcePageKey = $order->itemKey($groups[0]['items'][0]);
        $targetPageKey = $order->itemKey($groups[0]['items'][1]);
        $thirdPageKey = $order->itemKey($groups[1]['items'][0]);

        $arranged = $order->apply($groups, [
            'groups' => ['nav-audience', 'nav-campaigns'],
            'items' => [
                'nav-campaigns' => [$targetPageKey],
                'nav-audience' => [$thirdPageKey, $sourcePageKey],
            ],
        ]);

        self::assertSame('Audience', $arranged[0]['label']);
        self::assertSame([$thirdPageKey, $sourcePageKey], array_column($arranged[0]['items'], 'navigation_item_key'));
        self::assertSame([$targetPageKey], array_column($arranged[1]['items'], 'navigation_item_key'));
    }

    public function test_unmentioned_subpages_remain_in_their_default_parent(): void
    {
        $order = new GlobalNavigationOrder;
        $groups = $this->navigationGroups();
        $movedPageKey = $order->itemKey($groups[0]['items'][0]);
        $sourcePageKey = $order->itemKey($groups[0]['items'][1]);
        $destinationDefaultKey = $order->itemKey($groups[1]['items'][0]);

        $arranged = $order->apply($groups, [
            'groups' => [],
            'items' => ['nav-audience' => [$movedPageKey]],
        ]);

        self::assertSame([$sourcePageKey], array_column($arranged[0]['items'], 'navigation_item_key'));
        self::assertSame([$movedPageKey, $destinationDefaultKey], array_column($arranged[1]['items'], 'navigation_item_key'));
    }

    public function test_existing_parent_bound_saved_keys_are_migrated(): void
    {
        $order = new GlobalNavigationOrder;
        $groups = $this->navigationGroups();
        $page = $groups[0]['items'][0];
        $legacyKey = 'nav-item-'.Str::slug('nav-campaigns').'-'.substr(hash('sha256', 'nav-campaigns|'.$page['label'].'|'.$page['icon']), 0, 12);
        $stableKey = $order->itemKey($page);
        $destinationDefaultKey = $order->itemKey($groups[1]['items'][0]);

        $arranged = $order->apply($groups, [
            'items' => [
                'nav-audience' => [$legacyKey],
            ],
        ]);

        self::assertSame([$stableKey, $destinationDefaultKey], array_column($arranged[1]['items'], 'navigation_item_key'));
    }

    public function test_new_default_group_is_inserted_near_its_default_neighbors_in_saved_order(): void
    {
        $order = new GlobalNavigationOrder;
        $groups = [
            ['id' => 'nav-operations', 'label' => 'Operations', 'items' => []],
            ['id' => 'nav-mail', 'label' => 'Mail Infrastructure', 'items' => []],
            ['id' => 'nav-provider-network', 'label' => 'Provider Network', 'items' => []],
            ['id' => 'nav-domains', 'label' => 'Domain Management', 'items' => []],
            ['id' => 'nav-settings', 'label' => 'Settings', 'items' => []],
        ];

        $arranged = $order->apply($groups, [
            'groups' => ['nav-operations', 'nav-mail', 'nav-domains', 'nav-settings'],
        ]);

        self::assertSame(
            ['nav-operations', 'nav-mail', 'nav-provider-network', 'nav-domains', 'nav-settings'],
            array_column($arranged, 'navigation_group_key'),
        );
    }

    private function navigationGroups(): array
    {
        return [
            [
                'id' => 'nav-campaigns',
                'label' => 'Campaigns',
                'items' => [
                    ['label' => 'Create', 'icon' => 'fas fa-plus', 'href' => '/app/campaigns/create'],
                    ['label' => 'Overview', 'icon' => 'fas fa-chart-line', 'href' => '/app/audience/segments'],
                ],
            ],
            [
                'id' => 'nav-audience',
                'label' => 'Audience',
                'items' => [
                    ['label' => 'Lists', 'icon' => 'fas fa-list', 'href' => '/app/audience/lists'],
                ],
            ],
        ];
    }
}
