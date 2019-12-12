<?php

namespace MonsieurBiz\SyliusSalesReportsPlugin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

class AdminMenuListener
{
    /**
     * @param MenuBuilderEvent $event
     */
    public function addAdminMenuItem(MenuBuilderEvent $event): void
    {
        $menu = $event->getMenu();

        $menu
            ->getChild('sales')
            ->addChild('monsieur_biz_sales_reports', ['route' => 'monsieur_biz_sylius_sales_reports_admin_index'])
            ->setLabel('monsieur_biz_sales_reports.ui.title')
            ->setLabelAttribute('icon', 'list alternate')
        ;
    }
}
