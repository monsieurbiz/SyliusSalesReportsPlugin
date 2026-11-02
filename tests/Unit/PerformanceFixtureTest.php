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

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SalesReportsBenchmark\Fixtures;

require_once __DIR__ . '/../Performance/Fixtures.php';

final class PerformanceFixtureTest extends TestCase
{
    public function testOversizedFixturesAreRejectedBeforeDatabaseWrites(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('insert');
        $connection->expects(self::never())->method('executeStatement');
        $connection->expects(self::never())->method('beginTransaction');

        $this->expectException(InvalidArgumentException::class);
        Fixtures::load($connection, 50001);
    }
}
