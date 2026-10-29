<?php

declare(strict_types=1);

/**
 * Derafu: Symfony Query Bundle - Integrates derafu/query with Symfony.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\QueryBundle;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use Composer\InstalledVersions;
use Derafu\Array\ArrayReader;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Integrates Query's operators, parsers and bridges with Symfony services.
 */
final class QueryBundle extends AbstractBundle
{
    protected string $extensionAlias = 'derafu_query';

    /**
     * {@inheritDoc}
     */
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('operators_path')
                    ->info('Path to an operators YAML file. Defaults to the file supplied by derafu/query.')
                    ->defaultNull()
                ->end()
            ->end()
        ;
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $packagePath = InstalledVersions::getInstallPath('derafu/query');
        $configurator->import($packagePath . '/resources/config/query-bridge-doctrine-orm-services.yaml');

        // Apply the application's override after importing Query's defaults.
        $operatorsPath = ArrayReader::fromArray($config)->getString('operators_path');
        if ($operatorsPath !== null) {
            $container->setParameter('derafu.query.operators.path', $operatorsPath);
        }
        if (interface_exists(FilterInterface::class)) {
            $configurator->import('../resources/config/symfony-query-bundle-services.yaml');
        }
    }
}
