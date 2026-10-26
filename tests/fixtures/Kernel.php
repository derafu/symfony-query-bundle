<?php

declare(strict_types=1);

/**
 * Derafu: Symfony Query Bundle - Integrates derafu/query with Symfony.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsQueryBundle\Fixture;

use ApiPlatform\Symfony\Bundle\ApiPlatformBundle;
use Derafu\QueryBundle\QueryBundle;
use Derafu\TranslationBundle\TranslationBundle;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * A real Symfony application with Doctrine ORM and native translations.
 */
final class Kernel extends BaseKernel
{
    /** @param array<string, mixed> $queryConfig */
    public function __construct(private readonly string $project, private readonly array $queryConfig = [])
    {
        parent::__construct('test', true);
    }

    /** @return iterable<BundleInterface> */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new TranslationBundle();
        yield new QueryBundle();
        if (class_exists(ApiPlatformBundle::class)) {
            yield new ApiPlatformBundle();
        }
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $framework = [
                'secret' => 'query-bundle-test-secret',
                'default_locale' => 'es',
                'translator' => ['fallbacks' => ['en']],
            ];
            if (class_exists(ApiPlatformBundle::class)) {
                $framework['serializer'] = ['enabled' => true];
                $framework['property_info'] = ['enabled' => true];
                $framework['property_access'] = ['enabled' => true];
                $framework['validation'] = ['enabled' => true];
                $framework['router'] = ['resource' => __DIR__ . '/routes.yaml'];
                $container->loadFromExtension('api_platform', [
                    'mapping' => ['paths' => [__DIR__ . '/api']],
                    'formats' => ['json' => ['application/json']],
                    'defaults' => ['pagination_enabled' => false],
                    'enable_swagger_ui' => false,
                    'enable_re_doc' => false,
                    'enable_docs' => false,
                    'enable_entrypoint' => false,
                ]);
                $container->setAlias('test.filter_locator', 'api_platform.filter_locator')->setPublic(true);
            }
            $container->loadFromExtension('framework', $framework);
            $container->loadFromExtension('doctrine', [
                'dbal' => ['driver' => 'pdo_sqlite', 'memory' => true, 'logging' => false, 'profiling' => false],
                'orm' => ['mappings' => ['QueryFixture' => [
                    'type' => 'attribute',
                    'dir' => __DIR__ . '/Entity',
                    'prefix' => 'Derafu\\TestsQueryBundle\\Fixture\\Entity',
                    'is_bundle' => false,
                ]]],
            ]);
            $container->loadFromExtension('derafu_query', $this->queryConfig);
            $container->register('test.logger', NullLogger::class);
            $container->setAlias('logger', 'test.logger');
            $container->setAlias('test.entity_manager', EntityManagerInterface::class)->setPublic(true);
            $container->setAlias('test.translator', 'translator')->setPublic(true);
            // Keep package services reachable so compilation cannot remove a
            // broken definition merely because a test did not request it yet.
            $container->addCompilerPass(new class () implements CompilerPassInterface {
                public function process(ContainerBuilder $container): void
                {
                    foreach ($container->getDefinitions() as $id => $definition) {
                        if (str_starts_with($id, 'Derafu\\')) {
                            $definition->setPublic(true);
                        }
                    }
                    foreach ($container->getAliases() as $id => $alias) {
                        if (str_starts_with($id, 'Derafu\\')) {
                            $alias->setPublic(true);
                        }
                    }
                }
            });
        });
    }

    public function getProjectDir(): string
    {
        return $this->project;
    }

    public function getCacheDir(): string
    {
        return $this->project . '/var/cache';
    }

    public function getLogDir(): string
    {
        return $this->project . '/var/log';
    }
}
