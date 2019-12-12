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

final class ReportRepository
{
    /**
     * @var RepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var array
     */
    protected $result;

    /**
     * ReportRepository constructor.
     * @param RepositoryInterface $orderRepository
     */
    public function __construct(RepositoryInterface $orderRepository)
    {
        $this->orderRepository = $orderRepository;
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
        ];
    }

    /**
     * Increment results with given array
     *
     * @param $elementResults
     */
    private function addResults($elementResults)
    {
        foreach ($elementResults as $elementResult) {
            foreach($this->result as $key => $val) {
                $resultKey = str_replace('_total', '', $key);
                if (isset($elementResult[$resultKey])) {
                    $this->result[$key] += (int) $elementResult[$resultKey]; // Cast in int because doctrine return string for columns with `+`, and we can have null values
                }
            }
        }
    }

    /**
     * Get total sales for channel between 2 date times
     *
     * @param ChannelInterface $channel
     * @param \DateTimeInterface $from
     * @param \DateTimeInterface|null $to
     * @return array
     * @throws InvalidDateException
     */
    public function getSalesForChannelForDates(
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

        $this->initResult();

        // Order Item Units values
        $this->addResults($this->getOrderItemUnitValues($channel, $from, $to));
        // Order Items values
        $this->addResults($this->getOrderItemValues($channel, $from, $to));
        // Order values
        $this->addResults($this->getOrderValues($channel, $from, $to));

        return $this->result;
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
            ->select($this->getSelectColumns(true))
            ->leftJoin('o.items', 'item')
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
            ->select($this->getSelectColumns())
            ->leftJoin('o.items', 'element')
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
        $queryBuilder = $this->orderRepository->createQueryBuilder('o')->select($this->getSelectColumns(false, true));
        $queryBuilder = $this->appendAdjustmentsAndParameters($queryBuilder, $channel, $from, $to, true);
        return $queryBuilder->getQuery()->getArrayResult();
    }

    /**
     * Retrieve columns for select. We retrieve without tax value only for items units
     * All others columns are promotions, shipping, tax amounts
     *
     * @param bool $isItemUnit
     * @param bool $isOrder
     * @return string
     */
    private function getSelectColumns($isItemUnit = false, $isOrder = false): string
    {
        return implode(',',[
            $isItemUnit ? 'item.unitPrice as without_tax' : '0 as without_tax', // Only retrieve without_tax price for item units
            '(COALESCE(order_promotion_adjustment.amount, 0) + COALESCE(order_item_promotion_adjustment.amount, 0) + COALESCE(order_shipping_promotion_adjustment.amount, 0) + COALESCE(order_unit_promotion_adjustment.amount, 0)) AS without_tax_promo',
            'shipping_adjustment.amount as without_tax_shipping',
            'tax_adjustment.amount as tax',
            $isOrder ? 'o.total as total' : '0 as total'
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
        $isOrder = false
    ) {
        $elementAlias = $isOrder ? 'o' : 'element';
        return $queryBuilder
            // Adjustments joins
            ->leftJoin($elementAlias . '.adjustments', 'tax_adjustment', 'WITH', 'tax_adjustment.type = :tax_type')
            ->leftJoin($elementAlias . '.adjustments', 'shipping_adjustment', 'WITH', 'shipping_adjustment.type = :shipping_type')
            ->leftJoin($elementAlias . '.adjustments', 'order_promotion_adjustment', 'WITH', 'order_promotion_adjustment.type = :order_promotion_type')
            ->leftJoin($elementAlias . '.adjustments', 'order_item_promotion_adjustment', 'WITH', 'order_item_promotion_adjustment.type = :order_item_promotion_type')
            ->leftJoin($elementAlias . '.adjustments', 'order_shipping_promotion_adjustment', 'WITH', 'order_shipping_promotion_adjustment.type = :order_shipping_promotion_type')
            ->leftJoin($elementAlias . '.adjustments', 'order_unit_promotion_adjustment', 'WITH', 'order_unit_promotion_adjustment.type = :order_unit_promotion_type')
            // Adjustments parameters
            ->setParameter('tax_type', AdjustmentInterface::TAX_ADJUSTMENT)
            ->setParameter('shipping_type', AdjustmentInterface::SHIPPING_ADJUSTMENT)
            ->setParameter('order_promotion_type', AdjustmentInterface::ORDER_PROMOTION_ADJUSTMENT)
            ->setParameter('order_item_promotion_type', AdjustmentInterface::ORDER_ITEM_PROMOTION_ADJUSTMENT)
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
}
