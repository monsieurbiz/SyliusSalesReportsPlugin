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

use DateTime;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Exception;
use LogicException;
use MonsieurBiz\SyliusSalesReportsPlugin\Exception\InvalidDateException;
use MonsieurBiz\SyliusSalesReportsPlugin\Exception\MissingLocaleException;
use ReflectionClass;
use ReflectionMethod;
use Sylius\Component\Core\Model\ChannelInterface;
use Throwable;

class ReportRepository extends AbstractReportRepository
{
    /**
     * Generate the built-in reports from one set of sales data, without caching it between calls.
     * Public per-report and protected transformation overrides retain the legacy execution path.
     * Native aggregation requires its own autocommit connection with no existing transaction,
     * on MySQL/MariaDB or PostgreSQL, shared by the order and variant repositories.
     *
     * @throws InvalidDateException
     * @throws MissingLocaleException
     */
    public function getReportsForChannelForDates(
        ChannelInterface $channel,
        DateTimeInterface $fromDate,
        ?DateTimeInterface $toDate = null
    ): array {
        $reportMethods = [
            'getSalesForChannelForDates' => 'total_sales_result',
            'getAverageSalesForChannelForDates' => 'average_sales_result',
            'getProductSalesForChannelForDates' => 'product_sales_result',
            'getProductVariantSalesForChannelForDates' => 'product_variant_sales_result',
            'getProductOptionSalesForChannelForDates' => 'product_option_sales_result',
            'getProductOptionValueSalesForChannelForDates' => 'product_option_value_sales_result',
        ];
        if ($this->hasLegacyOverrides($reportMethods)) {
            return $this->getLegacyReports($reportMethods, $channel, $fromDate, $toDate);
        }

        $toDate = $toDate ?? $fromDate;

        try {
            $fromDate = new DateTime($fromDate->format('Y-m-d') . ' 00:00:00');
            $toDate = new DateTime($toDate->format('Y-m-d') . ' 23:59:59');
        } catch (Exception $e) {
            throw new InvalidDateException('Invalid date given to report.');
        }

        if ($this->canAggregateReports()) {
            return $this->getSnapshotReports($channel, $fromDate, $toDate);
        }

        return $this->addOptionReports($this->getArrayReports($channel, $fromDate, $toDate), $channel);
    }

    private function getSnapshotReports(ChannelInterface $channel, DateTimeInterface $fromDate, DateTimeInterface $toDate): array
    {
        $connection = $this->getSnapshotConnection();

        try {
            // MySQL applies this to the next transaction; PostgreSQL applies it to the current one.
            switch (true) {
                case $connection->getDatabasePlatform() instanceof PostgreSQLPlatform:
                    $connection->beginTransaction();
                    $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

                    break;
                default:
                    $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                    $connection->beginTransaction();
            }
            $reports = $this->addOptionReports($this->getAggregateReports($channel, $fromDate, $toDate), $channel);
            $connection->commit();

            return $reports;
        } catch (Throwable $error) {
            $this->discardSnapshot($connection);

            throw $error;
        }
    }

    private function getSnapshotConnection(): Connection
    {
        $connection = $this->createOrderQuery()->getEntityManager()->getConnection();
        if ($connection->isTransactionActive() || !$connection->isAutoCommit()) {
            throw new LogicException('Native reports must own their snapshot: autocommit is required and no transaction may be active.');
        }
        $this->validateSnapshotPlatform($connection);
        if ($connection !== $this->createProductVariantQuery()->getEntityManager()->getConnection()) {
            throw new LogicException('Native report snapshots require the same connection for orders and product variants.');
        }

        return $connection;
    }

    private function validateSnapshotPlatform(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();
        if (!$platform instanceof MySQLPlatform && !$platform instanceof PostgreSQLPlatform) {
            throw new LogicException('Native report snapshots support only MySQL/MariaDB and PostgreSQL.');
        }
    }

    private function discardSnapshot(Connection $connection): void
    {
        try {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        } catch (Throwable $cleanupError) {
            // Preserve the original error even if the driver cannot roll back.
        }

        try {
            // Failed commits can leave uncertain driver state even when DBAL reports depth zero.
            $connection->close();
        } catch (Throwable $cleanupError) {
            // Cleanup must never replace the original error.
        }
    }

    private function addOptionReports(array $reports, ChannelInterface $channel): array
    {
        $this->results = $reports['product_variant_sales_result'];

        if (!($locale = $channel->getDefaultLocale()) || (!$localeCode = $locale->getCode())) {
            throw new MissingLocaleException('Missing default locale for channel');
        }
        $resultsWithOptions = $this->populateOptions($localeCode);

        $this->results = [];
        $this->elementsArray = [];
        $this->addResultsByElement($resultsWithOptions, 'option_code', 'option_label');
        $reports['product_option_sales_result'] = $this->results;

        $this->results = [];
        $this->elementsArray = [];
        $this->addResultsByElement($resultsWithOptions, 'option_value_code', 'option_value_label', ['option_code', 'option_label']);
        $reports['product_option_value_sales_result'] = $this->results;

        return $reports;
    }

    private function hasLegacyOverrides(array $reportMethods): bool
    {
        $legacyMethods = array_merge(array_keys($reportMethods), ['initResult', 'addResults', 'averageResult', 'addResultsByElement', 'populateOptions']);
        foreach ($legacyMethods as $method) {
            if ($this->isMethodOverridden($method)) {
                return true;
            }
        }

        return false;
    }

    private function getLegacyReports(array $reportMethods, ChannelInterface $channel, DateTimeInterface $fromDate, ?DateTimeInterface $toDate): array
    {
        // Preserve report transformations, their invocation order and original date arguments.
        $reports = [];
        foreach ($reportMethods as $reportMethod => $reportKey) {
            $reports[$reportKey] = $this->{$reportMethod}($channel, $fromDate, $toDate);
        }

        return $reports;
    }

    private function canAggregateReports(): bool
    {
        // Query-only customizations retain array bulk; transformation customizations already use legacy calls.
        foreach ((new ReflectionClass(AbstractReportRepository::class))->getMethods(ReflectionMethod::IS_PROTECTED) as $method) {
            if ($this->isMethodOverridden($method->getName())) {
                return false;
            }
        }

        // SIZE(item.units) does not apply target SQL filters; retain the filter-aware legacy joins.
        return [] === $this->createOrderQuery()->getEntityManager()->getFilters()->getEnabledFilters();
    }

    private function isMethodOverridden(string $method): bool
    {
        return (new ReflectionMethod(self::class, $method))->getDeclaringClass()->getName()
            !== (new ReflectionMethod($this, $method))->getDeclaringClass()->getName();
    }

    private function getAggregateReports(ChannelInterface $channel, DateTimeInterface $fromDate, DateTimeInterface $toDate): array
    {
        $queries = new ReportAggregateQueryBuilder();
        $assembler = new ReportSummaryAssembler();
        $filteredQuery = fn () => $this->appendOrderFilters($this->createOrderQuery(), $channel, $fromDate, $toDate);
        $orderBase = $queries->createOrderBaseQuery($filteredQuery())->getQuery()->getArrayResult()[0];
        if (0 === (int) $orderBase['order_count']) {
            return $assembler->assemble($orderBase, [], [], [], [], [], []);
        }
        $itemBase = $queries->createItemBaseQuery($filteredQuery())->getQuery()->getArrayResult();
        $unitAdjustments = $queries->createUnitAdjustmentsQuery($filteredQuery())->getQuery()->getArrayResult();
        $itemAdjustments = $queries->createItemAdjustmentsQuery($filteredQuery())->getQuery()->getArrayResult();
        $orderAdjustments = $queries->createOrderAdjustmentsQuery($filteredQuery())->getQuery()->getArrayResult()[0];
        $productCounts = $queries->createProductOrderCountsQuery($filteredQuery())->getQuery()->getArrayResult();
        $itemIds = array_values(array_filter(array_column($itemBase, 'latest_item_id')));
        $labels = [];
        if ([] !== $itemIds) {
            $labels = $queries->createSnapshotLabelsQuery($filteredQuery(), $itemIds)->getQuery()->getArrayResult();
        }

        return $assembler->assemble($orderBase, $itemBase, $unitAdjustments, $itemAdjustments, $orderAdjustments, $productCounts, $labels);
    }

    private function getArrayReports(ChannelInterface $channel, DateTimeInterface $fromDate, DateTimeInterface $toDate): array
    {
        $reports = [];
        $unitValues = $this->getOrderItemUnitValues($channel, $fromDate, $toDate);
        $itemValues = $this->getOrderItemValues($channel, $fromDate, $toDate);

        $this->initResult();
        $this->addResults($unitValues, 'order_id');
        $this->addResults($itemValues, 'order_id');
        $this->addResults($this->getOrderValues($channel, $fromDate, $toDate), 'order_id');
        $reports['total_sales_result'] = $this->result;
        $reports['total_sales_result']['number_of_elements'] = 0;
        $this->averageResult();
        $reports['average_sales_result'] = $this->result;

        $this->results = [];
        $this->elementsArray = [];
        $this->addResultsByElement($unitValues, 'product_id', 'product_name');
        $this->addResultsByElement($itemValues, 'product_id', 'product_name');
        $reports['product_sales_result'] = $this->results;

        $this->results = [];
        $this->elementsArray = [];
        $this->addResultsByElement($unitValues, 'variant_id', 'variant_name');
        $this->addResultsByElement($itemValues, 'variant_id', 'variant_name');
        $reports['product_variant_sales_result'] = $this->results;

        return $reports;
    }

    /**
     * Get total sales for channel between 2 date times, or average sales from a given field.
     *
     * @throws InvalidDateException
     */
    public function getSalesForChannelForDates(
        ChannelInterface $channel,
        DateTimeInterface $fromDate,
        ?DateTimeInterface $toDate = null,
        ?string $groupField = null
    ): array {
        $toDate = $toDate ?? $fromDate; // If to is null, take the same day as from to make report on one day

        try {
            $fromDate = new DateTime($fromDate->format('Y-m-d') . ' 00:00:00');
            $toDate = new DateTime($toDate->format('Y-m-d') . ' 23:59:59');
        } catch (Exception $e) {
            throw new InvalidDateException('Invalid date given to report.');
        }

        $this->initResult();

        // Order Item Units values
        $this->addResults($this->getOrderItemUnitValues($channel, $fromDate, $toDate), $groupField);
        // Order Items values
        $this->addResults($this->getOrderItemValues($channel, $fromDate, $toDate), $groupField);
        // Order values
        $this->addResults($this->getOrderValues($channel, $fromDate, $toDate), $groupField);

        // Divide results by number of elements if needed
        $this->averageResult();

        return $this->result;
    }

    /**
     * Get average sales for channel between 2 date times.
     *
     * @throws InvalidDateException
     */
    public function getAverageSalesForChannelForDates(
        ChannelInterface $channel,
        DateTimeInterface $fromDate,
        ?DateTimeInterface $toDate = null
    ): array {
        return $this->getSalesForChannelForDates($channel, $fromDate, $toDate, 'order_id');
    }

    /**
     * Get sales per product variant for channel between 2 date times.
     *
     * @throws InvalidDateException
     */
    public function getProductVariantSalesForChannelForDates(
        ChannelInterface $channel,
        DateTimeInterface $fromDate,
        ?DateTimeInterface $toDate = null
    ): array {
        $toDate = $toDate ?? $fromDate; // If to is null, take the same day as from to make report on one day

        try {
            $fromDate = new DateTime($fromDate->format('Y-m-d') . ' 00:00:00');
            $toDate = new DateTime($toDate->format('Y-m-d') . ' 23:59:59');
        } catch (Exception $e) {
            throw new InvalidDateException('Invalid date given to report.');
        }

        $this->results = [];

        // Order Item Units values
        $this->addResultsByElement(
            $this->getOrderItemUnitValues($channel, $fromDate, $toDate),
            'variant_id',
            'variant_name'
        );
        // Order Items values
        $this->addResultsByElement(
            $this->getOrderItemValues($channel, $fromDate, $toDate),
            'variant_id',
            'variant_name'
        );

        return $this->results;
    }

    /**
     * Get sales per product option for channel between 2 date times.
     *
     * @throws InvalidDateException
     * @throws MissingLocaleException
     */
    public function getProductOptionSalesForChannelForDates(
        ChannelInterface $channel,
        DateTimeInterface $fromDate,
        ?DateTimeInterface $toDate = null
    ): array {
        $toDate = $toDate ?? $fromDate; // If to is null, take the same day as from to make report on one day

        try {
            $fromDate = new DateTime($fromDate->format('Y-m-d') . ' 00:00:00');
            $toDate = new DateTime($toDate->format('Y-m-d') . ' 23:59:59');
        } catch (Exception $e) {
            throw new InvalidDateException('Invalid date given to report.');
        }

        $this->results = [];

        // Order Item Units values
        $this->addResultsByElement(
            $this->getOrderItemUnitValues($channel, $fromDate, $toDate),
            'variant_id',
            'variant_name'
        );
        // Order Items values
        $this->addResultsByElement(
            $this->getOrderItemValues($channel, $fromDate, $toDate),
            'variant_id',
            'variant_name'
        );

        // Populate array with options values data
        if (!($locale = $channel->getDefaultLocale()) || (!$localeCode = $locale->getCode())) {
            throw new MissingLocaleException('Missing default locale for channel');
        }
        $resultsWithOptions = $this->populateOptions($localeCode);

        // Reinit results to generate a new one
        $this->results = [];

        $this->addResultsByElement($resultsWithOptions, 'option_code', 'option_label');

        return $this->results;
    }

    /**
     * Get sales per product option for channel between 2 date times.
     *
     * @throws InvalidDateException
     * @throws MissingLocaleException
     */
    public function getProductOptionValueSalesForChannelForDates(
        ChannelInterface $channel,
        DateTimeInterface $fromDate,
        ?DateTimeInterface $toDate = null
    ): array {
        $toDate = $toDate ?? $fromDate; // If to is null, take the same day as from to make report on one day

        try {
            $fromDate = new DateTime($fromDate->format('Y-m-d') . ' 00:00:00');
            $toDate = new DateTime($toDate->format('Y-m-d') . ' 23:59:59');
        } catch (Exception $e) {
            throw new InvalidDateException('Invalid date given to report.');
        }

        $this->results = [];

        // Order Item Units values
        $this->addResultsByElement(
            $this->getOrderItemUnitValues($channel, $fromDate, $toDate),
            'variant_id',
            'variant_name'
        );
        // Order Items values
        $this->addResultsByElement(
            $this->getOrderItemValues($channel, $fromDate, $toDate),
            'variant_id',
            'variant_name'
        );

        // Populate array with options values data
        if (!($locale = $channel->getDefaultLocale()) || (!$localeCode = $locale->getCode())) {
            throw new MissingLocaleException('Missing default locale for channel');
        }
        $resultsWithOptions = $this->populateOptions($localeCode);

        // Reinit results to generate a new one
        $this->results = [];

        $this->addResultsByElement(
            $resultsWithOptions,
            'option_value_code',
            'option_value_label',
            ['option_code', 'option_label']
        );

        return $this->results;
    }

    /**
     * Get sales per product for channel between 2 date times.
     *
     * @throws InvalidDateException
     */
    public function getProductSalesForChannelForDates(
        ChannelInterface $channel,
        DateTimeInterface $fromDate,
        ?DateTimeInterface $toDate = null
    ): array {
        $toDate = $toDate ?? $fromDate; // If to is null, take the same day as from to make report on one day

        try {
            $fromDate = new DateTime($fromDate->format('Y-m-d') . ' 00:00:00');
            $toDate = new DateTime($toDate->format('Y-m-d') . ' 23:59:59');
        } catch (Exception $e) {
            throw new InvalidDateException('Invalid date given to report.');
        }

        $this->results = [];

        // Order Item Units values
        $this->addResultsByElement(
            $this->getOrderItemUnitValues($channel, $fromDate, $toDate),
            'product_id',
            'product_name'
        );
        // Order Items values
        $this->addResultsByElement(
            $this->getOrderItemValues($channel, $fromDate, $toDate),
            'product_id',
            'product_name'
        );

        return $this->results;
    }
}
