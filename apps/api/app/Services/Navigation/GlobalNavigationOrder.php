<?php

namespace App\Services\Navigation;

use Illuminate\Support\Str;

class GlobalNavigationOrder
{
    public function apply(array $groups, array $savedOrder): array
    {
        foreach ($groups as &$group) {
            $group['navigation_group_key'] = $this->groupKey($group);
            foreach ($group['items'] ?? [] as &$item) {
                $item['navigation_item_key'] = $this->itemKey($group['navigation_group_key'], $item);
            }
            unset($item);
        }
        unset($group);

        $groups = $this->sortBySavedOrder($groups, $savedOrder['groups'] ?? [], 'navigation_group_key');
        foreach ($groups as &$group) {
            $itemOrder = $savedOrder['items'][$group['navigation_group_key']] ?? [];
            $group['items'] = $this->sortBySavedOrder($group['items'] ?? [], $itemOrder, 'navigation_item_key');
        }
        unset($group);

        return $groups;
    }

    public function groupKey(array $group): string
    {
        return (string) ($group['id'] ?? 'nav-'.Str::slug((string) ($group['label'] ?? 'group')));
    }

    public function itemKey(string $groupKey, array $item): string
    {
        $identity = implode('|', [
            $groupKey,
            (string) ($item['label'] ?? ''),
            (string) ($item['icon'] ?? ''),
        ]);

        return 'nav-item-'.Str::slug($groupKey).'-'.substr(hash('sha256', $identity), 0, 12);
    }

    private function sortBySavedOrder(array $items, mixed $order, string $key): array
    {
        $rank = array_flip(is_array($order) ? array_values($order) : []);
        foreach ($items as $index => &$item) {
            $item['_navigation_default_index'] = $index;
            $item['_navigation_saved_rank'] = $rank[$item[$key] ?? ''] ?? PHP_INT_MAX;
        }
        unset($item);

        usort($items, static fn (array $left, array $right): int => ($left['_navigation_saved_rank'] <=> $right['_navigation_saved_rank'])
            ?: ($left['_navigation_default_index'] <=> $right['_navigation_default_index']));

        foreach ($items as &$item) {
            unset($item['_navigation_default_index'], $item['_navigation_saved_rank']);
        }
        unset($item);

        return array_values($items);
    }
}
