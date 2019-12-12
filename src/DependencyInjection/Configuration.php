<?php

declare(strict_types=1);

namespace MonsieurBiz\Sylius__YourName__Plugin\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(MonsieurBizSylius__YourName_Extension::EXTENSION_CONFIG_NAME);
        if (\method_exists($treeBuilder, 'getRootNode')) {
            $rootNode = $treeBuilder->getRootNode();
        } else {
            // BC layer for symfony/config 4.1 and older
            $rootNode = $treeBuilder->root(MonsieurBizSylius__YourName_Extension::EXTENSION_CONFIG_NAME);
        }

        return $treeBuilder;
    }
}
