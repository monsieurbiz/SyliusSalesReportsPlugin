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

namespace SalesReportsBenchmark;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Throwable;

final class Fixtures
{
    public static function load(Connection $db, int $orders, bool $fanout = false, bool $multiitem = false, bool $unitless = false): void
    {
        if (!\in_array($orders, [10, 30000, 50000], true)) {
            throw new InvalidArgumentException('Allowed orders: 10 (control), 30000 (default), 50000 (maximum).');
        }
        $controls = (int) $fanout + (int) $multiitem + (int) $unitless;
        if ($controls > 1 || ($controls > 0 && 10 !== $orders)) {
            throw new InvalidArgumentException('Regression scenarios are mutually exclusive and require ten orders.');
        }
        foreach (['bench_channel' => [[1], [2]], 'bench_shipment' => [[1]]] as $table => $rows) {
            foreach ($rows as $row) {
                $db->insert($table, ['id' => $row[0]]);
            }
        }
        $db->insert('bench_product', ['id' => 1]);
        $db->insert('bench_option', ['id' => 1, 'code' => 'size']);
        $db->insert('bench_option_translation', ['id' => 1, 'translatable_id' => 1, 'locale' => 'en_US', 'name' => 'Size']);
        foreach ([1 => 'Popular', 2 => 'Rare'] as $id => $name) {
            $db->insert('bench_variant', ['id' => $id, 'product_id' => 1]);
            $db->insert('bench_option_value', ['id' => $id, 'code' => self::variantCode($id), 'option_id' => 1]);
            $db->insert('bench_option_value_translation', ['id' => $id, 'translatable_id' => $id, 'locale' => 'en_US', 'value' => $name]);
            $db->insert('bench_variant_option', ['variant_id' => $id, 'value_id' => $id]);
        }
        // Fixed arithmetic schedule (seed 2025), no randomness and no downscaling.
        $unit = 0;
        $adjustment = 0;
        $buffers = [];
        $append = static function (string $table, array $row) use (&$buffers): void {
            $buffers[$table][] = $row;
        };
        $flush = static function () use ($db, &$buffers): void {
            $db->beginTransaction();

            try {
                foreach (['bench_order', 'bench_item', 'bench_unit', 'bench_adjustment'] as $table) {
                    $rows = $buffers[$table] ?? [];
                    if ([] === $rows) {
                        continue;
                    }
                    $columns = array_keys($rows[0]);
                    foreach (array_chunk($rows, 500) as $chunk) {
                        $params = [];
                        foreach ($chunk as $row) {
                            array_push($params, ...array_values($row));
                        }
                        $db->executeStatement('INSERT INTO `' . $table . '` (`' . implode('`,`', $columns) . '`) VALUES ' .
                            implode(',', array_fill(0, \count($chunk), '(' . implode(',', array_fill(0, \count($columns), '?')) . ')')), $params);
                    }
                }
                $db->commit();
                $buffers = [];
            } catch (Throwable $error) {
                $db->rollBack();

                throw $error;
            }
        };
        for ($id = 1; $id <= $orders + 4; ++$id) {
            $sentinel = max(0, $id - $orders);
            $variant = 0 === $id % 10 ? 2 : 1;
            $quantity = 1 === $variant ? 3 : 1;
            $extraPromotion = $fanout && 1 === $id ? 32 : 0;
            $extraItemTotal = $multiitem && 1 === $id ? 1155 : 0;
            $date = (new DateTimeImmutable('2025-01-01'))->modify('+' . (($id - 1) % 365) . ' days')->format('Y-m-d');
            $append('bench_order', ['id' => $id, 'channel_id' => 1 === $sentinel ? 2 : 1,
                'state' => 3 === $sentinel ? 'cancelled' : ($id % 2 ? 'new' : 'fulfilled'),
                'paymentState' => 4 === $sentinel ? 'refunded' : 'paid',
                'checkoutCompletedAt' => 2 === $sentinel ? '2024-12-31 23:59:59' : $date . ($id % 2 ? ' 00:00:00' : ' 23:59:59'),
                'total' => $quantity * 1185 - 30 + 480 - $extraPromotion + $extraItemTotal]);
            $append('bench_item', ['id' => $id, 'order_id' => $id, 'variant_id' => $variant, 'productName' => 'Synthetic product',
                'variantName' => 1 === $variant ? 'Popular' : 'Rare', 'unitPrice' => 1200, 'quantity' => $quantity, 'total' => $quantity * 1185 - 30 - $extraPromotion]);
            $add = static function (string $type, int $amount, int $neutral, ?int $order, ?int $item, ?int $unitId, ?int $shipment = null) use (&$adjustment, $append): void {
                $append('bench_adjustment', ['id' => ++$adjustment, 'type' => $type, 'amount' => $amount, 'neutral' => $neutral,
                    'order_id' => $order, 'orderItem_id' => $item, 'orderItemUnit_id' => $unitId, 'shipment_id' => $shipment]);
            };
            $add('shipping', 500, 0, $id, null, null);
            $add('tax', 20, 1, $id, null, null, 1);
            $add('order_promotion', -20, 0, $id, null, null);
            $add('order_item_promotion', -30, 0, null, $id, null);
            for ($i = 0; $i < $quantity; ++$i) {
                $append('bench_unit', ['id' => ++$unit, 'orderItem_id' => $id]);
                $add('tax', 200, 1, null, null, $unit);
                $add('order_unit_promotion', -10, 0, null, null, $unit);
                $add('order_item_promotion', -5, 0, null, null, $unit);
                if ($fanout && 1 === $unit) {
                    $add('order_item_promotion', -20, 0, null, null, $unit);
                    $add('order_unit_promotion', -7, 0, null, null, $unit);
                }
                if ($fanout && 2 === $unit) {
                    $add('order_item_promotion', -5, 0, null, null, $unit);
                }
            }
            if (0 === $id % 500) {
                $flush();
            }
        }
        if ($multiitem) {
            // Allocate after every standard and excluded row to avoid identifier collisions.
            $itemId = $orders + 5;
            $append('bench_item', ['id' => $itemId, 'order_id' => 1, 'variant_id' => 2,
                'productName' => 'Synthetic product', 'variantName' => 'Rare',
                'unitPrice' => 1200, 'quantity' => 1, 'total' => 1155]);
            $append('bench_unit', ['id' => ++$unit, 'orderItem_id' => $itemId]);
            $add('tax', 200, 1, null, null, $unit);
            $add('order_unit_promotion', -10, 0, null, null, $unit);
            $add('order_item_promotion', -5, 0, null, null, $unit);
            $add('order_item_promotion', -30, 0, null, $itemId, null);
        }
        if ([] !== $buffers) {
            $flush();
        }
        if ($unitless) {
            // Deliberately retain stale item quantity and totals for LEFT JOIN compatibility.
            $db->executeStatement('DELETE FROM bench_adjustment WHERE orderItemUnit_id IN (SELECT id FROM bench_unit WHERE orderItem_id = 1)');
            $db->executeStatement('DELETE FROM bench_unit WHERE orderItem_id = 1');
        }
    }

    public static function variantCode(int $variant): string
    {
        return 1 === $variant ? 'popular' : 'rare';
    }

    /** Closed-form accepted-order counts; excluded rows never enter this model. */
    public static function expectedVariants(int $orders, bool $multiitem = false): array
    {
        $rare = intdiv($orders, 10);
        $popular = $orders - $rare;
        $rare += $multiitem ? 1 : 0;

        return [1 => ['orders' => $popular, 'items' => $popular, 'units' => $popular * 3, 'representative' => $orders - 1],
            2 => ['orders' => $rare, 'items' => $rare, 'units' => $rare, 'representative' => $orders]];
    }

    public static function expectedProducts(int $orders, bool $multiitem = false): array
    {
        return [1 => ['orders' => $orders, 'items' => $orders + ($multiitem ? 1 : 0),
            'units' => intdiv($orders * 28, 10) + ($multiitem ? 1 : 0)]];
    }

    public static function expected(int $orders, bool $fanout = false, bool $multiitem = false, bool $unitless = false): array
    {
        $extraItems = $multiitem ? 1 : 0;
        $units = intdiv($orders * 28, 10) + $extraItems;
        $items = $orders + $extraItems;
        $extraPromotion = $fanout ? 32 : 0;

        return ['without_tax_total' => $units * 1000 - ($unitless ? 1800 : 0),
            'without_tax_promo_total' => -15 * $units - 30 * $items - 20 * $orders - $extraPromotion + ($unitless ? 45 : 0),
            'without_tax_shipping_total' => 480 * $orders, 'tax_total' => 200 * $units - ($unitless ? 600 : 0),
            'total' => $units * 1185 - 30 * $items + 480 * $orders - $extraPromotion,
            'item_row_total' => $units * 1185 - 30 * $items - $extraPromotion,
            'item_qty_total' => $units, 'number_of_elements' => 0];
    }
}
