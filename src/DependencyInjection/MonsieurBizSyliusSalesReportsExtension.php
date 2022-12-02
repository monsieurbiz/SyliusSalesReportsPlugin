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

namespace MonsieurBiz\SyliusSalesReportsPlugin\DependencyInjection;

use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class MonsieurBizSyliusSalesReportsExtension extends Extension
{
    public const EXTENSION_CONFIG_NAME = 'monsieurbiz_sylius_sales_reports';

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = $this->getConfiguration([], $container);
        if ($configuration instanceof ConfigurationInterface) {
            $config = $this->processConfiguration($configuration, $configs);
            foreach ($config as $name => $value) {
                $container->setParameter(self::EXTENSION_CONFIG_NAME . '.' . $name, $value);
            }
        }

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
    }

    public function getAlias(): string
    {
        return str_replace('monsieur_biz', 'monsieurbiz', parent::getAlias());
    }
}
