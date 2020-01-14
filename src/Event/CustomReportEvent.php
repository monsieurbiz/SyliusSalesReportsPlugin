<?php

declare(strict_types=1);

namespace MonsieurBiz\SyliusSalesReportsPlugin\Event;

use MonsieurBiz\SyliusSalesReportsPlugin\Exception\AlreadyExistsReport;
use MonsieurBiz\SyliusSalesReportsPlugin\Exception\NotExistsReport;
use Symfony\Component\EventDispatcher\Event;

final class CustomReportEvent extends Event
{
    /**
     * @var array
     */
    private $customReports = [];

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
}
