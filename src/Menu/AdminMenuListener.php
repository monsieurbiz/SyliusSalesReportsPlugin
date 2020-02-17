<?php

namespace MonsieurBiz\SyliusSalesReportsPlugin\Menu;

use Knp\Menu\ItemInterface;
use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

class AdminMenuListener
{
    /**
     * Add reports link in sales menu
     *
     * @param MenuBuilderEvent $event
     */
    public function addAdminMenuItem(MenuBuilderEvent $event): void
    {
        $menu = $event->getMenu();

        $salesMenu = $menu->getChild('sales');

        if ($salesMenu instanceof ItemInterface) {
            $salesMenu
                ->addChild('monsieur_biz_sales_reports', ['route' => 'monsieur_biz_sylius_sales_reports_admin_index'])
                ->setLabel('monsieur_biz_sales_reports.ui.title')
                ->setLabelAttribute('icon', 'list alternate')
            ;
        }
    }
}
