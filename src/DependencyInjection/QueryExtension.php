<?php

declare(strict_types=1);

/**
 * Derafu: Symfony Query Bundle - Integrates derafu/query with Symfony.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\QueryBundle\DependencyInjection;

use Derafu\Query\Operator\OperatorLoader;
use ReflectionClass;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * DI extension for the Query bundle.
 */
final class QueryExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        if ($config['operators_path'] === null) {
            $ref = new ReflectionClass(OperatorLoader::class);
            $config['operators_path'] = dirname($ref->getFileName(), 3) . '/resources/operators.yaml';
        }

        $container->setParameter('derafu_query.operators_path', $config['operators_path']);

        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../../config')
        );
        $loader->load('services.yaml');
    }

    public function getAlias(): string
    {
        return 'derafu_query';
    }
}
