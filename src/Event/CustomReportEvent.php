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

namespace MonsieurBiz\SyliusSalesReportsPlugin\Event;

use MonsieurBiz\SyliusSalesReportsPlugin\Exception\AlreadyExistsReport;
use MonsieurBiz\SyliusSalesReportsPlugin\Exception\NotExistsReport;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Contracts\EventDispatcher\Event;

final class CustomReportEvent extends Event
{
    /**
     * @var array
     */
    private $customReports = [];

    /**
     * @var ChannelInterface
     */
    private $channel;

    /**
     * @var \DateTimeInterface
     */
    private $fromDate;

    /**
     * @var \DateTimeInterface|null
     */
    private $toDate;

    public function __construct(
        ChannelInterface $channel,
        \DateTimeInterface $fromDate,
        ?\DateTimeInterface $toDate = null
    ) {
        $this->channel = $channel;
        $this->fromDate = $fromDate;
        $this->toDate = $toDate;
    }

    /**
     * Retrieve custom reports, use a template override to display it.
     */
    public function getCustomReports(): array
    {
        return $this->customReports;
    }

    /**
     * Add a new custom report, you can't have reports with the same key.
     *
     * @throws AlreadyExistsReport
     */
    public function addReport(string $key, array $data): void
    {
        if (isset($this->customReports[$key])) {
            throw new AlreadyExistsReport(sprintf('Report "%s" already exists', $key));
        }
        $this->customReports[$key] = $data;
    }

    /**
     * Remove a custom report, you cannot remove a key which not exists.
     *
     * @throws NotExistsReport
     */
    public function removeReport(string $key): void
    {
        if (!isset($this->customReports[$key])) {
            throw new NotExistsReport(sprintf('Report "%s" does not exist', $key));
        }
        unset($this->customReports[$key]);
    }

    public function getChannel(): ChannelInterface
    {
        return $this->channel;
    }

    public function getFromDate(): \DateTimeInterface
    {
        return $this->fromDate;
    }

    public function getToDate(): ?\DateTimeInterface
    {
        return $this->toDate;
    }
}
