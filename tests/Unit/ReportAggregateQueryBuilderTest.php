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

namespace MonsieurBiz\SyliusSalesReportsPlugin\Tests\Unit;

use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use MonsieurBiz\SyliusSalesReportsPlugin\Repository\ReportAggregateQueryBuilder;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\OrderItemUnit;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductVariant;

final class ReportAggregateQueryBuilderTest extends TestCase
{
    public function testPhysicalUnitCountCompilesUsingCustomUnitEntityAndInverseOwnerMapping(): void
    {
        // Compile only: explicit platform and preloaded metadata require no database connection.
        $configuration = ORMSetup::createConfiguration(true);
        $configuration->setMetadataDriverImpl($this->createMock(MappingDriver::class));
        $manager = EntityManager::create(['driver' => 'pdo_mysql', 'platform' => new MySQL80Platform()], $configuration);
        $order = new ClassMetadata(Order::class);
        $order->mapOneToMany(['fieldName' => 'items', 'targetEntity' => OrderItem::class, 'mappedBy' => 'order']);
        $item = new ClassMetadata(OrderItem::class);
        $item->mapManyToOne(['fieldName' => 'order', 'targetEntity' => Order::class]);
        $item->mapManyToOne(['fieldName' => 'variant', 'targetEntity' => ProductVariant::class]);
        $item->mapOneToMany(['fieldName' => 'units', 'targetEntity' => 'Custom\\PhysicalUnit', 'mappedBy' => 'customItemOwner']);
        foreach (['unitPrice', 'total', 'quantity'] as $field) {
            $item->mapField(['fieldName' => $field, 'type' => 'integer']);
        }
        $unit = new ClassMetadata('Custom\\PhysicalUnit');
        $unit->mapManyToOne(['fieldName' => 'customItemOwner', 'targetEntity' => OrderItem::class, 'joinColumns' => [['name' => 'custom_item_owner_id', 'referencedColumnName' => 'id']]]);
        $variant = new ClassMetadata(ProductVariant::class);
        $variant->mapManyToOne(['fieldName' => 'product', 'targetEntity' => Product::class]);
        $product = new ClassMetadata(Product::class);
        foreach (['test_order' => $order, 'test_item' => $item, 'custom_physical_unit' => $unit, 'test_variant' => $variant, 'test_product' => $product] as $table => $metadata) {
            $metadata->setPrimaryTable(['name' => $table]);
            $metadata->mapField(['fieldName' => 'id', 'type' => 'integer', 'id' => true]);
            $manager->getMetadataFactory()->setMetadataFor($metadata->getName(), $metadata);
        }
        $query = (new ReportAggregateQueryBuilder())->createItemBaseQuery($manager->createQueryBuilder()->from(Order::class, 'o'));
        $sql = $query->getQuery()->getSQL();
        self::assertMatchesRegularExpression('/COALESCE\(NULLIF\(\(SELECT COUNT\(\*\) FROM custom_physical_unit (\w+) WHERE \1\.custom_item_owner_id = \w+\.id\), 0\), 1\)/', $sql);
        self::assertStringNotContainsString('JOIN custom_physical_unit', $sql);
        self::assertStringNotContainsString('unitPrice * quantity', $sql);
        self::assertFalse($manager->getConnection()->isConnected());
    }

    public function testBaseAmountsAreReadWithoutAdjustmentFanOut(): void
    {
        $builder = new ReportAggregateQueryBuilder();
        foreach ([
            'createOrderBaseQuery' => ['SUM(o.total) AS total', 'COUNT(o.id) AS order_count'],
            'createItemBaseQuery' => ['SUM(item.unitPrice * COALESCE(NULLIF(SIZE(item.units), 0), 1)) AS without_tax', 'SUM(item.total) AS item_row', 'SUM(item.quantity) AS item_qty', 'COUNT(DISTINCT o.id) AS order_count', 'MAX(item.id) AS latest_item_id', 'MAX(o.id) AS order_id'],
        ] as $method => $projections) {
            $base = $this->query();
            $query = $builder->{$method}($base);
            self::assertSame($base, $query);
            foreach ($projections as $projection) {
                self::assertStringContainsString($projection, $query->getDQL());
            }
            self::assertStringNotContainsString('.adjustments', $query->getDQL());
            self::assertStringNotContainsString('SUM(DISTINCT', $query->getDQL());
            $this->assertFilters($query);
        }
        // Physical units determine price; stored quantity remains only the reported quantity.
        $dql = $builder->createItemBaseQuery($this->query())->getDQL();
        self::assertStringNotContainsString('item.unitPrice * item.quantity', $dql);
        self::assertStringNotContainsString('JOIN item.units', $dql);
        self::assertStringContainsString('LEFT JOIN o.items item', $dql);
    }

    public function testAdjustmentsHaveOneJoinAndShippingTaxIsGuardedPerActualOwner(): void
    {
        $builder = new ReportAggregateQueryBuilder();
        foreach ([
            'createUnitAdjustmentsQuery' => ['unit', 'customUnitOwner'],
            'createItemAdjustmentsQuery' => ['item', 'customItemOwner'],
            'createOrderAdjustmentsQuery' => ['o', 'customOrderOwner'],
        ] as $method => [$owner, $ownerField]) {
            $query = $builder->{$method}($this->query());
            $dql = $query->getDQL();
            self::assertSame(1, substr_count($dql, '.adjustments a'));
            self::assertStringContainsString('INNER JOIN ' . $owner . '.adjustments a WITH a.type IN (:report_adjustment_types)', $dql);
            if ('o' !== $owner) {
                self::assertStringContainsString('INNER JOIN o.items item', $dql);
                self::assertStringContainsString('LEFT JOIN item.variant variant', $dql);
            }
            self::assertStringContainsString('FROM Custom\\Adjustment shipping_check WHERE shipping_check.' . $ownerField . ' = ' . $owner, $dql);
            self::assertStringContainsString('shipping_check.type = :shipping_type', $dql);
            self::assertStringContainsString('a.shipment IS NOT NULL AND a.neutral = true AND EXISTS', $dql);
            self::assertStringContainsString('a.type = :tax_type AND a.shipment IS NULL THEN a.amount', $dql);
            self::assertStringNotContainsString('SUM(DISTINCT', $dql);
            self::assertCount(8, $query->getParameter('report_adjustment_types')->getValue());
            self::assertSame(ReportAggregateQueryBuilder::PROMOTION_TYPES, $query->getParameter('promotion_types')->getValue());
            if ('unit' === $owner) {
                self::assertStringContainsString('INNER JOIN item.units unit', $dql);
                self::assertStringContainsString('0 - SUM(CASE WHEN a.type = :tax_type AND a.shipment IS NULL AND a.neutral = true THEN a.amount ELSE 0 END) AS without_tax', $dql);
            }
            $this->assertFilters($query);
            self::assertSame('o' === $owner ? [] : ['variant.product, item.variant'], array_map('strval', $query->getDQLPart('groupBy')));
        }
    }

    public function testGroupCountsAndSnapshotLabelsDoNotGroupPhysicalOwnersOrNames(): void
    {
        $builder = new ReportAggregateQueryBuilder();
        foreach (['createItemBaseQuery', 'createUnitAdjustmentsQuery', 'createItemAdjustmentsQuery'] as $method) {
            self::assertSame(['variant.product, item.variant'], array_map('strval', $builder->{$method}($this->query())->getDQLPart('groupBy')));
        }
        $counts = $builder->createProductOrderCountsQuery($this->query());
        self::assertSame(['variant.product'], array_map('strval', $counts->getDQLPart('groupBy')));
        self::assertStringContainsString('COUNT(DISTINCT o.id)', $counts->getDQL());
        self::assertStringNotContainsString('.units', $counts->getDQL());
        self::assertStringNotContainsString('.adjustments', $counts->getDQL());
        $labels = $builder->createSnapshotLabelsQuery($this->query(), [12, 30]);
        self::assertSame([], $labels->getDQLPart('groupBy'));
        self::assertStringContainsString('item.productName AS product_name', $labels->getDQL());
        self::assertStringContainsString('item.variantName', $labels->getDQL());
        self::assertStringNotContainsString('MAX(', $labels->getDQL());
        self::assertSame([12, 30], $labels->getParameter('report_item_ids')->getValue());
        $this->assertFilters($counts);
        $this->assertFilters($labels);
    }

    private function query(): QueryBuilder
    {
        $order = new ClassMetadata(Order::class);
        $order->mapOneToMany(['fieldName' => 'items', 'targetEntity' => OrderItem::class, 'mappedBy' => 'order']);
        $order->mapOneToMany(['fieldName' => 'adjustments', 'targetEntity' => 'Custom\\Adjustment', 'mappedBy' => 'customOrderOwner']);
        $item = new ClassMetadata(OrderItem::class);
        $item->mapOneToMany(['fieldName' => 'units', 'targetEntity' => OrderItemUnit::class, 'mappedBy' => 'orderItem']);
        $item->mapOneToMany(['fieldName' => 'adjustments', 'targetEntity' => 'Custom\\Adjustment', 'mappedBy' => 'customItemOwner']);
        $unit = new ClassMetadata(OrderItemUnit::class);
        $unit->mapOneToMany(['fieldName' => 'adjustments', 'targetEntity' => 'Custom\\Adjustment', 'mappedBy' => 'customUnitOwner']);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getClassMetadata')->willReturnMap([[Order::class, $order], [OrderItem::class, $item], [OrderItemUnit::class, $unit]]);

        return (new QueryBuilder($manager))->from(Order::class, 'o')
            ->andWhere('o.channel = :channel')
            ->andWhere('o.state IN (:states)')
            ->andWhere('o.paymentState IN (:payment_states)')
            ->andWhere('o.checkoutCompletedAt BETWEEN :from AND :to')
            ->setParameter('channel', 42)
        ;
    }

    private function assertFilters(QueryBuilder $query): void
    {
        foreach (['o.channel = :channel', 'o.state IN (:states)', 'o.paymentState IN (:payment_states)', 'o.checkoutCompletedAt BETWEEN :from AND :to'] as $filter) {
            self::assertStringContainsString($filter, $query->getDQL());
        }
        self::assertSame(42, $query->getParameter('channel')->getValue());
    }
}
