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

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\ClassMetadata as PersistenceMetadata;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Sylius\Component\Core\Model as Core;
use Sylius\Component\Product\Model as Product;

/** Minimal explicit metadata on real model classes; never loads application mappings. */
final class Mapping implements MappingDriver
{
    public const TABLES = [
        Core\Channel::class => 'bench_channel',
        Core\Order::class => 'bench_order',
        Core\OrderItem::class => 'bench_item',
        Core\OrderItemUnit::class => 'bench_unit',
        Core\Adjustment::class => 'bench_adjustment',
        Core\Shipment::class => 'bench_shipment',
        Core\Product::class => 'bench_product',
        Core\ProductVariant::class => 'bench_variant',
        Product\ProductOption::class => 'bench_option',
        Product\ProductOptionValue::class => 'bench_option_value',
        Product\ProductOptionTranslation::class => 'bench_option_translation',
        Product\ProductOptionValueTranslation::class => 'bench_option_value_translation',
    ];

    public function getAllClassNames(): array
    {
        return array_keys(self::TABLES);
    }

    public function isTransient(string $className): bool
    {
        return !isset(self::TABLES[$className]);
    }

    public function loadMetadataForClass(string $className, PersistenceMetadata $metadata): void
    {
        if ($this->isTransient($className)) {
            throw \Doctrine\ORM\Mapping\MappingException::classIsNotAValidEntityOrMappedSuperClass($className);
        }
        /** @var ClassMetadata $metadata */
        $metadata->setPrimaryTable(['name' => self::TABLES[$className]]);
        $metadata->mapField(['fieldName' => 'id', 'type' => 'integer', 'id' => true]);
        $field = static function (string $name, string $type = 'string') use ($metadata): void {
            $metadata->mapField(['fieldName' => $name, 'type' => $type]);
        };
        $many = static function (string $name, string $target) use ($metadata): void {
            $metadata->mapManyToOne(['fieldName' => $name, 'targetEntity' => $target,
                'joinColumns' => [['name' => $name . '_id', 'referencedColumnName' => 'id', 'nullable' => true]]]);
        };
        $collection = static function (string $name, string $target, string $inverse) use ($metadata): void {
            $metadata->mapOneToMany(['fieldName' => $name, 'targetEntity' => $target, 'mappedBy' => $inverse]);
        };
        switch ($className) {
            case Core\Order::class:
                $field('state');
                $field('paymentState');
                $field('checkoutCompletedAt', 'datetime');
                $field('total', 'integer');
                $many('channel', Core\Channel::class);
                $collection('items', Core\OrderItem::class, 'order');
                $collection('adjustments', Core\Adjustment::class, 'order');

                break;
            case Core\OrderItem::class:
                $field('productName');
                $field('variantName');
                foreach (['unitPrice', 'total', 'quantity'] as $name) {
                    $field($name, 'integer');
                }
                $many('order', Core\Order::class);
                $many('variant', Core\ProductVariant::class);
                $collection('units', Core\OrderItemUnit::class, 'orderItem');
                $collection('adjustments', Core\Adjustment::class, 'orderItem');

                break;
            case Core\OrderItemUnit::class:
                $many('orderItem', Core\OrderItem::class);
                $collection('adjustments', Core\Adjustment::class, 'orderItemUnit');

                break;
            case Core\Adjustment::class:
                $field('type');
                $field('amount', 'integer');
                $field('neutral', 'boolean');
                $many('order', Core\Order::class);
                $many('orderItem', Core\OrderItem::class);
                $many('orderItemUnit', Core\OrderItemUnit::class);
                $many('shipment', Core\Shipment::class);

                break;
            case Core\ProductVariant::class:
                $many('product', Core\Product::class);
                $metadata->mapManyToMany([
                    'fieldName' => 'optionValues',
                    'targetEntity' => Product\ProductOptionValue::class,
                    'joinTable' => [
                        'name' => 'bench_variant_option',
                        'joinColumns' => [['name' => 'variant_id', 'referencedColumnName' => 'id']],
                        'inverseJoinColumns' => [['name' => 'value_id', 'referencedColumnName' => 'id']],
                    ],
                ]);

                break;
            case Product\ProductOption::class:
                $field('code');
                $collection('translations', Product\ProductOptionTranslation::class, 'translatable');

                break;
            case Product\ProductOptionValue::class:
                $field('code');
                $many('option', Product\ProductOption::class);
                $collection('translations', Product\ProductOptionValueTranslation::class, 'translatable');

                break;
            case Product\ProductOptionTranslation::class:
                $field('locale');
                $field('name');
                $many('translatable', Product\ProductOption::class);

                break;
            case Product\ProductOptionValueTranslation::class:
                $field('locale');
                $field('value');
                $many('translatable', Product\ProductOptionValue::class);

                break;
        }
    }
}
