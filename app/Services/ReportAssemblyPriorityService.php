<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Throwable;

class ReportAssemblyPriorityService
{
    private const CACHE_KEY = 'reports.report_assembly_priority.v1';

    /**
     * @return array{category_priority:list<string>,item_priority_by_category:array<string,list<string>>}
     */
    public function getSettings(): array
    {
        try {
            /** @var mixed $raw */
            $raw = Cache::store('database')->get(self::CACHE_KEY, []);
        } catch (Throwable) {
            /** @var mixed $raw */
            $raw = Cache::get(self::CACHE_KEY, []);
        }

        $data = is_array($raw) ? $raw : [];
        $categoryPriority = array_values(array_filter(
            array_map(static fn ($value): string => trim((string) $value), (array) ($data['category_priority'] ?? [])),
            static fn (string $value): bool => $value !== ''
        ));

        $itemPriorityByCategory = [];
        foreach ((array) ($data['item_priority_by_category'] ?? []) as $category => $items) {
            $categoryName = trim((string) $category);
            if ($categoryName === '') {
                continue;
            }
            $itemPriorityByCategory[$categoryName] = array_values(array_filter(
                array_map(static fn ($value): string => trim((string) $value), (array) $items),
                static fn (string $value): bool => $value !== ''
            ));
        }

        return [
            'category_priority' => array_values(array_unique($categoryPriority)),
            'item_priority_by_category' => $itemPriorityByCategory,
        ];
    }

    /**
     * @param  list<string>  $orderedCategories
     */
    public function saveCategoryPriority(array $orderedCategories): void
    {
        $settings = $this->getSettings();
        $settings['category_priority'] = array_values(array_unique(array_filter(
            array_map(static fn ($value): string => trim((string) $value), $orderedCategories),
            static fn (string $value): bool => $value !== ''
        )));
        $this->storeSettings($settings);
    }

    /**
     * @param  list<string>  $orderedItems
     */
    public function saveItemPriority(string $category, array $orderedItems): void
    {
        $category = trim($category);
        if ($category === '') {
            return;
        }

        $settings = $this->getSettings();
        $settings['item_priority_by_category'][$category] = array_values(array_unique(array_filter(
            array_map(static fn ($value): string => trim((string) $value), $orderedItems),
            static fn (string $value): bool => $value !== ''
        )));
        $this->storeSettings($settings);
    }

    /**
     * @param  list<object>  $rows
     * @return list<object>
     */
    public function sortRows(array $rows, string $categoryField = 'category_name', string $itemField = 'item_name'): array
    {
        $settings = $this->getSettings();
        $categoryIndex = [];
        foreach ($settings['category_priority'] as $index => $categoryName) {
            $categoryIndex[$categoryName] = $index;
        }

        $itemIndexByCategory = [];
        foreach ($settings['item_priority_by_category'] as $categoryName => $items) {
            $itemIndexByCategory[$categoryName] = [];
            foreach ($items as $index => $itemName) {
                $itemIndexByCategory[$categoryName][$itemName] = $index;
            }
        }

        usort($rows, static function (object $a, object $b) use ($categoryField, $itemField, $categoryIndex, $itemIndexByCategory): int {
            $categoryA = trim((string) ($a->{$categoryField} ?? ''));
            $categoryB = trim((string) ($b->{$categoryField} ?? ''));
            $itemA = trim((string) ($a->{$itemField} ?? ''));
            $itemB = trim((string) ($b->{$itemField} ?? ''));

            $categoryPriorityA = $categoryIndex[$categoryA] ?? PHP_INT_MAX;
            $categoryPriorityB = $categoryIndex[$categoryB] ?? PHP_INT_MAX;
            if ($categoryPriorityA !== $categoryPriorityB) {
                return $categoryPriorityA <=> $categoryPriorityB;
            }
            if ($categoryA !== $categoryB) {
                return strcasecmp($categoryA, $categoryB);
            }

            $itemsForCategory = $itemIndexByCategory[$categoryA] ?? [];
            $itemPriorityA = $itemsForCategory[$itemA] ?? PHP_INT_MAX;
            $itemPriorityB = $itemsForCategory[$itemB] ?? PHP_INT_MAX;
            if ($itemPriorityA !== $itemPriorityB) {
                return $itemPriorityA <=> $itemPriorityB;
            }

            $nameCmp = strcasecmp($itemA, $itemB);
            if ($nameCmp !== 0) {
                return $nameCmp;
            }

            $itemCodeA = trim((string) ($a->item_code ?? ''));
            $itemCodeB = trim((string) ($b->item_code ?? ''));
            $codeCmp = strcasecmp($itemCodeA, $itemCodeB);
            if ($codeCmp !== 0) {
                return $codeCmp;
            }

            return strcasecmp(trim((string) ($a->item_id ?? '')), trim((string) ($b->item_id ?? '')));
        });

        return $rows;
    }

    /**
     * @param  array{category_priority:list<string>,item_priority_by_category:array<string,list<string>>}  $settings
     */
    private function storeSettings(array $settings): void
    {
        try {
            Cache::store('database')->forever(self::CACHE_KEY, $settings);
        } catch (Throwable) {
            Cache::forever(self::CACHE_KEY, $settings);
        }
    }
}
