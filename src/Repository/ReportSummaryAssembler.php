<?php

/*
 * This file is part of Monsieur Biz' Sales Reports plugin for Sylius.
 *
 * (c) Monsieur Biz <sylius@monsieurbiz.com>
 *
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MonsieurBiz\SyliusSalesReportsPlugin\Repository;

/** Combines SQL summaries without physical-row or distinct-order sets. */
final class ReportSummaryAssembler
{
    private const METRICS = [
        'without_tax_total' => 'without_tax',
        'without_tax_promo_total' => 'without_tax_promo',
        'without_tax_shipping_total' => 'without_tax_shipping',
        'tax_total' => 'tax',
        'total' => 'total',
        'item_row_total' => 'item_row',
        'item_qty_total' => 'item_qty',
    ];

    public function assemble(array $orderBase, array $itemBase, array $unitAdjustments, array $itemAdjustments, array $orderAdjustments, array $productCounts, array $labels): array
    {
        $total = $this->emptyTotals();
        $products = $variants = [];
        foreach ([$itemBase, $unitAdjustments, $itemAdjustments] as $rows) {
            foreach ($rows as $row) {
                $this->addAmounts($total, $row);
                $this->addGroup($products, $row['product_id'], 'product', $row);
                $this->addGroup($variants, $row['variant_id'], 'variant', $row);
            }
        }
        $this->addAmounts($total, $orderBase);
        $this->addAmounts($total, $orderAdjustments);
        $this->applyCounts($products, $variants, $itemBase, $productCounts);
        $this->applyLabels($products, $variants, $labels);
        $average = $this->averageTotals($total, (int) ($orderBase['order_count'] ?? 0));
        $total['number_of_elements'] = 0;

        return [
            'total_sales_result' => $total,
            'average_sales_result' => $average,
            'product_sales_result' => $products,
            'product_variant_sales_result' => $variants,
        ];
    }

    private function applyCounts(array &$products, array &$variants, array $itemBase, array $productCounts): void
    {
        foreach ($itemBase as $row) {
            $variants[$row['variant_id']]['number_of_elements'] = (int) $row['order_count'];
            $variants[$row['variant_id']]['order_id'] = (int) $row['order_id'];
            $products[$row['product_id']]['order_id'] = max($products[$row['product_id']]['order_id'] ?? 0, (int) $row['order_id']);
        }
        foreach ($productCounts as $row) {
            $products[$row['product_id']]['number_of_elements'] = (int) $row['order_count'];
        }
    }

    private function applyLabels(array &$products, array &$variants, array $labels): void
    {
        $latestProductItems = [];
        foreach ($labels as $row) {
            $variants[$row['variant_id']]['variant_name'] = $row['variant_name'] ?? '';
            if ((int) $row['item_id'] > ($latestProductItems[$row['product_id']] ?? 0)) {
                $latestProductItems[$row['product_id']] = (int) $row['item_id'];
                $products[$row['product_id']]['product_name'] = $row['product_name'] ?? '';
            }
        }
    }

    private function averageTotals(array $total, int $count): array
    {
        $average = $total;
        if (0 !== $count) {
            foreach ($average as $metric => $amount) {
                $average[$metric] = round($amount / $count);
            }
        }
        $average['number_of_elements'] = $count;

        return $average;
    }

    private function emptyTotals(): array
    {
        return array_fill_keys(array_keys(self::METRICS), 0);
    }

    private function addAmounts(array &$totals, array $row): void
    {
        foreach (self::METRICS as $metric => $column) {
            $totals[$metric] += (int) ($row[$column] ?? 0);
        }
    }

    private function addGroup(array &$groups, int|string|null $id, string $prefix, array $row): void
    {
        if (!isset($groups[$id])) {
            $groups[$id] = $this->emptyTotals();
            $groups[$id][$prefix . '_id'] = $id;
            $groups[$id][$prefix . '_name'] = '';
        }
        $this->addAmounts($groups[$id], $row);
    }
}
