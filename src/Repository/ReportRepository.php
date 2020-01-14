<?php

declare(strict_types=1);

namespace MonsieurBiz\SyliusSalesReportsPlugin\Repository;

use Doctrine\ORM\QueryBuilder;
use MonsieurBiz\SyliusSalesReportsPlugin\Exception\InvalidDateException;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;

class ReportRepository
{
    // Adjustment if Admin Order Creation plugin is installed
    public const ADMIN_ORDER_DISCOUNT_ADJUSTMENT = 'order_discount';
    public const ADMIN_ORDER_ITEM_DISCOUNT_ADJUSTMENT = 'order_item_discount';

    /**
     * @var RepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var RepositoryInterface
     */
    protected $productVariantRepository;

    /**
     * Result with totals, one dimensional array
     *
     * @var array
     */
    protected $result;

    /**
     * Results with totals by elements, two dimensional array
     *
     * @var array
     */
    protected $results;

    /**
     * The elements we want to group, id as key, id or label as value, one dimensional array
     *
     * @var array
     */
    protected $elements;

    /**
     * An array of elements we want to group, two dimensional array
     *
     * @var array
     */
    protected $elementsArray;

    /**
     * ReportRepository constructor.
     * @param RepositoryInterface $orderRepository
     */
    public function __construct(RepositoryInterface $orderRepository, RepositoryInterface $productVariantRepository)
    {
        $this->orderRepository = $orderRepository;
        $this->productVariantRepository = $productVariantRepository;
    }

    /**
     * Get total sales for channel between 2 date times, or average sales from a given field
     *
     * @param ChannelInterface $channel
     * @param \DateTimeInterface $from
     * @param \DateTimeInterface|null $to
     * @param string|null $groupField
     * @return array
     * @throws InvalidDateException
     */
    public function getSalesForChannelForDates(
        ChannelInterface $channel,
        \DateTimeInterface $from,
        ?\DateTimeInterface $to = null,
        ?string $groupField = null
    ): array {
        $to = $to ?? $from; // If to is null, take the same day as from to make report on one day
        try {
            $from = new \DateTime($from->format("Y-m-d")." 00:00:00");
            $to   = new \DateTime($to->format("Y-m-d")." 23:59:59");
        } catch (\Exception $e) {
            throw new InvalidDateException('Invalid date given to report.');
        }

        $this->initResult();

        // Order Item Units values
        $this->addResults($this->getOrderItemUnitValues($channel, $from, $to), $groupField);
        // Order Items values
        $this->addResults($this->getOrderItemValues($channel, $from, $to), $groupField);
        // Order values
        $this->addResults($this->getOrderValues($channel, $from, $to), $groupField);

        // Divide results by number of elements if needed
        $this->averageResult();

        return $this->result;
    }


    /**
     * Get average sales for channel between 2 date times
     *
     * @param ChannelInterface $channel
     * @param \DateTimeInterface $from
     * @param \DateTimeInterface|null $to
     * @return array
     * @throws InvalidDateException
     */
    public function getAverageSalesForChannelForDates(
        ChannelInterface $channel,
        \DateTimeInterface $from,
        ?\DateTimeInterface $to = null
    ): array {
        return $this->getSalesForChannelForDates($channel, $from, $to, 'order_id');
    }

    /**
     * Get sales per product variant for channel between 2 date times
     *
     * @param ChannelInterface $channel
     * @param \DateTimeInterface $from
     * @param \DateTimeInterface|null $to
     * @return array
     * @throws InvalidDateException
     */
    public function getProductVariantSalesForChannelForDates(
        ChannelInterface $channel,
        \DateTimeInterface $from,
        ?\DateTimeInterface $to = null
    ): array {
        $to = $to ?? $from; // If to is null, take the same day as from to make report on one day
        try {
            $from = new \DateTime($from->format("Y-m-d")." 00:00:00");
            $to   = new \DateTime($to->format("Y-m-d")." 23:59:59");
        } catch (\Exception $e) {
            throw new InvalidDateException('Invalid date given to report.');
        }

        $this->results = [];

        // Order Item Units values
        $this->addResultsByElement(
            $this->getOrderItemUnitValues($channel, $from, $to), 'variant_id', 'variant_name'
        );
        // Order Items values
        $this->addResultsByElement(
            $this->getOrderItemValues($channel, $from, $to), 'variant_id', 'variant_name'
        );

        return $this->results;
    }

    /**
     * Get sales per product option for channel between 2 date times
     *
     * @param ChannelInterface $channel
     * @param \DateTimeInterface $from
     * @param \DateTimeInterface|null $to
     * @return array
     * @throws InvalidDateException
     */
    public function getProductOptionSalesForChannelForDates(
        ChannelInterface $channel,
        \DateTimeInterface $from,
        ?\DateTimeInterface $to = null
    ): array {
        $to = $to ?? $from; // If to is null, take the same day as from to make report on one day
        try {
            $from = new \DateTime($from->format("Y-m-d")." 00:00:00");
            $to   = new \DateTime($to->format("Y-m-d")." 23:59:59");
        } catch (\Exception $e) {
            throw new InvalidDateException('Invalid date given to report.');
        }

        $this->results = [];

        // Order Item Units values
        $this->addResultsByElement(
            $this->getOrderItemUnitValues($channel, $from, $to), 'variant_id', 'variant_name'
        );
        // Order Items values
        $this->addResultsByElement(
            $this->getOrderItemValues($channel, $from, $to), 'variant_id', 'variant_name'
        );

        // Populate array with options values data
        $resultsWithOptions = $this->populateOptions($channel->getDefaultLocale()->getCode());

        // Reinit results to generate a new one
        $this->results = [];

        $this->addResultsByElement($resultsWithOptions, 'option_code', 'option_label');

        return $this->results;
    }

    /**
     * Get sales per product option for channel between 2 date times
     *
     * @param ChannelInterface $channel
     * @param \DateTimeInterface $from
     * @param \DateTimeInterface|null $to
     * @return array
     * @throws InvalidDateException
     */
    public function getProductOptionValueSalesForChannelForDates(
        ChannelInterface $channel,
        \DateTimeInterface $from,
        ?\DateTimeInterface $to = null
    ): array {
        $to = $to ?? $from; // If to is null, take the same day as from to make report on one day
        try {
            $from = new \DateTime($from->format("Y-m-d")." 00:00:00");
            $to   = new \DateTime($to->format("Y-m-d")." 23:59:59");
        } catch (\Exception $e) {
            throw new InvalidDateException('Invalid date given to report.');
        }

        $this->results = [];

        // Order Item Units values
        $this->addResultsByElement(
            $this->getOrderItemUnitValues($channel, $from, $to), 'variant_id', 'variant_name'
        );
        // Order Items values
        $this->addResultsByElement(
            $this->getOrderItemValues($channel, $from, $to), 'variant_id', 'variant_name'
        );

        // Populate array with options values data
        $resultsWithOptions = $this->populateOptions($channel->getDefaultLocale()->getCode());

        // Reinit results to generate a new one
        $this->results = [];

        $this->addResultsByElement($resultsWithOptions, 'option_value_code', 'option_value_label', ['option_code', 'option_label']);

        return $this->results;
    }

    /**
     * Get sales per product for channel between 2 date times
     *
     * @param ChannelInterface $channel
     * @param \DateTimeInterface $from
     * @param \DateTimeInterface|null $to
     * @return array
     * @throws InvalidDateException
     */
    public function getProductSalesForChannelForDates(
        ChannelInterface $channel,
        \DateTimeInterface $from,
        ?\DateTimeInterface $to = null
    ): array {
        $to = $to ?? $from; // If to is null, take the same day as from to make report on one day
        try {
            $from = new \DateTime($from->format("Y-m-d")." 00:00:00");
            $to   = new \DateTime($to->format("Y-m-d")." 23:59:59");
        } catch (\Exception $e) {
            throw new InvalidDateException('Invalid date given to report.');
        }

        $this->results = [];

        // Order Item Units values
        $this->addResultsByElement(
            $this->getOrderItemUnitValues($channel, $from, $to), 'product_id', 'product_name'
        );
        // Order Items values
        $this->addResultsByElement(
            $this->getOrderItemValues($channel, $from, $to), 'product_id', 'product_name'
        );

        return $this->results;
    }

    /**
     * Generate results for order item units
     *
     * @param ChannelInterface $channel
     * @param \DateTimeInterface $from
     * @param \DateTimeInterface $to
     * @return array
     */
    private function getOrderItemUnitValues(
        ChannelInterface $channel,
        \DateTimeInterface $from,
        \DateTimeInterface $to
    ): array {
        $queryBuilder = $this->orderRepository->createQueryBuilder('o')
            ->select($this->getSelectColumns(true, false, false))
            ->leftJoin('o.items', 'item')
            ->leftJoin('item.variant', 'variant')
            ->leftJoin('item.units', 'element')
        ;
        $queryBuilder = $this->appendAdjustmentsAndParameters($queryBuilder, $channel, $from, $to);
        return $queryBuilder->getQuery()->getArrayResult();
    }

    /**
     * Generate results for order items
     *
     * @param ChannelInterface $channel
     * @param \DateTimeInterface $from
     * @param \DateTimeInterface $to
     * @return array
     */
    private function getOrderItemValues(
        ChannelInterface $channel,
        \DateTimeInterface $from,
        \DateTimeInterface $to
    ): array {
        $queryBuilder = $this->orderRepository->createQueryBuilder('o')
            ->select($this->getSelectColumns(false, true, false))
            ->leftJoin('o.items', 'element')
            ->leftJoin('element.variant', 'variant')
        ;
        $queryBuilder = $this->appendAdjustmentsAndParameters($queryBuilder, $channel, $from, $to);
        return $queryBuilder->getQuery()->getArrayResult();
    }

    /**
     * Generate results for orders
     *
     * @param ChannelInterface $channel
     * @param \DateTimeInterface $from
     * @param \DateTimeInterface $to
     * @return array
     */
    private function getOrderValues(
        ChannelInterface $channel,
        \DateTimeInterface $from,
        \DateTimeInterface $to
    ): array {
        $queryBuilder = $this->orderRepository->createQueryBuilder('o')->select($this->getSelectColumns(false, false, true));
        $queryBuilder = $this->appendAdjustmentsAndParameters($queryBuilder, $channel, $from, $to, true);
        return $queryBuilder->getQuery()->getArrayResult();
    }

    /**
     * Retrieve columns for select.
     * Column order_id is used to generate average report
     * Column without_tax is for unit price without tax in item units
     * Columns without_tax_promo, without_tax_shipping, tax columns are respectively for promotions, shipping, tax amounts
     * Columns item and total are respectively for total for items total for orders (With shipping etc.)
     *
     * @param bool $isItemUnit
     * @param bool $isItem
     * @param bool $isOrder
     * @return string
     */
    private function getSelectColumns(bool $isItemUnit = false, bool $isItem = false, bool $isOrder = false): string
    {
        return implode(',',[
            // Order ID
            'o.id as order_id',

            // Product infos
            ($isItemUnit ? 'IDENTITY(variant.product) as product_id' : ($isItem ? 'IDENTITY(variant.product) as product_id' : '\'\' as product_id')),
            ($isItemUnit ? 'item.productName as product_name' : ($isItem ? 'element.productName as product_name' : '\'\' as product_name')),

            // Variant infos
            ($isItemUnit ? 'IDENTITY(item.variant) as variant_id' : ($isItem ? 'IDENTITY(element.variant) as variant_id' : '\'\' as variant_id')),
            ($isItemUnit ? 'CONCAT(item.productName, \' \' ,item.variantName) as variant_name' : ($isItem ? 'CONCAT(element.productName, \' \' , element.variantName) as variant_name' : '\'\' as variant_name')),

            // Adjustments
            $isItemUnit ? 'item.unitPrice - COALESCE(tax_adjustment.amount, 0) as without_tax' : '0 as without_tax', // Only retrieve without_tax price for item units
            '(COALESCE(order_promotion_adjustment.amount, 0) + COALESCE(order_item_promotion_adjustment.amount, 0) + COALESCE(order_shipping_promotion_adjustment.amount, 0) + COALESCE(order_unit_promotion_adjustment.amount, 0)) AS without_tax_promo',
            'shipping_adjustment.amount as without_tax_shipping',
            'tax_adjustment.amount as tax',

            // Totals
            $isOrder ? 'o.total as total' : '0 as total',
            $isItem ? 'element.total as item_row' : '0 as item_row'
        ]);
    }

    /**
     * Make joins with all adjustments, add conditions and set parameters to query
     *
     * @param $queryBuilder
     * @param $channel
     * @param $from
     * @param $to
     * @param bool $isOrder
     * @return mixed
     */
    private function appendAdjustmentsAndParameters(
        QueryBuilder $queryBuilder,
        ChannelInterface $channel,
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        bool $isOrder = false
    ) {
        $elementAlias = $isOrder ? 'o' : 'element';
        return $queryBuilder
            // Adjustments joins
            ->leftJoin($elementAlias . '.adjustments', 'tax_adjustment', 'WITH', 'tax_adjustment.type = :tax_type')
            ->leftJoin($elementAlias . '.adjustments', 'shipping_adjustment', 'WITH', 'shipping_adjustment.type = :shipping_type')
            ->leftJoin($elementAlias . '.adjustments', 'order_promotion_adjustment', 'WITH', 'order_promotion_adjustment.type = :order_promotion_type OR order_promotion_adjustment.type = :admin_order_promotion_type')
            ->leftJoin($elementAlias . '.adjustments', 'order_item_promotion_adjustment', 'WITH', 'order_item_promotion_adjustment.type = :order_item_promotion_type OR order_item_promotion_adjustment.type = :admin_order_item_promotion_type')
            ->leftJoin($elementAlias . '.adjustments', 'order_shipping_promotion_adjustment', 'WITH', 'order_shipping_promotion_adjustment.type = :order_shipping_promotion_type')
            ->leftJoin($elementAlias . '.adjustments', 'order_unit_promotion_adjustment', 'WITH', 'order_unit_promotion_adjustment.type = :order_unit_promotion_type')
            // Adjustments parameters
            ->setParameter('tax_type', AdjustmentInterface::TAX_ADJUSTMENT)
            ->setParameter('shipping_type', AdjustmentInterface::SHIPPING_ADJUSTMENT)
            ->setParameter('order_promotion_type', AdjustmentInterface::ORDER_PROMOTION_ADJUSTMENT)
            ->setParameter('admin_order_promotion_type', self::ADMIN_ORDER_DISCOUNT_ADJUSTMENT)
            ->setParameter('order_item_promotion_type', AdjustmentInterface::ORDER_ITEM_PROMOTION_ADJUSTMENT)
            ->setParameter('admin_order_item_promotion_type', self::ADMIN_ORDER_ITEM_DISCOUNT_ADJUSTMENT)
            ->setParameter('order_shipping_promotion_type', AdjustmentInterface::ORDER_SHIPPING_PROMOTION_ADJUSTMENT)
            ->setParameter('order_unit_promotion_type', AdjustmentInterface::ORDER_UNIT_PROMOTION_ADJUSTMENT)
            // Filters on orders in channel, which are paid, not refunded and completed between the wanted dates
            ->andWhere('o.channel = :channel')
            ->andWhere('o.state = :state')
            ->andWhere('o.paymentState != :payment_state')
            ->andWhere('o.checkoutCompletedAt BETWEEN :from AND :to')
            // Filters parameters
            ->setParameter('channel', $channel)
            ->setParameter('state', OrderInterface::STATE_FULFILLED)
            ->setParameter('payment_state', PaymentInterface::STATE_REFUNDED)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
        ;
    }

    /**
     * Populate result array with options and option values data
     *
     * @param string $localeCode
     * @return array
     */
    private function populateOptions(string $localeCode): array
    {
        $variantOptions = $this->getVariantsOptions($localeCode);
        $salesResults = [];

        foreach($this->results as $result) {
            $variantId = $result['variant_id'];
            $options = $variantOptions[$variantId];

            // Rename field with _total
            foreach ($result as $key => $value) {
                if (strpos($key, '_total')) {
                    $result[str_replace('_total', '', $key)] = $value;
                    unset($result[$key]);
                }
            }
            foreach ($options as $optionCode => $option) {
                $result['option_code'] = $optionCode;
                $result['option_label'] = $option['label'];
                $result['option_value_code'] = $option['value_code'];
                $result['option_value_label'] = $option['value_label'];
                $salesResults[] = $result;
            }
        }

        return $salesResults;
    }

    /**
     * Retrieve options for all variants and build an array
     *
     * @param string $localeCode
     * @return array
     */
    private function getVariantsOptions(string $localeCode): array
    {
        $queryBuilder = $this->productVariantRepository->createQueryBuilder('v')
            ->select('v.id AS variant_id, option.code AS option_code, option_translation.name AS option_label, option_value.code AS option_value_code, option_value_translation.value AS option_value_label')
            ->leftJoin('v.optionValues', 'option_value')
            ->leftJoin('option_value.translations', 'option_value_translation', 'WITH', 'option_value_translation.locale = :locale')
            ->leftJoin('option_value.option', 'option')
            ->leftJoin('option.translations', 'option_translation', 'WITH', 'option_translation.locale = :locale')
            ->setParameter('locale', $localeCode)
        ;

        $variantOptionsValues = [];

        $result = $queryBuilder->getQuery()->getArrayResult();
        foreach ($result as $variantOptionValue) {
            $variantOptionsValues[$variantOptionValue['variant_id']][$variantOptionValue['option_code']] = [
                'label' => $variantOptionValue['option_label'],
                'value_code' => $variantOptionValue['option_value_code'],
                'value_label' => $variantOptionValue['option_value_label'],
            ];
        }

        return $variantOptionsValues;
    }

    /**
     * Init the result with 0 totals
     */
    private function initResult()
    {
        $this->result = [
            'without_tax_total' => 0,
            'without_tax_promo_total' => 0,
            'without_tax_shipping_total' => 0,
            'tax_total' => 0,
            'total' => 0,
            'item_row_total' => 0,
        ];
        $this->elements = [];
    }

    /**
     * Increment results with given array
     *
     * @param array $elementResults
     * @param string|null $groupField
     */
    private function addResults(array $elementResults, ?string $groupField = null): void
    {
        // Loop on given elements to increments current result
        foreach ($elementResults as $elementResult) {
            foreach ($this->result as $key => $val) {
                if (strpos($key, 'total') !== false) {
                    // Get the field key, for example `without_tax_shipping` if we need to increment `without_tax_shipping_total`
                    $resultKey = str_replace('_total', '', $key);
                    if (isset($elementResult[$resultKey])) {
                        $this->result[$key] += (int) $elementResult[$resultKey]; // Cast in int because doctrine return string for columns with `+`, and we can have null values
                    }
                }
            }
            // Add group field value if we got one, for example, an order ID to have an average per order or a list per product variant
            if ($groupField !== null) {
                $this->elements[$elementResult[$groupField]] = $elementResult[$groupField];
            }
        }
    }

    /**
     * Make the sum of results by elements
     *
     * @param array $elementResults
     * @param string $groupField
     * @param string|null $labelField
     * @param array|null $extraFields
     */
    private function addResultsByElement(array $elementResults, string $groupField, ?string $labelField = null, ?array $extraFields = null): void
    {
        // Loop on given elements to increments current result
        foreach ($elementResults as $elementResult) {
            $elementId = $elementResult[$groupField];
            // Init results for this element or retrieve existing one
            if (!isset($this->results[$elementId])) {
                $this->initResult();
            } else {
                $this->result = $this->results[$elementId];
                $this->elements = $this->elementsArray[$elementId];
            }
            // Add results by order
            $this->addResults([$elementResult], 'order_id');
            $this->result['order_id'] = $elementResult['order_id'];

            // Add extra fields
            $this->result[$groupField] = $elementId; // Grouped field ID
            if ($labelField && isset($elementResult[$labelField]) && !empty($elementResult[$labelField])) {
                $this->result[$labelField] = $elementResult[$labelField]; // Grouped field label if given
            } elseif($labelField && (!isset($elementResult[$labelField]) || empty($elementResult[$labelField]))) {
                $this->result[$labelField] = '';
            }
            if (!empty($extraFields)) {
                foreach ($extraFields as $extraField) {
                    if (isset($elementResult[$extraField])) {
                        $this->result[$extraField] = $elementResult[$extraField];
                    } else {
                        $this->result[$extraField] = '';
                    }
                }
            }

            // Update results for this element
            $this->results[$elementId] = $this->result;
            $this->elementsArray[$elementId] = $this->elements;
        }

        // Aggregate number of order per element
        foreach ($this->results as $key => $value) {
            $this->results[$key]['number_of_elements'] = count($this->elementsArray[$key]);
        }
    }

    /**
     * Make the average of results depending on number of elements
     */
    private function averageResult(): void
    {
        if (!empty($this->elements)) {
            $numberOfElements = count($this->elements);
            foreach ($this->result as $key => $val) {
                $this->result[$key] = round($this->result[$key] / $numberOfElements);
            }
            $this->result['number_of_elements'] = count($this->elements);
        } else {
            $this->result['number_of_elements'] = 0;
        }
    }
}
