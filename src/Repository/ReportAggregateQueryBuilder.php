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

use Doctrine\ORM\QueryBuilder;
use Sylius\Component\Core\Model\AdjustmentInterface;

/**
 * Returns small product/variant summaries, not physical sales rows.
 * Base amounts and adjustments stay separate to avoid adjustment fan-out.
 */
final class ReportAggregateQueryBuilder
{
    public const PROMOTION_TYPES = [
        AdjustmentInterface::ORDER_PROMOTION_ADJUSTMENT,
        AbstractReportRepository::ADMIN_ORDER_DISCOUNT_ADJUSTMENT,
        AdjustmentInterface::ORDER_ITEM_PROMOTION_ADJUSTMENT,
        AbstractReportRepository::ADMIN_ORDER_ITEM_DISCOUNT_ADJUSTMENT,
        AdjustmentInterface::ORDER_SHIPPING_PROMOTION_ADJUSTMENT,
        AdjustmentInterface::ORDER_UNIT_PROMOTION_ADJUSTMENT,
    ];

    public function createOrderBaseQuery(QueryBuilder $query): QueryBuilder
    {
        return $query->select('SUM(o.total) AS total, COUNT(o.id) AS order_count');
    }

    public function createItemBaseQuery(QueryBuilder $query): QueryBuilder
    {
        // SIZE compiles to a correlated physical-unit count using the mapped entity and owner field.
        // No units still contributes one placeholder price; stored quantity is independent.
        return $this->groupItems($query->leftJoin('o.items', 'item'))
            ->addSelect('SUM(item.unitPrice * COALESCE(NULLIF(SIZE(item.units), 0), 1)) AS without_tax')
            ->addSelect('SUM(item.total) AS item_row, SUM(item.quantity) AS item_qty, COUNT(DISTINCT o.id) AS order_count, MAX(item.id) AS latest_item_id, MAX(o.id) AS order_id')
        ;
    }

    public function createUnitAdjustmentsQuery(QueryBuilder $query): QueryBuilder
    {
        $this->groupItems($query->innerJoin('o.items', 'item'))->innerJoin('item.units', 'unit');

        return $this->adjustments($query, 'unit', ['items', 'units'])
            ->addSelect('0 - SUM(CASE WHEN a.type = :tax_type AND a.shipment IS NULL AND a.neutral = true THEN a.amount ELSE 0 END) AS without_tax')
        ;
    }

    public function createItemAdjustmentsQuery(QueryBuilder $query): QueryBuilder
    {
        $this->groupItems($query->innerJoin('o.items', 'item'));

        return $this->adjustments($query, 'item', ['items']);
    }

    public function createOrderAdjustmentsQuery(QueryBuilder $query): QueryBuilder
    {
        $query->select('0 AS without_tax');

        return $this->adjustments($query, 'o', []);
    }

    public function createProductOrderCountsQuery(QueryBuilder $query): QueryBuilder
    {
        return $query
            ->select('IDENTITY(variant.product) AS product_id, COUNT(DISTINCT o.id) AS order_count')
            ->leftJoin('o.items', 'item')
            ->leftJoin('item.variant', 'variant')
            ->groupBy('variant.product')
        ;
    }

    public function createSnapshotLabelsQuery(QueryBuilder $query, array $itemIds): QueryBuilder
    {
        return $query
            ->select("item.id AS item_id, o.id AS order_id, IDENTITY(variant.product) AS product_id, IDENTITY(item.variant) AS variant_id, item.productName AS product_name, CONCAT(item.productName, ' ', item.variantName) AS variant_name")
            ->leftJoin('o.items', 'item')
            ->leftJoin('item.variant', 'variant')
            ->andWhere('item.id IN (:report_item_ids)')
            ->setParameter('report_item_ids', $itemIds)
        ;
    }

    private function groupItems(QueryBuilder $query): QueryBuilder
    {
        return $query
            ->select('IDENTITY(variant.product) AS product_id, IDENTITY(item.variant) AS variant_id')
            ->leftJoin('item.variant', 'variant')
            ->groupBy('variant.product, item.variant')
        ;
    }

    private function adjustments(QueryBuilder $query, string $owner, array $ownerPath): QueryBuilder
    {
        // Custom entities and inverse owner fields come from public ORM metadata APIs.
        $manager = $query->getEntityManager();
        $metadata = $manager->getClassMetadata($query->getRootEntities()[0]);
        foreach ($ownerPath as $association) {
            $metadata = $manager->getClassMetadata($metadata->getAssociationTargetClass($association));
        }
        $adjustmentClass = $metadata->getAssociationTargetClass('adjustments');
        $ownerField = $metadata->getAssociationMappedByTargetField('adjustments');
        $shippingExists = \sprintf('EXISTS (SELECT shipping_check.id FROM %s shipping_check WHERE shipping_check.%s = %s AND shipping_check.type = :shipping_type)', $adjustmentClass, $ownerField, $owner);

        $query
            ->innerJoin($owner . '.adjustments', 'a', 'WITH', 'a.type IN (:report_adjustment_types)')
            ->addSelect('SUM(CASE WHEN a.type = :tax_type AND a.shipment IS NULL THEN a.amount ELSE 0 END) AS tax')
            ->addSelect('SUM(CASE WHEN a.type IN (:promotion_types) THEN a.amount ELSE 0 END) AS without_tax_promo')
            ->addSelect('SUM(CASE WHEN a.type = :shipping_type THEN a.amount ELSE 0 END) - SUM(CASE WHEN a.type = :tax_type AND a.shipment IS NOT NULL AND a.neutral = true AND ' . $shippingExists . ' THEN a.amount ELSE 0 END) AS without_tax_shipping')
            ->setParameter('tax_type', AdjustmentInterface::TAX_ADJUSTMENT)
            ->setParameter('shipping_type', AdjustmentInterface::SHIPPING_ADJUSTMENT)
            ->setParameter('promotion_types', self::PROMOTION_TYPES)
            ->setParameter('report_adjustment_types', array_merge(self::PROMOTION_TYPES, [AdjustmentInterface::TAX_ADJUSTMENT, AdjustmentInterface::SHIPPING_ADJUSTMENT]))
        ;

        return $query;
    }
}
