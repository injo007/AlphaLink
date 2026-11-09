<?php

namespace App\Services\Navigation;

use Illuminate\Support\Str;

class GlobalNavigationOrder
{
    public function apply(array $groups, array $savedOrder): array
    {
        $itemsByKey = [];
        $legacyItemKeys = [];
        $defaultItemsByGroup = [];

        foreach ($groups as &$group) {
            $groupKey = $this->groupKey($group);
            $group['navigation_group_key'] = $groupKey;
            $defaultItemsByGroup[$groupKey] = [];

            foreach ($group['items'] ?? [] as &$item) {
                $itemKey = $this->itemKey($item);
                $item['navigation_item_key'] = $itemKey;
                $itemsByKey[$itemKey] = $item;
                $legacyItemKeys[$this->legacyItemKey($groupKey, $item)] = $itemKey;
                $defaultItemsByGroup[$groupKey][] = $itemKey;
            }
            unset($item);
        }
        unset($group);

        $itemsByGroup = array_fill_keys(array_keys($defaultItemsByGroup), []);
        $assignedItems = [];

        foreach ($savedOrder['items'] ?? [] as $groupKey => $itemOrder) {
            if (! array_key_exists($groupKey, $itemsByGroup) || ! is_array($itemOrder)) {
                continue;
            }

            foreach ($itemOrder as $savedItemKey) {
                if (! is_string($savedItemKey)) {
                    continue;
                }

                $itemKey = $legacyItemKeys[$savedItemKey] ?? $savedItemKey;
                if (! array_key_exists($itemKey, $itemsByKey) || isset($assignedItems[$itemKey])) {
                    continue;
                }

                $itemsByGroup[$groupKey][] = $itemsByKey[$itemKey];
                $assignedItems[$itemKey] = true;
            }
        }

        foreach ($defaultItemsByGroup as $groupKey => $itemKeys) {
            foreach ($itemKeys as $itemKey) {
                if (isset($assignedItems[$itemKey])) {
                    continue;
                }

                $itemsByGroup[$groupKey][] = $itemsByKey[$itemKey];
                $assignedItems[$itemKey] = true;
            }
        }

        $groups = $this->sortBySavedOrder($groups, $savedOrder['groups'] ?? [], 'navigation_group_key');
        foreach ($groups as &$group) {
            $group['items'] = $itemsByGroup[$group['navigation_group_key']] ?? [];
        }
        unset($group);

        return $groups;
    }

    public function groupKey(array $group): string
    {
        return (string) ($group['id'] ?? 'nav-'.Str::slug((string) ($group['label'] ?? 'group')));
    }

    public function itemKey(array $item): string
    {
        $identity = implode('|', [
            (string) ($item['label'] ?? ''),
            $this->navigationPath($item),
        ]);

        return 'nav-item-'.substr(Str::slug((string) ($item['label'] ?? 'page')), 0, 48).'-'.substr(hash('sha256', $identity), 0, 12);
    }

    private function legacyItemKey(string $groupKey, array $item): string
    {
        $identity = implode('|', [
            $groupKey,
            (string) ($item['label'] ?? ''),
            (string) ($item['icon'] ?? ''),
        ]);

        return 'nav-item-'.Str::slug($groupKey).'-'.substr(hash('sha256', $identity), 0, 12);
    }

    private function navigationPath(array $item): string
    {
        $path = parse_url((string) ($item['href'] ?? ''), PHP_URL_PATH);

        return is_string($path) ? $path : '';
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
