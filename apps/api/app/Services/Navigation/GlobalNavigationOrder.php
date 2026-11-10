<?php

namespace App\Services\Navigation;

use Illuminate\Support\Str;

class GlobalNavigationOrder
{
    /**
     * Move known navigation destinations to their new default parent once,
     * without disabling future admin reordering of those destinations.
     *
     * @param  list<string>  $itemKeys
     */
    public function moveItemsIntoGroup(array $savedOrder, string $targetGroupKey, array $itemKeys): array
    {
        $itemKeys = array_values(array_unique(array_filter($itemKeys, 'is_string')));
        $items = is_array($savedOrder['items'] ?? null) ? $savedOrder['items'] : [];
        $targetItems = [];

        foreach ($items as $groupKey => $groupItems) {
            if (! is_array($groupItems)) {
                continue;
            }

            $keptItems = [];
            foreach ($groupItems as $itemKey) {
                if (! is_string($itemKey)) {
                    continue;
                }

                if (! in_array($itemKey, $itemKeys, true)) {
                    $keptItems[] = $itemKey;

                    continue;
                }

                if ($groupKey === $targetGroupKey && ! in_array($itemKey, $targetItems, true)) {
                    $targetItems[] = $itemKey;
                }
            }

            $items[$groupKey] = $keptItems;
        }

        $newItems = array_values(array_filter(
            $itemKeys,
            static fn (string $itemKey): bool => ! in_array($itemKey, $targetItems, true),
        ));
        $targetItems = [...$newItems, ...$targetItems];

        $items[$targetGroupKey] = $targetItems;
        $savedOrder['items'] = $items;

        return $savedOrder;
    }

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
                foreach ($this->legacyLabelAliases($item) as $legacyLabel) {
                    $legacyItem = [...$item, 'label' => $legacyLabel];
                    $legacyItemKeys[$this->itemKey($legacyItem)] = $itemKey;
                    $legacyItemKeys[$this->legacyItemKey($groupKey, $legacyItem)] = $itemKey;
                }
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

    /** @return list<string> */
    private function legacyLabelAliases(array $item): array
    {
        $path = $this->navigationPath($item);

        if ($path === '/app/users' && ($item['label'] ?? null) === 'GLOBAL USER ACCOUNTS') {
            return ['USERS', 'USERS USERS'];
        }

        if (preg_match('#^/app/workspaces/[^/]+/users$#', $path) === 1 && ($item['label'] ?? null) === 'WORKSPACE MEMBERS') {
            return ['WORKSPACE USERS', 'USERS WORKSPACE USERS'];
        }

        if (preg_match('#^/app/workspaces/[^/]+/campaign-negatives$#', $path) === 1 && ($item['label'] ?? null) === 'NEGATIVE TOKEN FILES') {
            return ['CAMPAIGN SUPPRESSIONS', 'NEGATIVES'];
        }

        if (preg_match('#^/app/workspaces/[^/]+/drops/suppression$#', $path) === 1 && ($item['label'] ?? null) === 'OFFER/LIST EXCLUSIONS') {
            return ['DROP SUPPRESSIONS', 'SUPPRESSION'];
        }

        return [];
    }

    private function sortBySavedOrder(array $items, mixed $order, string $key): array
    {
        $rank = array_flip(is_array($order) ? array_values($order) : []);
        foreach ($items as $index => &$item) {
            $item['_navigation_default_index'] = $index;
            $item['_navigation_saved_rank'] = $rank[$item[$key] ?? ''] ?? PHP_INT_MAX;
        }
        unset($item);

        $defaultItems = array_values($items);
        usort($items, static fn (array $left, array $right): int => ($left['_navigation_saved_rank'] <=> $right['_navigation_saved_rank'])
            ?: ($left['_navigation_default_index'] <=> $right['_navigation_default_index']));

        $orderedItems = array_values(array_filter($items, static fn (array $item): bool => $item['_navigation_saved_rank'] !== PHP_INT_MAX));
        foreach ($defaultItems as $defaultIndex => $item) {
            if ($item['_navigation_saved_rank'] !== PHP_INT_MAX) {
                continue;
            }

            $orderedKeys = array_column($orderedItems, $key);
            $nextKey = null;
            for ($index = $defaultIndex + 1, $count = count($defaultItems); $index < $count; $index++) {
                $candidateKey = $defaultItems[$index][$key] ?? null;
                if (in_array($candidateKey, $orderedKeys, true)) {
                    $nextKey = $candidateKey;
                    break;
                }
            }

            if ($nextKey !== null) {
                $nextPosition = array_search($nextKey, $orderedKeys, true);
                array_splice($orderedItems, (int) $nextPosition, 0, [$item]);

                continue;
            }

            $previousKey = null;
            for ($index = $defaultIndex - 1; $index >= 0; $index--) {
                $candidateKey = $defaultItems[$index][$key] ?? null;
                if (in_array($candidateKey, $orderedKeys, true)) {
                    $previousKey = $candidateKey;
                    break;
                }
            }

            if ($previousKey === null) {
                $orderedItems[] = $item;

                continue;
            }

            $previousPosition = array_search($previousKey, array_column($orderedItems, $key), true);
            array_splice($orderedItems, (int) $previousPosition + 1, 0, [$item]);
        }

        foreach ($orderedItems as &$item) {
            unset($item['_navigation_default_index'], $item['_navigation_saved_rank']);
        }
        unset($item);

        return $orderedItems;
    }
}
