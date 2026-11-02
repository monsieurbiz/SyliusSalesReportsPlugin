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

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use MonsieurBiz\SyliusSalesReportsPlugin\Repository\AbstractReportRepository;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

final class ReportVariantOptionsTest extends TestCase
{
    public function testEmptyAndDeletedVariantGroupsDoNotCreateAnOptionsQuery(): void
    {
        foreach ([[], [null => ['variant_id' => null, 'total' => 1200, 'tax_total' => 200]]] as $results) {
            $repository = $this->getMockBuilder(AbstractReportRepository::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['createProductVariantQuery'])
                ->getMock()
            ;
            $repository->expects(self::never())->method('createProductVariantQuery');
            (new ReflectionProperty(AbstractReportRepository::class, 'results'))->setValue($repository, $results);

            self::assertSame([], (new ReflectionMethod(AbstractReportRepository::class, 'getVariantsOptions'))->invoke($repository, 'fr_FR'));
            self::assertSame($results, (new ReflectionProperty(AbstractReportRepository::class, 'results'))->getValue($repository));
        }
    }

    public function testDeletedVariantIsNotBoundAndOptionMappingPreservesStoredTotals(): void
    {
        $rows = [
            ['variant_id' => 42, 'option_code' => 'color', 'option_label' => 'Couleur', 'option_value_code' => 'red', 'option_value_label' => 'Rouge'],
        ];
        $builder = $this->optionsQuery($rows, 2);
        $results = [
            null => ['variant_id' => null, 'total' => 1200, 'tax_total' => 200],
            42 => ['variant_id' => 42, 'total' => 600, 'tax_total' => 100],
        ];
        $repository = $this->repository($builder, $results);

        self::assertSame([42 => ['color' => ['label' => 'Couleur', 'value_code' => 'red', 'value_label' => 'Rouge']]], (new ReflectionMethod(AbstractReportRepository::class, 'getVariantsOptions'))->invoke($repository, 'fr_FR'));
        self::assertSame([42], $builder->getParameter('variant_ids')->getValue());
        self::assertSame('fr_FR', $builder->getParameter('locale')->getValue());
        self::assertSame([
            ['variant_id' => 42, 'total' => 600, 'tax' => 100, 'option_code' => 'color', 'option_label' => 'Couleur', 'option_value_code' => 'red', 'option_value_label' => 'Rouge'],
        ], (new ReflectionMethod(AbstractReportRepository::class, 'populateOptions'))->invoke($repository, 'fr_FR'));
        self::assertSame($results, (new ReflectionProperty(AbstractReportRepository::class, 'results'))->getValue($repository));
    }

    public function testOrdinaryVariantIdsIncludingZeroAreRetained(): void
    {
        $builder = $this->optionsQuery([], 1);
        $results = [0 => ['total' => 100], 42 => ['total' => 200], 73 => ['total' => 300]];
        $repository = $this->repository($builder, $results);

        self::assertSame([], (new ReflectionMethod(AbstractReportRepository::class, 'getVariantsOptions'))->invoke($repository, 'en_US'));
        self::assertSame([0, 42, 73], $builder->getParameter('variant_ids')->getValue());
        self::assertSame('en_US', $builder->getParameter('locale')->getValue());
        self::assertSame($results, (new ReflectionProperty(AbstractReportRepository::class, 'results'))->getValue($repository));
    }

    private function optionsQuery(array $rows, int $calls): QueryBuilder
    {
        $query = $this->createMock(Query::class);
        $query->method('setParameters')->willReturnSelf();
        $query->method('setFirstResult')->willReturnSelf();
        $query->method('setMaxResults')->willReturnSelf();
        $query->expects(self::exactly($calls))->method('getArrayResult')->willReturn($rows);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::exactly($calls))->method('createQuery')->willReturn($query);

        return (new QueryBuilder($manager))->from('Custom\\Variant', 'v');
    }

    private function repository(QueryBuilder $builder, array $results): AbstractReportRepository
    {
        $repository = $this->getMockBuilder(AbstractReportRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createProductVariantQuery'])
            ->getMock()
        ;
        $repository->method('createProductVariantQuery')->willReturn($builder);
        (new ReflectionProperty(AbstractReportRepository::class, 'results'))->setValue($repository, $results);

        return $repository;
    }
}
