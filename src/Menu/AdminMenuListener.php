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

namespace MonsieurBiz\SyliusSalesReportsPlugin\Menu;

use Knp\Menu\ItemInterface;
use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

class AdminMenuListener
{
    /**
     * Add reports link in sales menu.
     */
    public function addAdminMenuItem(MenuBuilderEvent $event): void
    {
        $menu = $event->getMenu();

        $statisticsMenu = $menu
            ->addChild('monsieurbiz.statistics')
            ->setLabel('monsieurbiz.sales_reports.ui.statistics')
        ;

        if ($statisticsMenu instanceof ItemInterface) {
            $statisticsMenu
                ->addChild('monsieurbiz.sales_reports', ['route' => 'monsieurbiz_sylius_sales_reports_admin_index'])
                ->setLabel('monsieurbiz.sales_reports.ui.title')
                ->setLabelAttribute('icon', 'list alternate')
            ;
        }
    }
}
