<?php

declare(strict_types=1);

namespace MonsieurBiz\SyliusSalesReportsPlugin\Event;

use Symfony\Contracts\EventDispatcher\Event;
use Sylius\Component\Core\Model\ChannelInterface;
use MonsieurBiz\SyliusSalesReportsPlugin\Exception\NotExistsReport;
use MonsieurBiz\SyliusSalesReportsPlugin\Exception\AlreadyExistsReport;

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
     * Retrieve custom reports, use a template override to display it
     *
     * @return array
     */
    public function getCustomReports(): array
    {
        return $this->customReports;
    }

    /**
     * Add a new custom report, you can't have reports with the same key
     *
     * @param string $key
     * @param array $data
     * @throws AlreadyExistsReport
     */
    public function addReport(string $key, array $data)
    {
        if (isset($this->customReports[$key])) {
            throw new AlreadyExistsReport(sprintf('Report "%s" already exists', $key));
        }
        $this->customReports[$key] = $data;
    }

    /**
     * Remove a custom report, you cannot remove a key which not exists
     *
     * @param string $key
     * @throws NotExistsReport
     */
    public function removeReport(string $key)
    {
        if (!isset($this->customReports[$key])) {
            throw new NotExistsReport(sprintf('Report "%s" does not exist', $key));
        }
        unset($this->customReports[$key]);
    }

    /**
     * @return ChannelInterface
     */
    public function getChannel(): ChannelInterface
    {
        return $this->channel;
    }

    /**
     * @return \DateTimeInterface
     */
    public function getFromDate(): \DateTimeInterface
    {
        return $this->fromDate;
    }

    /**
     * @return \DateTimeInterface|null
     */
    public function getToDate(): ?\DateTimeInterface
    {
        return $this->toDate;
    }
}
