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

$processStart = hrtime(true);

// Deliberately no application bootstrap, dotenv, kernel, or tests/Application.
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/Mapping.php';
require __DIR__ . '/Fixtures.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\SQLLogger;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use SalesReportsBenchmark\Fixtures;
use SalesReportsBenchmark\Mapping;
use Sylius\Component\Core\Model;

$options = getopt('', ['orders:', 'load', 'load-only', 'source:', 'limit:', 'fanout', 'multiitem', 'unitless']);
foreach (array_slice($argv, 1) as $argument) {
    if (!preg_match('/^--(?:(?:orders|source|limit)=.+|load|load-only|fanout|multiitem|unitless)$/D', $argument)) {
        throw new RuntimeException('Unsupported argument; use --orders=N, --source=PATH or --limit=SECONDS for values.');
    }
}
$limitInput = $options['limit'] ?? '180';
if (!is_string($limitInput) || !is_numeric($limitInput) || !is_finite((float) $limitInput) || (float) $limitInput <= 0 || (float) $limitInput > 180) {
    throw new RuntimeException('Limit must be > 0 and <= 180 seconds.');
}
$limit = (float) $limitInput;
$ordersInput = $options['orders'] ?? '30000';
if (!in_array($ordersInput, ['10', '30000', '50000'], true)) {
    throw new RuntimeException('Allowed orders: 10 (control), 30000 (default), 50000 (maximum).');
}
$orders = (int) $ordersInput;
$fanout = isset($options['fanout']);
$multiitem = isset($options['multiitem']);
$unitless = isset($options['unitless']);
$controls = (int) $fanout + (int) $multiitem + (int) $unitless;
if ($controls > 1 || ($controls > 0 && (10 !== $orders || !isset($options['load']) || isset($options['load-only'])))) {
    throw new RuntimeException('--fanout, --multiitem and --unitless are mutually exclusive; require --orders=10 --load and forbid --load-only.');
}
$scenario = $unitless ? 'unitless' : ($multiitem ? 'multiitem' : ($fanout ? 'fanout' : 'standard'));
$url = getenv('SALES_REPORT_BENCHMARK_DATABASE_URL');
$parts = false === $url ? false : parse_url($url);
if (!$parts || !in_array($parts['scheme'] ?? '', ['mysql', 'pdo-mysql', 'pdo_mysql'], true)
    || !in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost', '[::1]'], true)
    || !preg_match('~^/sales_reports_benchmark(?:_[a-z0-9]+)?$~D', $parts['path'] ?? '')
    || isset($parts['query']) || isset($parts['fragment'])) {
    throw new RuntimeException('Set explicit loopback MySQL SALES_REPORT_BENCHMARK_DATABASE_URL with database sales_reports_benchmark[_suffix], no query parameters.');
}
$source = realpath($options['source'] ?? dirname(__DIR__, 2) . '/src');
if (false === $source) {
    throw new RuntimeException('Source directory not found.');
}
require $source . '/Repository/AbstractReportRepository.php';
require $source . '/Repository/ReportRepository.php';

$config = ORMSetup::createConfiguration(true);
$config->setMetadataDriverImpl(new Mapping());
$parameters = ['driver' => 'pdo_mysql', 'host' => '[::1]' === $parts['host'] ? '::1' : $parts['host'],
    'port' => $parts['port'] ?? 3306, 'user' => rawurldecode($parts['user'] ?? ''), 'password' => rawurldecode($parts['pass'] ?? ''),
    'charset' => 'utf8mb4'];
$db = DriverManager::getConnection($parameters);
$version = (string) $db->fetchOne('SELECT VERSION()');
if (!preg_match('/^8\./', $version) || false !== stripos($version, 'mariadb')) {
    throw new RuntimeException('This benchmark requires real MySQL 8, got ' . $version);
}
$databaseSettings = array_map('intval', $db->fetchAssociative('SELECT @@innodb_buffer_pool_size AS buffer_pool_bytes, @@tmp_table_size AS temp_table_bytes, @@max_heap_table_size AS heap_table_bytes'));
if (isset($options['load']) || isset($options['load-only'])) {
    $db->executeStatement('CREATE DATABASE IF NOT EXISTS `' . substr($parts['path'], 1) . '` CHARACTER SET utf8mb4');
}
$db->close();
$parameters['dbname'] = substr($parts['path'], 1);
$db = DriverManager::getConnection($parameters);
$em = EntityManager::create($db, $config);
$metadata = $em->getMetadataFactory()->getAllMetadata();
if (isset($options['load']) || isset($options['load-only'])) {
    // Only these dedicated bench_* tables are dropped; never drop an entire database.
    $tool = new SchemaTool($em);
    $tool->dropSchema($metadata);
    $tool->createSchema($metadata);
    Fixtures::load($db, $orders, $fanout, $multiitem, $unitless);
    // Refresh planner statistics after bulk inserts, outside report timing.
    foreach ($db->fetchAllAssociative('ANALYZE TABLE bench_order, bench_item, bench_unit, bench_adjustment') as $analysis) {
        if ('error' === $analysis['Msg_type']) {
            throw new RuntimeException('Unable to refresh benchmark table statistics.');
        }
    }
    $db->executeStatement('DROP TABLE IF EXISTS bench_manifest');
    $db->executeStatement('CREATE TABLE bench_manifest (orders_count INT NOT NULL, scenario VARCHAR(16) NOT NULL, catalogue_size INT NOT NULL)');
    $db->insert('bench_manifest', ['orders_count' => $orders, 'scenario' => $scenario, 'catalogue_size' => 2]);
    if (isset($options['load-only'])) {
        echo json_encode(['loaded_orders' => $orders, 'excluded_orders' => 4, 'scenario' => $scenario,
            'mysql' => $version]) . \PHP_EOL;
        exit(0);
    }
}
$manifest = $db->fetchAssociative('SELECT * FROM bench_manifest');
if (!$manifest || (int) $manifest['orders_count'] !== $orders || ($manifest['scenario'] ?? null) !== $scenario
    || 2 !== (int) ($manifest['catalogue_size'] ?? 0)
    || (int) $db->fetchOne('SELECT COUNT(*) FROM bench_order') !== $orders + 4
    || 2 !== (int) $db->fetchOne('SELECT COUNT(*) FROM bench_variant')
    || 1 !== (int) $db->fetchOne('SELECT COUNT(*) FROM bench_product')) {
    throw new RuntimeException('Fixture count, scenario or fixed profile mismatch; run --load for this exact profile.');
}

// Interface doubles ONLY forward createQueryBuilder to real Doctrine repositories.
// All reports, DQL, SQL joins, filtering, hydration and aggregation are real.
$factory = new class('benchmark') extends PHPUnit\Framework\TestCase {
    public function wrap(string $interface, Doctrine\ORM\EntityRepository $repository): object
    {
        $double = method_exists($interface, 'createQueryBuilder') ? $this->createMock($interface) :
            $this->getMockBuilder($interface)->onlyMethods(get_class_methods($interface))->addMethods(['createQueryBuilder'])->getMock();
        $double->method('createQueryBuilder')->willReturnCallback(static fn ($alias) => $repository->createQueryBuilder($alias));

        return $double;
    }
};
$report = new MonsieurBiz\SyliusSalesReportsPlugin\Repository\ReportRepository(
    $factory->wrap(Sylius\Component\Core\Repository\OrderRepositoryInterface::class, $em->getRepository(Model\Order::class)),
    $factory->wrap(Sylius\Component\Core\Repository\ProductVariantRepositoryInterface::class, $em->getRepository(Model\ProductVariant::class)),
    null,
    null,
);
$channel = $em->getReference(Model\Channel::class, 1);
$locale = new Sylius\Component\Locale\Model\Locale();
$locale->setCode('en_US');
// Initialize channel proxy before the measured interval.
$channel->getId();
$em->initializeObject($channel);
$channel->setDefaultLocale($locale);
$native = $db->getNativeConnection();
if (!$native instanceof PDO) {
    throw new RuntimeException('Native PDO connection required for server-side query deadlines.');
}
$logger = new class($native, $limit) implements SQLLogger {
    public int $count = 0;

    public int $controlStatements = 0;

    public int $origin = 0;

    public function __construct(private PDO $native, private float $limit)
    {
    }

    public function startQuery($sql, ?array $params = null, ?array $types = null): void
    {
        if (1 !== preg_match('/^SELECT\b/i', ltrim($sql))) {
            ++$this->controlStatements;

            return;
        }
        $deadline = $this->origin + (int) ($this->limit * 1e9);
        $remaining = (int) max(1, floor(($deadline - hrtime(true)) / 1e6));
        // Native PDO avoids recursively invoking this DBAL report-query logger.
        if (false === $this->native->exec('SET SESSION max_execution_time = ' . $remaining)) {
            throw new RuntimeException('Unable to enforce the server-side report query deadline.');
        }
        ++$this->count;
    }

    public function stopQuery(): void
    {
    }
};
$db->getConfiguration()->setSQLLogger($logger);
$from = new DateTimeImmutable('2025-01-01');
$to = new DateTimeImmutable('2025-12-31');
if (function_exists('memory_reset_peak_usage')) {
    memory_reset_peak_usage();
}
$startMemory = memory_get_usage(true);
$start = hrtime(true);
$logger->origin = $start;
$outputs = [];
$bulk = method_exists($report, 'getReportsForChannelForDates');
if (!$bulk && !isset($options['source'])) {
    throw new RuntimeException('The current source must expose the optimized entry point. Use --source to measure a baseline.');
}
if ($bulk) {
    $results = $report->getReportsForChannelForDates($channel, $from, $to);
    foreach (['sales' => 'total_sales_result', 'average' => 'average_sales_result',
        'products' => 'product_sales_result', 'variants' => 'product_variant_sales_result',
        'options' => 'product_option_sales_result', 'option_values' => 'product_option_value_sales_result'] as $key => $resultKey) {
        $outputs[$key] = $results[$resultKey];
    }
} else {
    foreach ([
        'sales' => 'getSalesForChannelForDates',
        'average' => 'getAverageSalesForChannelForDates',
        'products' => 'getProductSalesForChannelForDates',
        'variants' => 'getProductVariantSalesForChannelForDates',
        'options' => 'getProductOptionSalesForChannelForDates',
        'option_values' => 'getProductOptionValueSalesForChannelForDates',
    ] as $key => $method) {
        $outputs[$key] = $report->{$method}($channel, $from, $to);
    }
}
$seconds = (hrtime(true) - $start) / 1e9;
$peak = memory_get_peak_usage(true);
$db->getConfiguration()->setSQLLogger(null);
$expected = Fixtures::expected($orders, $fanout, $multiitem, $unitless);
$assert = static function ($actual, $expected, string $label): void {
    if ($actual != $expected) {
        throw new RuntimeException($label . ' mismatch: ' . json_encode(['actual' => $actual, 'expected' => $expected]));
    }
};
$expectedQueries = $bulk ? 8 : 16;
$assert($logger->count, $expectedQueries, 'actual report data SQL query count');
$assert($logger->controlStatements, $bulk ? 3 : 0, 'snapshot control statement count');
if (10 === $orders) {
    $referenceTotals = $multiitem ? [
        'without_tax_total' => 29000, 'without_tax_promo_total' => -965,
        'without_tax_shipping_total' => 4800, 'tax_total' => 5800, 'total' => 38835,
        'item_row_total' => 34035, 'item_qty_total' => 29, 'number_of_elements' => 0,
    ] : [
        'without_tax_total' => 28000, 'without_tax_promo_total' => $fanout ? -952 : -920,
        'without_tax_shipping_total' => 4800, 'tax_total' => 5600, 'total' => $fanout ? 37648 : 37680,
        'item_row_total' => $fanout ? 32848 : 32880, 'item_qty_total' => 28, 'number_of_elements' => 0,
    ];
    if ($unitless) {
        $referenceTotals = ['without_tax_total' => 26200, 'without_tax_promo_total' => -875,
            'without_tax_shipping_total' => 4800, 'tax_total' => 5000, 'total' => 37680,
            'item_row_total' => 32880, 'item_qty_total' => 28, 'number_of_elements' => 0];
    }
    $assert($expected, $referenceTotals, 'literal reference fixture');
}
$assert($outputs['sales'], $expected, 'sales exact control totals');
$average = array_map(static fn ($value) => round($value / $orders), $expected);
$average['number_of_elements'] = $orders;
$assert($outputs['average'], $average, 'average');
$groupExpected = $expected;
$groupExpected['total'] = 0;
$groupExpected['without_tax_shipping_total'] = 0;
$groupExpected['without_tax_promo_total'] += 20 * $orders;
$variantCounts = Fixtures::expectedVariants($orders, $multiitem);
$productCounts = Fixtures::expectedProducts($orders, $multiitem);
$groupDefinitions = ['products' => [], 'variants' => [], 'options' => [], 'option_values' => []];
foreach (['products' => $productCounts, 'variants' => $variantCounts] as $key => $groups) {
    foreach ($groups as $id => $counts) {
        $counts['elements'] = $counts['orders'];
        $counts['extra_promotion'] = $fanout && 1 === $id ? 32 : 0;
        $counts['unitless'] = $unitless && 1 === $id;
        $groupDefinitions[$key][$id] = $counts;
        if ('variants' === $key) {
            $counts['elements'] = 1;
            $groupDefinitions['option_values'][Fixtures::variantCode($id)] = $counts;
        }
    }
}
$groupDefinitions['options']['size'] = ['items' => $orders + ($multiitem ? 1 : 0),
    'units' => $expected['item_qty_total'], 'elements' => count(array_unique(array_column($variantCounts, 'representative'))),
    'extra_promotion' => $fanout ? 32 : 0, 'unitless' => $unitless];
foreach (['products', 'variants', 'options', 'option_values'] as $key) {
    $totals = array_fill_keys(array_keys($groupExpected), 0);
    foreach ($outputs[$key] as $row) {
        foreach ($totals as $field => $_) {
            if ('number_of_elements' !== $field) {
                $totals[$field] += $row[$field];
            }
        }
    }
    $groupExpected['number_of_elements'] = 0;
    $assert($totals, $groupExpected, $key . ' exact financial totals');
    $actualIds = array_keys($outputs[$key]);
    $expectedIds = array_keys($groupDefinitions[$key]);
    sort($actualIds);
    sort($expectedIds);
    $assert($actualIds, $expectedIds, $key . ' exact sold group IDs');
    foreach ($outputs[$key] as $id => $row) {
        $counts = $groupDefinitions[$key][$id];
        $itemCount = $counts['items'];
        $units = $counts['units'];
        $extraPromotion = $counts['extra_promotion'];
        // Options count distinct representative order IDs from variant summaries, not underlying orders.
        $assert($row['number_of_elements'], $counts['elements'], $key . ' element count');
        $assert($row['total'], 0, $key . ' group order total');
        $assert($row['without_tax_shipping_total'], 0, $key . ' group shipping');
        $assert($row['item_qty_total'], $units, $key . ' group quantity');
        $assert($row['without_tax_total'], $units * 1000 - ($counts['unitless'] ? 1800 : 0), $key . ' group net');
        $assert($row['without_tax_promo_total'], -15 * $units - 30 * $itemCount - $extraPromotion + ($counts['unitless'] ? 45 : 0), $key . ' group promotions');
        $assert($row['tax_total'], 200 * $units - ($counts['unitless'] ? 600 : 0), $key . ' group tax');
        $assert($row['item_row_total'], 1185 * $units - 30 * $itemCount - $extraPromotion, $key . ' group item total');
    }
}
echo json_encode(['description' => 'Synthetic structural SQL stress test, not a full business workflow recreation',
    'source' => $source, 'orders' => $orders, 'excluded_orders' => 4, 'scenario' => $scenario, 'seed' => 2025, 'mysql' => $version,
    'sold_product_groups' => count($productCounts), 'sold_variant_groups' => count($variantCounts),
    'database_settings' => $databaseSettings, 'bootstrap_seconds' => ($start - $processStart) / 1e9,
    'strategy' => $bulk ? 'report-wide controller entry point' : 'six public methods (baseline)', 'queries' => $logger->count,
    'transaction_control_statements' => $logger->controlStatements,
    'seconds' => $seconds, 'limit_seconds' => $limit, 'memory_start_bytes' => $startMemory,
    'peak_memory_bytes' => $peak, 'peak_delta_bytes' => $peak - $startMemory,
    'checks' => 'all six outputs checked against deterministic integer formulas', 'outputs' => $outputs], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR) . \PHP_EOL;
if ($seconds > $limit) {
    fwrite(\STDERR, "FAIL: report exceeded {$limit} seconds\n");
    exit(1);
}
