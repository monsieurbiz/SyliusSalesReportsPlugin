<?php

declare(strict_types=1);

namespace MonsieurBiz\Sylius__YourName__Plugin\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

final class MonsieurBizSylius__YourName_Extension extends Extension
{

    CONST EXTENSION_CONFIG_NAME = 'monsieur_biz_sylius___your_name__';

    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);
        foreach ($config as $name => $value) {
            $container->setParameter(self::EXTENSION_CONFIG_NAME . '.' . $name, $value);
        }
    }
}
