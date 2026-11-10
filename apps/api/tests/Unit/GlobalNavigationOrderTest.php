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

    public function test_provider_network_default_membership_migrates_existing_saved_subpages(): void
    {
        $order = new GlobalNavigationOrder;
        $serverProvider = ['label' => 'SERVERS PROVIDERS', 'icon' => 'fas fa-tasks', 'href' => '/infrastructure/server-providers'];
        $cloudProvider = ['label' => 'CLOUD PROVIDERS', 'icon' => 'fas fa-cloud-sun', 'href' => '/infrastructure/cloud-providers'];
        $domainProvider = ['label' => 'DOMAINS PROVIDERS', 'icon' => 'fas fa-tasks', 'href' => '/infrastructure/domain-providers'];
        $sponsors = ['label' => 'SPONSORS', 'icon' => 'fas fa-hand-holding-usd', 'href' => '/sponsors'];
        $linode = ['label' => 'LINODE', 'icon' => 'fab fa-linode', 'href' => '/clouds/linode'];
        $digitalOcean = ['label' => 'DIGITAL OCEAN', 'icon' => 'fab fa-digital-ocean', 'href' => '/clouds/digitalocean'];
        $campaign = ['label' => 'CAMPAIGNS', 'icon' => 'fas fa-envelope', 'href' => '/mailer/campaigns'];
        $providerKeys = array_map($order->itemKey(...), [$serverProvider, $cloudProvider, $domainProvider, $sponsors, $linode, $digitalOcean]);

        $savedOrder = $order->moveItemsIntoGroup([
            'groups' => ['nav-v2-provider-network', 'nav-v2-mail-infrastructure', 'nav-v2-domain-management', 'nav-v2-offers-suppressions'],
            'items' => [
                'nav-v2-provider-network' => [$order->itemKey($linode), $order->itemKey($digitalOcean)],
                'nav-v2-mail-infrastructure' => [$order->itemKey($serverProvider), $order->itemKey($cloudProvider), $order->itemKey($campaign)],
                'nav-v2-domain-management' => [$order->itemKey($domainProvider)],
                'nav-v2-offers-suppressions' => [$order->itemKey($sponsors)],
            ],
        ], 'nav-v2-provider-network', $providerKeys);

        self::assertSame($providerKeys, $savedOrder['items']['nav-v2-provider-network']);
        self::assertSame([$order->itemKey($campaign)], $savedOrder['items']['nav-v2-mail-infrastructure']);
        self::assertSame([], $savedOrder['items']['nav-v2-domain-management']);
        self::assertSame([], $savedOrder['items']['nav-v2-offers-suppressions']);
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
