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

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Doctrine\ORM\Query\FilterCollection;
use Doctrine\ORM\QueryBuilder;
use LogicException;
use MonsieurBiz\SyliusSalesReportsPlugin\Exception\MissingLocaleException;
use MonsieurBiz\SyliusSalesReportsPlugin\Repository\AbstractReportRepository;
use MonsieurBiz\SyliusSalesReportsPlugin\Repository\ReportRepository;
use MonsieurBiz\SyliusSalesReportsPlugin\Repository\ReportSummaryAssembler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\OrderItemUnit;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Core\Repository\ProductVariantRepositoryInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Throwable;

final class ReportRepositoryTest extends TestCase
{
    /**
     * @dataProvider snapshotPlatforms
     */
    public function testSnapshotIncludesAllEightReadsAndOptions(AbstractPlatform $platform, array $start): void
    {
        $events = [];
        $connection = $this->snapshotConnection($platform, $events);
        $repository = $this->nativeRepository($this->snapshotSummaries(), [], [], $connection, static function () use (&$events): void {
            $events[] = 'read';
        });
        $repository->getReportsForChannelForDates($this->channel('fr_FR'), new DateTimeImmutable('2025-01-01'));
        self::assertSame(array_merge($start, array_fill(0, 8, 'read'), ['commit']), $events);
    }

    public static function snapshotPlatforms(): iterable
    {
        yield 'mysql' => [new MySQLPlatform(), ['set', 'begin']];
        yield 'mariadb' => [new MariaDBPlatform(), ['set', 'begin']];
        yield 'postgresql' => [new PostgreSQLPlatform(), ['begin', 'set']];
    }

    public function testEmptySnapshotCommitsAfterLocaleCheck(): void
    {
        $events = [];
        $connection = $this->snapshotConnection(new MySQLPlatform(), $events);
        $repository = $this->nativeRepository([[['total' => null, 'order_count' => '0']]], [], [], $connection, static function () use (&$events): void {
            $events[] = 'read';
        });
        $repository->getReportsForChannelForDates($this->channel('fr_FR'), new DateTimeImmutable('2025-01-01'));
        self::assertSame(['set', 'begin', 'read', 'commit'], $events);
    }

    /**
     * @dataProvider snapshotFailures
     */
    public function testSnapshotFailuresPreserveOriginalErrorAndClose(string $failure, bool $rollbackFails, AbstractPlatform $platform): void
    {
        $events = [];
        $error = new RuntimeException('Original failure');
        $connection = $this->snapshotConnection($platform, $events, $failure, $error, $rollbackFails);
        $summaries = $this->snapshotSummaries();
        $channel = $this->channel('fr_FR');
        if ('aggregate' === $failure || 'close' === $failure) {
            $summaries = [$error];
        } elseif ('options' === $failure) {
            $summaries[7] = $error;
        } elseif ('locale' === $failure) {
            $summaries = [[['total' => null, 'order_count' => '0']]];
            $channel = $this->createMock(ChannelInterface::class);
        } else {
            $summaries = [];
            if ('commit' === $failure || 'commit_inactive' === $failure) {
                $summaries = [[['total' => null, 'order_count' => '0']]];
            }
        }
        $repository = $this->nativeRepository($summaries, [], [], $connection);

        try {
            $repository->getReportsForChannelForDates($channel, new DateTimeImmutable('2025-01-01'));
            self::fail('A failed snapshot must not return reports.');
        } catch (Throwable $actual) {
            if ('locale' === $failure) {
                self::assertInstanceOf(MissingLocaleException::class, $actual);
            } else {
                self::assertSame($error, $actual);
            }
        }
        self::assertSame('close', end($events));
        $rollbackExpected = 'commit_inactive' !== $failure && ('set' !== $failure || $platform instanceof PostgreSQLPlatform);
        self::assertSame($rollbackExpected, \in_array('rollback', $events, true));
        self::assertSame(\in_array($failure, ['commit', 'commit_inactive'], true), \in_array('commit', $events, true));
    }

    public static function snapshotFailures(): iterable
    {
        foreach (['set', 'begin', 'aggregate', 'options', 'locale', 'commit', 'commit_inactive', 'close'] as $failure) {
            yield $failure => [$failure, false, new MySQLPlatform()];
        }
        yield 'rollback failure' => ['aggregate', true, new MySQLPlatform()];
        yield 'postgresql set failure' => ['set', false, new PostgreSQLPlatform()];
        yield 'postgresql begin failure' => ['begin', false, new PostgreSQLPlatform()];
    }

    /**
     * @dataProvider snapshotRejections
     */
    public function testSnapshotRejectsCallerStateWithoutMutatingConnections(int $depth, bool $autoCommit, AbstractPlatform $platform, bool $differentConnection): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn($depth > 0);
        $connection->method('isAutoCommit')->willReturn($autoCommit);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->expects(self::never())->method('getTransactionIsolation');
        $connection->expects(self::never())->method('setTransactionIsolation');
        foreach (['executeStatement', 'beginTransaction', 'commit', 'rollBack', 'close'] as $method) {
            $connection->expects(self::never())->method($method);
        }
        $variantConnection = null;
        if ($differentConnection) {
            $variantConnection = $this->createMock(Connection::class);
            foreach (['executeStatement', 'beginTransaction', 'commit', 'rollBack', 'close'] as $method) {
                $variantConnection->expects(self::never())->method($method);
            }
        }
        $repository = $this->nativeRepository([], [], [], $connection, null, $variantConnection);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Native report');
        $repository->getReportsForChannelForDates($this->channel('fr_FR'), new DateTimeImmutable('2025-01-01'));
    }

    public static function snapshotRejections(): iterable
    {
        yield 'caller transaction' => [1, true, new MySQLPlatform(), false];
        yield 'nested caller transaction' => [2, true, new MySQLPlatform(), false];
        yield 'lazy non-autocommit' => [0, false, new MySQLPlatform(), false];
        yield 'unsupported platform' => [0, true, new SqlitePlatform(), false];
        yield 'different variant connection' => [0, true, new MySQLPlatform(), true];
    }

    private function snapshotConnection(AbstractPlatform $platform, array &$events, string $failure = '', ?RuntimeException $error = null, bool $rollbackFails = false): Connection
    {
        $active = false;
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('isAutoCommit')->willReturn(true);
        $connection->method('isTransactionActive')->willReturnCallback(static function () use (&$active) { return $active; });
        $connection->expects(self::never())->method('getTransactionIsolation');
        $connection->expects(self::never())->method('setTransactionIsolation');
        $connection->method('executeStatement')->with('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')->willReturnCallback(static function () use (&$events, $failure, $error) {
            $events[] = 'set';
            if ('set' === $failure) {
                throw $error;
            }

            return 0;
        });
        $connection->method('beginTransaction')->willReturnCallback(static function () use (&$events, &$active, $failure, $error) {
            $events[] = 'begin';
            $active = true;
            if ('begin' === $failure) {
                throw $error;
            }

            return true;
        });
        $connection->method('commit')->willReturnCallback(static function () use (&$events, &$active, $failure, $error) {
            $events[] = 'commit';
            if ('commit_inactive' === $failure) {
                $active = false;

                throw $error;
            }
            if ('commit' === $failure) {
                throw $error;
            }
            $active = false;

            return true;
        });
        $connection->method('rollBack')->willReturnCallback(static function () use (&$events, &$active, $rollbackFails) {
            $events[] = 'rollback';
            if ($rollbackFails) {
                throw new RuntimeException('Rollback failed');
            }
            $active = false;

            return true;
        });
        $connection->method('close')->willReturnCallback(static function () use (&$events, $failure): void {
            $events[] = 'close';
            if ('close' === $failure) {
                throw new RuntimeException('Close failed');
            }
        });

        return $connection;
    }

    private function snapshotSummaries(): array
    {
        $group = ['product_id' => 1, 'variant_id' => 11];

        return [
            [['total' => '100', 'order_count' => '1']],
            [$group + ['without_tax' => '100', 'item_row' => '100', 'item_qty' => '1', 'order_count' => '1', 'order_id' => '1', 'latest_item_id' => '1']],
            [],
            [],
            [[]],
            [['product_id' => 1, 'order_count' => '1']],
            [$group + ['item_id' => 1, 'order_id' => 1, 'product_name' => 'Product', 'variant_name' => 'Variant']],
            [['variant_id' => 11, 'option_code' => 'color', 'option_label' => 'Color', 'option_value_code' => 'red', 'option_value_label' => 'Red']],
        ];
    }

    public function testNativePipelineReadsEightSmallSummariesAndKeepsOptionRepresentativeCounts(): void
    {
        $group = ['product_id' => 1, 'variant_id' => 11];
        $other = ['product_id' => 1, 'variant_id' => 12];
        $repository = $this->nativeRepository([
            [['total' => '1000', 'order_count' => '10']],
            [
                $group + ['without_tax' => '900', 'item_row' => '900', 'item_qty' => '9', 'order_count' => '9', 'order_id' => '9', 'latest_item_id' => '9'],
                $other + ['without_tax' => '200', 'item_row' => '200', 'item_qty' => '2', 'order_count' => '2', 'order_id' => '10', 'latest_item_id' => '11'],
            ],
            [$group + ['without_tax' => '-100', 'tax' => '100']],
            [$group + ['without_tax_promo' => '-100']],
            [['without_tax_shipping' => '50']],
            [['product_id' => 1, 'order_count' => '10']],
            [
                $group + ['item_id' => 9, 'order_id' => 9, 'product_name' => 'Old snapshot', 'variant_name' => 'Old variant'],
                $other + ['item_id' => 11, 'order_id' => 1, 'product_name' => 'Latest snapshot', 'variant_name' => 'Rare variant'],
            ],
            [
                ['variant_id' => 11, 'option_code' => 'color', 'option_label' => 'Color', 'option_value_code' => 'red', 'option_value_label' => 'Red'],
                ['variant_id' => 12, 'option_code' => 'color', 'option_label' => 'Color', 'option_value_code' => 'blue', 'option_value_label' => 'Blue'],
            ],
        ]);
        $reports = $repository->getReportsForChannelForDates($this->channel('fr_FR'), new DateTimeImmutable('2025-01-01'));
        self::assertSame(1000, $reports['total_sales_result']['without_tax_total']);
        self::assertSame(1000, $reports['total_sales_result']['total']);
        self::assertSame(10, $reports['product_sales_result'][1]['number_of_elements']);
        self::assertSame('Latest snapshot', $reports['product_sales_result'][1]['product_name']);
        self::assertSame(10, $reports['product_variant_sales_result'][12]['order_id']);
        self::assertSame(2, $reports['product_option_sales_result']['color']['number_of_elements']);
        self::assertSame(1, $reports['product_option_value_sales_result']['red']['number_of_elements']);
        self::assertSame(1, $reports['product_option_value_sales_result']['blue']['number_of_elements']);
        // Inner adjustment reads omit this variant; its base group and zero adjustments remain.
        self::assertSame(200, $reports['product_variant_sales_result'][12]['without_tax_total']);
        self::assertSame(0, $reports['product_variant_sales_result'][12]['tax_total']);
        self::assertSame(0, $reports['product_variant_sales_result'][12]['without_tax_shipping_total']);
        self::assertSame(0, $reports['product_variant_sales_result'][12]['without_tax_promo_total']);
    }

    public function testEmptyNativeReportSkipsRemainingReadsAndStillRequiresLocale(): void
    {
        $repository = $this->nativeRepository([[['total' => null, 'order_count' => '0']]]);
        $reports = $repository->getReportsForChannelForDates($this->channel('fr_FR'), new DateTimeImmutable('2025-01-01'));
        self::assertSame(0, $reports['total_sales_result']['total']);
        self::assertSame(0, $reports['average_sales_result']['number_of_elements']);
        foreach (['product_sales_result', 'product_variant_sales_result', 'product_option_sales_result', 'product_option_value_sales_result'] as $key) {
            self::assertSame([], $reports[$key]);
        }
        $repository = $this->nativeRepository([[['total' => null, 'order_count' => '0']]]);
        $this->expectException(MissingLocaleException::class);
        $repository->getReportsForChannelForDates($this->createMock(ChannelInterface::class), new DateTimeImmutable('2025-01-01'));
    }

    public function testBaseGroupsWithoutAdjustmentsKeepPlaceholderPricesAndIndependentQuantity(): void
    {
        $reports = (new ReportSummaryAssembler())->assemble(
            ['total' => '500', 'order_count' => '2'],
            [
                ['product_id' => 1, 'variant_id' => 11, 'without_tax' => '700', 'item_qty' => '99', 'item_row' => '500', 'order_count' => '1', 'order_id' => '1'],
                ['product_id' => null, 'variant_id' => null, 'without_tax' => null, 'item_qty' => null, 'item_row' => null, 'order_count' => '1', 'order_id' => '2'],
            ],
            [],
            [],
            ['tax' => null, 'without_tax_promo' => null, 'without_tax_shipping' => null],
            [['product_id' => 1, 'order_count' => '1'], ['product_id' => null, 'order_count' => '1']],
            []
        );
        self::assertSame(700, $reports['total_sales_result']['without_tax_total']);
        self::assertSame(99, $reports['total_sales_result']['item_qty_total']);
        self::assertSame(700, $reports['product_variant_sales_result'][11]['without_tax_total']);
        self::assertSame(0, $reports['product_variant_sales_result'][11]['tax_total']);
        self::assertSame(0, $reports['product_variant_sales_result']['']['without_tax_total']);
        self::assertSame(1, $reports['product_variant_sales_result']['']['number_of_elements']);
        self::assertSame(1, $reports['product_sales_result']['']['number_of_elements']);
        self::assertSame(2, $reports['average_sales_result']['number_of_elements']);
    }

    public function testProtectedAverageAdjustmentIsIncludedInBothTotalAndAverageReports(): void
    {
        $repository = $this->repository(AverageAdjustedReportRepository::class);
        $reports = $repository->getReportsForChannelForDates($this->channel('fr_FR'), new DateTimeImmutable('2025-01-01'));

        // Legacy total generation calls averageResult() too, even without averaging by order.
        self::assertSame(4350, $reports['total_sales_result']['total']);
        self::assertSame(2225.0, $reports['average_sales_result']['total']);
        self::assertSame(2, $reports['average_sales_result']['number_of_elements']);
    }

    public function testProtectedOptionTransformationRunsIndependentlyForEachOptionReport(): void
    {
        $repository = $this->repository(OptionAdjustedReportRepository::class);
        $reports = $repository->getReportsForChannelForDates($this->channel('fr_FR'), new DateTimeImmutable('2025-01-01'));

        self::assertSame(2, $repository->optionTransformations);
        self::assertSame(3400, $reports['product_option_sales_result']['color']['without_tax_total']);
        self::assertSame(3000, $reports['product_option_value_sales_result']['red']['without_tax_total']);
    }

    /**
     * @dataProvider transformationHooks
     */
    public function testProtectedTransformationsUseAllSixLegacyReports(string $hook): void
    {
        $repository = $this->getMockBuilder(InMemoryReportRepository::class)
            ->setConstructorArgs([$this->createMock(OrderRepositoryInterface::class), $this->createMock(ProductVariantRepositoryInterface::class), null, null])
            ->onlyMethods([$hook])
            ->getMock()
        ;
        $baseMethod = new ReflectionMethod(AbstractReportRepository::class, $hook);
        $repository->expects(self::atLeastOnce())->method($hook)->willReturnCallback(static function (...$arguments) use ($baseMethod, $repository) {
            return $baseMethod->invokeArgs($repository, $arguments);
        });
        $fixture = $this->repository();
        $repository->units = $fixture->units;
        $repository->items = $fixture->items;
        $repository->orders = $fixture->orders;
        $channel = $this->channel('fr_FR');
        $date = new DateTimeImmutable('2025-01-01');

        $reports = $repository->getReportsForChannelForDates($channel, $date);

        self::assertSame([
            'units', 'items', 'orders',
            'units', 'items', 'orders',
            'units', 'items',
            'units', 'items',
            'units', 'items', 'options',
            'units', 'items', 'options',
        ], array_column($repository->reads, 0));
        self::assertSame($fixture->getReportsForChannelForDates($channel, $date), $reports);
    }

    public static function transformationHooks(): iterable
    {
        foreach (['initResult', 'addResults', 'averageResult', 'addResultsByElement', 'populateOptions'] as $hook) {
            yield $hook => [$hook];
        }
    }

    public function testSqlSummariesPreserveFinancialTotalsAndDistinctProductOrders(): void
    {
        $repository = $this->repository();
        $assembler = new ReportSummaryAssembler();
        $reports = $assembler->assemble(
            ['total' => '4250', 'order_count' => '2'],
            [
                ['product_id' => 1, 'variant_id' => 11, 'without_tax' => '3000', 'item_row' => '2800', 'item_qty' => '3', 'order_count' => '2', 'order_id' => '2'],
                ['product_id' => 1, 'variant_id' => 12, 'without_tax' => '600', 'item_row' => '600', 'item_qty' => '1', 'order_count' => '1', 'order_id' => '1'],
                ['product_id' => 2, 'variant_id' => 21, 'without_tax' => '480', 'item_row' => '480', 'item_qty' => '1', 'order_count' => '1', 'order_id' => '2'],
            ],
            [
                ['product_id' => 1, 'variant_id' => 11, 'without_tax' => '-600', 'tax' => '600', 'without_tax_promo' => '-200'],
                ['product_id' => 1, 'variant_id' => 12, 'without_tax' => '-100', 'tax' => '100'],
                ['product_id' => 2, 'variant_id' => 21, 'without_tax' => '-80', 'tax' => '80'],
            ],
            [],
            ['without_tax_shipping' => '350', 'tax' => '70', 'without_tax_promo' => '-50'],
            [['product_id' => 1, 'order_count' => '2'], ['product_id' => 2, 'order_count' => '1']],
            [
                ['item_id' => 3, 'product_id' => 1, 'variant_id' => 11, 'product_name' => 'Product 1', 'variant_name' => 'Variant 11'],
                ['item_id' => 2, 'product_id' => 1, 'variant_id' => 12, 'product_name' => 'Product 1', 'variant_name' => 'Variant 12'],
                ['item_id' => 4, 'product_id' => 2, 'variant_id' => 21, 'product_name' => 'Product 2', 'variant_name' => 'Variant 21'],
            ]
        );
        $expected = $repository->getReportsForChannelForDates($this->channel('fr_FR'), new DateTimeImmutable('2025-01-01'));
        foreach ($reports as $key => $report) {
            self::assertEquals($expected[$key], $report);
        }
        // Identical order-1 units both contribute; two variants on that order count once per product.
        self::assertSame(3300, $reports['total_sales_result']['without_tax_total']);
        self::assertSame(5, $reports['total_sales_result']['item_qty_total']);
        self::assertSame(2, $reports['product_sales_result'][1]['number_of_elements']);
        self::assertSame(2, $reports['product_variant_sales_result'][11]['number_of_elements']);
        self::assertSame(1, $reports['product_variant_sales_result'][12]['number_of_elements']);
        self::assertSame(0, $assembler->assemble([], [], [], [], [], [], [])['total_sales_result']['total']);
    }

    public function testSnapshotLabelsUseLatestItemButKeepMaximumRepresentativeOrder(): void
    {
        $reports = (new ReportSummaryAssembler())->assemble(
            ['order_count' => '2'],
            [
                ['product_id' => 1, 'variant_id' => 11, 'order_count' => '2', 'order_id' => '10'],
                ['product_id' => 1, 'variant_id' => 12, 'order_count' => '1', 'order_id' => '9'],
            ],
            [],
            [],
            [],
            [['product_id' => 1, 'order_count' => '2']],
            [
                ['item_id' => 20, 'order_id' => 1, 'product_id' => 1, 'variant_id' => 11, 'product_name' => '', 'variant_name' => null],
                ['item_id' => 10, 'order_id' => 9, 'product_id' => 1, 'variant_id' => 12, 'product_name' => 'Alphabetically last', 'variant_name' => 'Old snapshot'],
            ]
        );
        // Query result order and alphabetical names must not choose the product snapshot.
        self::assertSame('', $reports['product_sales_result'][1]['product_name']);
        self::assertSame('', $reports['product_variant_sales_result'][11]['variant_name']);
        self::assertSame(10, $reports['product_sales_result'][1]['order_id']);
        self::assertSame(10, $reports['product_variant_sales_result'][11]['order_id']);
        self::assertSame(9, $reports['product_variant_sales_result'][12]['order_id']);
    }

    public function testEveryProtectedHookDisablesAggregationButUnmodifiedRepositoryAllowsIt(): void
    {
        $guard = new ReflectionMethod(ReportRepository::class, 'canAggregateReports');
        $repository = $this->nativeRepository([]);
        self::assertTrue($guard->invoke($repository));
        foreach ((new ReflectionClass(AbstractReportRepository::class))->getMethods(ReflectionMethod::IS_PROTECTED) as $method) {
            $customized = $this->getMockBuilder(ReportRepository::class)
                ->disableOriginalConstructor()
                ->onlyMethods([$method->getName()])
                ->getMock()
            ;
            self::assertFalse($guard->invoke($customized), $method->getName());
        }
    }

    public function testEnabledSqlFilterDisablesAggregationAndUsesFourLegacyReads(): void
    {
        $filter = $this->createMock(SQLFilter::class);
        $guard = new ReflectionMethod(ReportRepository::class, 'canAggregateReports');
        self::assertFalse($guard->invoke($this->nativeRepository([], ['soft_delete_unit' => $filter])));

        $repository = $this->nativeRepository(
            [
                [$this->row(1, 1, 11, ['without_tax' => '800', 'tax' => '200'])],
                [$this->row(1, 1, 11, ['item_row' => '1000', 'item_qty' => '1'])],
                [$this->row(1, 0, 0, ['total' => '1000'])],
                [['variant_id' => 11, 'option_code' => 'color', 'option_label' => 'Color', 'option_value_code' => 'red', 'option_value_label' => 'Red']],
            ],
            ['soft_delete_unit' => $filter],
            ['LEFT JOIN item.units element', 'LEFT JOIN o.items element', 'o.total as total', 'LEFT JOIN v.optionValues option_value']
        );
        $reports = $repository->getReportsForChannelForDates($this->channel('fr_FR'), new DateTimeImmutable('2025-01-01'));
        self::assertSame(800, $reports['total_sales_result']['without_tax_total']);
        self::assertSame(1000, $reports['total_sales_result']['total']);
        self::assertSame(1, $reports['product_variant_sales_result'][11]['item_qty_total']);
        self::assertSame(800, $reports['product_option_sales_result']['color']['without_tax_total']);
    }

    /**
     * @dataProvider publicReportMethods
     */
    public function testEachPublicReportOverrideTriggersCompatibilityFallback(string $method, string $key): void
    {
        $repository = $this->getMockBuilder(ReportRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods([$method, 'getOrderItemUnitValues', 'getOrderItemValues', 'getOrderValues', 'getVariantsOptions'])
            ->getMock()
        ;
        foreach (['getOrderItemUnitValues', 'getOrderItemValues', 'getOrderValues', 'getVariantsOptions'] as $hook) {
            $repository->method($hook)->willReturn([]);
        }
        $channel = $this->channel('fr_FR');
        $from = new DateTimeImmutable('2025-01-01 12:34:56');
        $to = new DateTimeImmutable('2025-12-31 18:30:00');
        $repository->expects(self::atLeastOnce())->method($method)->with($channel, $from, $to)->willReturn(['custom' => $method]);

        $reports = $repository->getReportsForChannelForDates($channel, $from, $to);

        self::assertSame(['custom' => $method], $reports[$key]);
    }

    public static function publicReportMethods(): iterable
    {
        yield 'total' => ['getSalesForChannelForDates', 'total_sales_result'];
        yield 'average' => ['getAverageSalesForChannelForDates', 'average_sales_result'];
        yield 'product' => ['getProductSalesForChannelForDates', 'product_sales_result'];
        yield 'variant' => ['getProductVariantSalesForChannelForDates', 'product_variant_sales_result'];
        yield 'option' => ['getProductOptionSalesForChannelForDates', 'product_option_sales_result'];
        yield 'option value' => ['getProductOptionValueSalesForChannelForDates', 'product_option_value_sales_result'];
    }

    public function testCompatibilityFallbackCallsAllSixMethodsWithOriginalDateArguments(): void
    {
        $methods = iterator_to_array(self::publicReportMethods());
        $channel = $this->channel('fr_FR');
        $from = new DateTimeImmutable('2025-01-01 12:34:56');

        foreach ([null, new DateTimeImmutable('2025-12-31 18:30:00')] as $to) {
            $repository = $this->getMockBuilder(ReportRepository::class)
                ->disableOriginalConstructor()
                ->onlyMethods(array_column($methods, 0))
                ->getMock()
            ;
            $expected = [];
            foreach ($methods as [$method, $key]) {
                $expected[$key] = ['custom' => $method];
                $repository->expects(self::once())->method($method)
                    ->with(self::identicalTo($channel), self::identicalTo($from), self::identicalTo($to))
                    ->willReturn($expected[$key])
                ;
            }

            self::assertSame($expected, $repository->getReportsForChannelForDates($channel, $from, $to));
        }
    }

    public function testGeneratesAllReportsFromOneReadWithIndependentFinancialTotals(): void
    {
        $repository = $this->repository();
        $channel = $this->channel('fr_FR');
        $from = new DateTimeImmutable('2025-01-01 12:34:56');
        $to = new DateTimeImmutable('2025-12-31 12:34:56');
        $reports = $repository->getReportsForChannelForDates($channel, $from, $to);

        self::assertSame(['units', 'items', 'orders', 'options'], array_column($repository->reads, 0));
        foreach (\array_slice($repository->reads, 0, 3) as $read) {
            self::assertSame($channel, $read[1]);
            self::assertSame('2025-01-01 00:00:00', $read[2]);
            self::assertSame('2025-12-31 23:59:59', $read[3]);
        }
        self::assertSame(['options', 'fr_FR', [11, 12, 21]], $repository->reads[3]);
        self::assertSame('12:34:56', $from->format('H:i:s'));
        self::assertSame('12:34:56', $to->format('H:i:s'));

        // Two orders: 2650 and 1600, including shipping, inclusive/non-inclusive tax and discounts.
        self::assertSame([
            'without_tax_total' => 3300,
            'without_tax_promo_total' => -250,
            'without_tax_shipping_total' => 350,
            'tax_total' => 850,
            'total' => 4250,
            'item_row_total' => 3880,
            'item_qty_total' => 5,
            'number_of_elements' => 0,
        ], $reports['total_sales_result']);
        self::assertSame([
            'without_tax_total' => 1650.0,
            'without_tax_promo_total' => -125.0,
            'without_tax_shipping_total' => 175.0,
            'tax_total' => 425.0,
            'total' => 2125.0,
            'item_row_total' => 1940.0,
            'item_qty_total' => 3.0,
            'number_of_elements' => 2,
        ], $reports['average_sales_result']);
        self::assertSame(2900, $reports['product_sales_result'][1]['without_tax_total']);
        self::assertSame(3400, $reports['product_sales_result'][1]['item_row_total']);
        self::assertSame(2, $reports['product_sales_result'][1]['number_of_elements']);
        self::assertSame(2400, $reports['product_variant_sales_result'][11]['without_tax_total']);
        self::assertSame(2800, $reports['product_variant_sales_result'][11]['item_row_total']);
        self::assertSame(2, $reports['product_variant_sales_result'][11]['number_of_elements']);
        self::assertSame(3300, $reports['product_option_sales_result']['color']['without_tax_total']);
        self::assertSame(3880, $reports['product_option_sales_result']['color']['item_row_total']);
        self::assertSame(2800, $reports['product_option_value_sales_result']['red']['without_tax_total']);
        self::assertSame(3280, $reports['product_option_value_sales_result']['red']['item_row_total']);

        // The existing per-report entry points remain available and keep their output shape.
        $legacy = $this->repository();
        self::assertSame($legacy->getSalesForChannelForDates($channel, $from, $to), $reports['total_sales_result']);
        self::assertSame($legacy->getAverageSalesForChannelForDates($channel, $from, $to), $reports['average_sales_result']);
        self::assertSame($legacy->getProductSalesForChannelForDates($channel, $from, $to), $reports['product_sales_result']);
        self::assertSame($legacy->getProductVariantSalesForChannelForDates($channel, $from, $to), $reports['product_variant_sales_result']);
        self::assertSame($legacy->getProductOptionSalesForChannelForDates($channel, $from, $to), $reports['product_option_sales_result']);
        self::assertSame($legacy->getProductOptionValueSalesForChannelForDates($channel, $from, $to), $reports['product_option_value_sales_result']);
    }

    public function testDoesNotReuseDataAcrossCallsAndDefaultsToOneWholeDay(): void
    {
        $repository = $this->repository();
        $repository->getReportsForChannelForDates($this->channel('fr_FR'), new DateTimeImmutable('2025-01-01'));
        $repository->units = $repository->items = $repository->orders = [];
        $repository->reads = [];
        $channel = $this->channel('en_US');
        $reports = $repository->getReportsForChannelForDates($channel, new DateTimeImmutable('2026-02-03 12:00:00'));

        foreach (\array_slice($repository->reads, 0, 3) as $read) {
            self::assertSame($channel, $read[1]);
            self::assertSame('2026-02-03 00:00:00', $read[2]);
            self::assertSame('2026-02-03 23:59:59', $read[3]);
        }
        self::assertSame(0, $reports['total_sales_result']['total']);
        self::assertSame(0, $reports['average_sales_result']['number_of_elements']);
        foreach (['product_sales_result', 'product_variant_sales_result', 'product_option_sales_result', 'product_option_value_sales_result'] as $key) {
            self::assertSame([], $reports[$key]);
        }
        self::assertSame(['options', 'en_US', []], $repository->reads[3]);
    }

    public function testRequiresTheChannelDefaultLocaleEvenForAnEmptyReport(): void
    {
        $repository = $this->repository();
        $repository->units = $repository->items = $repository->orders = [];
        $this->expectException(MissingLocaleException::class);
        $repository->getReportsForChannelForDates($this->createMock(ChannelInterface::class), new DateTimeImmutable('2025-01-01'));
    }

    public function testGroupedOrderSetsRemainIndependentAcrossRowsAndReportCalls(): void
    {
        $repository = $this->repository();
        $repository->units = $repository->items = [];
        for ($order = 1; $order <= 10000; ++$order) {
            $repository->units[] = $this->row($order, 1, 11, ['without_tax' => '100']);
            $repository->units[] = $this->row($order, 2, 21, ['without_tax' => '200']);
            $repository->items[] = $this->row($order, 1, 11, ['item_qty' => '1']);
        }
        $channel = $this->channel('fr_FR');
        $date = new DateTimeImmutable('2025-01-01');
        $variants = $repository->getProductVariantSalesForChannelForDates($channel, $date);
        self::assertSame(10000, $variants[11]['number_of_elements']);
        self::assertSame(10000, $variants[21]['number_of_elements']);
        self::assertSame(1000000, $variants[11]['without_tax_total']);
        self::assertSame(2000000, $variants[21]['without_tax_total']);
        self::assertSame(10000, $variants[11]['item_qty_total']);
        self::assertSame(0, $variants[21]['item_qty_total']);

        $repository->units = [$this->row(10001, 1, 11, ['without_tax' => '300'])];
        $repository->items = [];
        $variants = $repository->getProductVariantSalesForChannelForDates($channel, $date);
        self::assertSame([11], array_keys($variants));
        self::assertSame(1, $variants[11]['number_of_elements']);
        self::assertSame(300, $variants[11]['without_tax_total']);
    }

    public function testOptionQueryIsLimitedToSoldVariantsAndLocale(): void
    {
        $query = $this->createMock(Query::class);
        $query->expects(self::once())->method('getArrayResult')->willReturn([]);
        $builder = $this->createMock(QueryBuilder::class);
        $builder->method('select')->willReturnSelf();
        $builder->method('leftJoin')->willReturnSelf();
        $builder->expects(self::once())->method('andWhere')->with('v.id IN (:variant_ids)')->willReturnSelf();
        $parameters = [];
        $builder->method('setParameter')->willReturnCallback(static function ($key, $value) use (&$parameters, $builder) {
            $parameters[$key] = $value;

            return $builder;
        });
        $builder->method('getQuery')->willReturn($query);
        $repository = new class($builder) extends ReportRepository {
            public function __construct(private QueryBuilder $builder)
            {
            }

            public function options(array $variants): array
            {
                $this->results = $variants;

                return $this->getVariantsOptions('fr_FR');
            }

            protected function createProductVariantQuery()
            {
                return $this->builder;
            }
        };
        self::assertSame([], $repository->options([11 => [], 21 => []]));
        self::assertSame(['locale' => 'fr_FR', 'variant_ids' => [11, 21]], $parameters);
        // No query is issued when there are no sold variants.
        self::assertSame([], $repository->options([]));
    }

    private function nativeRepository(array $summaries, array $enabledFilters = [], array $expectedQueryPaths = [], ?Connection $connection = null, ?callable $onRead = null, ?Connection $variantConnection = null): ReportRepository
    {
        if (null === $connection) {
            $connection = $this->createMock(Connection::class);
            $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
            $connection->method('isAutoCommit')->willReturn(true);
        }
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $filters = $this->createMock(FilterCollection::class);
        $filters->method('getEnabledFilters')->willReturn($enabledFilters);
        $manager->method('getFilters')->willReturn($filters);
        $orderMetadata = new ClassMetadata(Order::class);
        $orderMetadata->mapOneToMany(['fieldName' => 'items', 'targetEntity' => OrderItem::class, 'mappedBy' => 'order']);
        $itemMetadata = new ClassMetadata(OrderItem::class);
        $itemMetadata->mapOneToMany(['fieldName' => 'units', 'targetEntity' => OrderItemUnit::class, 'mappedBy' => 'orderItem']);
        $unitMetadata = new ClassMetadata(OrderItemUnit::class);
        foreach ([[$orderMetadata, 'order'], [$itemMetadata, 'orderItem'], [$unitMetadata, 'orderItemUnit']] as [$metadata, $owner]) {
            $metadata->mapOneToMany(['fieldName' => 'adjustments', 'targetEntity' => 'Custom\\Adjustment', 'mappedBy' => $owner]);
        }
        $manager->method('getClassMetadata')->willReturnMap([[Order::class, $orderMetadata], [OrderItem::class, $itemMetadata], [OrderItemUnit::class, $unitMetadata]]);
        $manager->expects(self::exactly(\count($summaries)))->method('createQuery')->willReturnCallback(function (string $dql) use (&$summaries, &$expectedQueryPaths, $onRead) {
            if ([] !== $expectedQueryPaths) {
                self::assertStringContainsString(array_shift($expectedQueryPaths), $dql);
                self::assertStringNotContainsString('SIZE(', $dql);
            }
            if (false !== strpos($dql, 'FROM ' . Order::class)) {
                foreach (['o.channel = :channel', 'o.state IN (:states)', 'o.paymentState IN (:payment_states)', 'o.checkoutCompletedAt BETWEEN :from AND :to'] as $filter) {
                    self::assertStringContainsString($filter, $dql);
                }
            }
            $query = $this->createMock(Query::class);
            $query->method('setParameters')->willReturnSelf();
            $query->method('setFirstResult')->willReturnSelf();
            $query->method('setMaxResults')->willReturnSelf();
            $summary = array_shift($summaries);
            $query->expects(self::once())->method('getArrayResult')->willReturnCallback(static function () use ($summary, $onRead) {
                if (null !== $onRead) {
                    $onRead();
                }
                if ($summary instanceof Throwable) {
                    throw $summary;
                }

                return $summary;
            });

            return $query;
        });
        $orders = $this->getMockBuilder(OrderRepositoryInterface::class)->onlyMethods(get_class_methods(OrderRepositoryInterface::class))->addMethods(['createQueryBuilder'])->getMock();
        $orders->method('createQueryBuilder')->willReturnCallback(static fn (string $alias) => (new QueryBuilder($manager))->from(Order::class, $alias));
        $variants = $this->getMockBuilder(ProductVariantRepositoryInterface::class)->onlyMethods(get_class_methods(ProductVariantRepositoryInterface::class))->addMethods(['createQueryBuilder'])->getMock();
        $variantManager = $manager;
        if (null !== $variantConnection) {
            $variantManager = $this->createMock(EntityManagerInterface::class);
            $variantManager->method('getConnection')->willReturn($variantConnection);
        }
        $variants->method('createQueryBuilder')->willReturnCallback(static fn (string $alias) => (new QueryBuilder($variantManager))->from('Custom\\Variant', $alias));

        return new ReportRepository($orders, $variants, null, null);
    }

    private function channel(string $code): ChannelInterface
    {
        $locale = $this->createMock(LocaleInterface::class);
        $locale->method('getCode')->willReturn($code);
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getDefaultLocale')->willReturn($locale);

        return $channel;
    }

    private function repository(string $class = InMemoryReportRepository::class): InMemoryReportRepository
    {
        $repository = new $class(
            $this->createMock(OrderRepositoryInterface::class),
            $this->createMock(ProductVariantRepositoryInterface::class),
            null,
            null
        );
        // Unit rows contain base price net of inclusive tax, promotions and tax; item rows contain quantities/totals.
        $repository->units = [
            $this->row(1, 1, 11, ['without_tax' => '800', 'without_tax_promo' => '-100', 'tax' => '200']),
            $this->row(1, 1, 11, ['without_tax' => '800', 'without_tax_promo' => '-100', 'tax' => '200']),
            $this->row(1, 1, 12, ['without_tax' => '500', 'tax' => '100']),
            $this->row(2, 1, 11, ['without_tax' => '800', 'tax' => '200']),
            $this->row(2, 2, 21, ['without_tax' => '400', 'tax' => '80']),
        ];
        $repository->items = [
            $this->row(1, 1, 11, ['item_row' => '1800', 'item_qty' => '2']),
            $this->row(1, 1, 12, ['item_row' => '600', 'item_qty' => '1']),
            $this->row(2, 1, 11, ['item_row' => '1000', 'item_qty' => '1']),
            $this->row(2, 2, 21, ['item_row' => '480', 'item_qty' => '1']),
        ];
        $repository->orders = [
            $this->row(1, 0, 0, ['without_tax_shipping' => '250', 'tax' => '50', 'without_tax_promo' => '-50', 'total' => '2650']),
            $this->row(2, 0, 0, ['without_tax_shipping' => '100', 'tax' => '20', 'total' => '1600']),
        ];

        return $repository;
    }

    private function row(int $order, int $product, int $variant, array $amounts): array
    {
        return $amounts + [
            'order_id' => $order,
            'product_id' => $product,
            'product_name' => 'Product ' . $product,
            'variant_id' => $variant,
            'variant_name' => 'Variant ' . $variant,
            'without_tax' => null,
            'without_tax_promo' => null,
            'without_tax_shipping' => null,
            'tax' => null,
            'total' => '0',
            'item_row' => '0',
            'item_qty' => '0',
        ];
    }
}

class InMemoryReportRepository extends ReportRepository
{
    public array $units = [];

    public array $items = [];

    public array $orders = [];

    public array $reads = [];

    protected function getOrderItemUnitValues(ChannelInterface $channel, DateTimeInterface $fromDate, DateTimeInterface $toDate): array
    {
        $this->reads[] = ['units', $channel, $fromDate->format('Y-m-d H:i:s'), $toDate->format('Y-m-d H:i:s')];

        return $this->units;
    }

    protected function getOrderItemValues(ChannelInterface $channel, DateTimeInterface $fromDate, DateTimeInterface $toDate): array
    {
        $this->reads[] = ['items', $channel, $fromDate->format('Y-m-d H:i:s'), $toDate->format('Y-m-d H:i:s')];

        return $this->items;
    }

    protected function getOrderValues(ChannelInterface $channel, DateTimeInterface $fromDate, DateTimeInterface $toDate): array
    {
        $this->reads[] = ['orders', $channel, $fromDate->format('Y-m-d H:i:s'), $toDate->format('Y-m-d H:i:s')];

        return $this->orders;
    }

    protected function getVariantsOptions(string $localeCode): array
    {
        $this->reads[] = ['options', $localeCode, array_keys($this->results)];

        return [
            11 => ['color' => ['label' => 'Color', 'value_code' => 'red', 'value_label' => 'Red'], 'size' => ['label' => 'Size', 'value_code' => 'large', 'value_label' => 'Large']],
            12 => ['color' => ['label' => 'Color', 'value_code' => 'blue', 'value_label' => 'Blue'], 'size' => ['label' => 'Size', 'value_code' => 'small', 'value_label' => 'Small']],
            21 => ['color' => ['label' => 'Color', 'value_code' => 'red', 'value_label' => 'Red']],
        ];
    }
}

final class AverageAdjustedReportRepository extends InMemoryReportRepository
{
    protected function averageResult(): void
    {
        parent::averageResult();
        $this->result['total'] += 100;
    }
}

final class OptionAdjustedReportRepository extends InMemoryReportRepository
{
    public int $optionTransformations = 0;

    protected function populateOptions(string $localeCode): array
    {
        $rows = parent::populateOptions($localeCode);
        $rows[0]['without_tax'] += 100 * ++$this->optionTransformations;

        return $rows;
    }
}
