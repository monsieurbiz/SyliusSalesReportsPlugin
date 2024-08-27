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
use Exception;
use MonsieurBiz\SyliusSalesReportsPlugin\Exception\InvalidDateException;
use MonsieurBiz\SyliusSalesReportsPlugin\Exception\MissingLocaleException;
use Sylius\Component\Core\Model\ChannelInterface;

class ReportRepository extends AbstractReportRepository
{
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
