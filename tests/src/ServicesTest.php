<?php

declare(strict_types=1);

/**
 * Derafu: Symfony Query Bundle - Integrates derafu/query with Symfony.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsQueryBundle;

use Derafu\Array\Exception\TypeMismatchException;
use Derafu\Query\Bridge\ApiPlatform\SmartFilter;
use Derafu\Query\Bridge\Contract\QueryBuilderConditionApplierInterface;
use Derafu\Query\Bridge\DoctrineORMQueryBuilderConditionApplier;
use Derafu\Query\Filter\Contract\CompositeExpressionParserInterface;
use Derafu\Query\Filter\Contract\ExpressionParserInterface;
use Derafu\Query\Filter\Contract\FilterParserInterface;
use Derafu\Query\Operator\Contract\OperatorManagerInterface;
use Derafu\Query\Operator\OperatorManagerFactory;
use Derafu\QueryBundle\QueryBundle;
use Derafu\TestsQueryBundle\Fixture\Entity\Product;
use Derafu\TestsQueryBundle\Fixture\Kernel;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Translation\Translator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests real configuration, service construction and execution in a kernel.
 */
#[CoversClass(QueryBundle::class)]
final class ServicesTest extends TestCase
{
    private string $project;

    private ?Kernel $kernel = null;

    private ?EntityManagerInterface $manager = null;

    /** @var array<array-key, mixed> */
    private array $environment;

    /** @var array<array-key, mixed> */
    private array $server;

    private string|false $shellVerbosity;

    protected function setUp(): void
    {
        $this->environment = $_ENV;
        $this->server = $_SERVER;
        $this->shellVerbosity = getenv('SHELL_VERBOSITY');
        $this->project = sys_get_temp_dir() . '/derafu-query-bundle-' . bin2hex(random_bytes(8));
        mkdir($this->project);
    }

    protected function tearDown(): void
    {
        try {
            $this->manager?->getConnection()->close();
            $this->kernel?->shutdown();
            (new Filesystem())->remove($this->project);
        } finally {
            $_ENV = $this->environment;
            $_SERVER = $this->server;
            putenv($this->shellVerbosity === false ? 'SHELL_VERBOSITY' : 'SHELL_VERBOSITY=' . $this->shellVerbosity);
        }
    }

    /** @param array<string, mixed> $config */
    private function boot(array $config = []): void
    {
        $this->kernel = new Kernel($this->project, $config);
        $this->kernel->boot();
    }

    private function container(): Container
    {
        self::assertNotNull($this->kernel);
        $container = $this->kernel->getContainer();
        self::assertInstanceOf(Container::class, $container);

        return $container;
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    private function service(string $id): object
    {
        $service = $this->container()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    private function seed(): EntityManagerInterface
    {
        $manager = $this->container()->get('test.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
        (new SchemaTool($manager))->createSchema([$manager->getClassMetadata(Product::class)]);
        foreach ([
            [1, 'Notebook', 120, 'active'],
            [2, 'Mouse', 25, 'active'],
            [3, 'Desk', 250, 'archived'],
            [4, 'Lamp', 80, 'active'],
        ] as [$id, $name, $price, $status]) {
            $manager->persist(new Product($id, $name, $price, $status));
        }
        $manager->flush();
        $manager->clear();

        return $manager;
    }

    private function query(EntityManagerInterface $manager): QueryBuilder
    {
        return $manager->createQueryBuilder()->select('p.id')->from(Product::class, 'p')->orderBy('p.id');
    }

    public function testTheBundleRetainsItsPathAndPublicConfigurationAlias(): void
    {
        $bundle = new QueryBundle();
        self::assertSame(dirname(__DIR__, 2), $bundle->getPath());
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);
        self::assertSame('derafu_query', $extension->getAlias());
        self::assertSame($extension, $bundle->getContainerExtension());
    }

    public function testEveryImportedPackageServiceCanBeConstructed(): void
    {
        $this->boot();
        $container = $this->container();
        foreach ($container->getServiceIds() as $id) {
            if (str_starts_with($id, 'Derafu\\')) {
                $container->get($id);
            }
        }
        self::assertInstanceOf(DoctrineORMQueryBuilderConditionApplier::class, $this->service(QueryBuilderConditionApplierInterface::class));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function defaultConfigurations(): iterable
    {
        yield 'omitted' => [[]];
        yield 'explicit null' => [['operators_path' => null]];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('defaultConfigurations')]
    public function testDefaultOperatorsAreAvailableWithOmittedOrNullPaths(array $config): void
    {
        $this->boot($config);
        self::assertSame(OperatorManagerFactory::DEFAULT_OPERATORS_PATH, $this->container()->getParameter('derafu.query.operators.path'));
        self::assertSame('=', $this->service(OperatorManagerInterface::class)->getOperator('=')->getSymbol());
        $condition = $this->service(ExpressionParserInterface::class)->parse('price?>100');
        self::assertSame('price', $condition->getPath()->getLastSegment()->getName());
        self::assertSame('100', $condition->getFilter()->getValue());
    }

    public function testCustomOperatorsAreUsedByTheParsersAndOrmBridge(): void
    {
        $path = dirname(__DIR__) . '/fixtures/operators.yaml';
        $this->boot(['operators_path' => $path]);
        self::assertSame($path, $this->container()->getParameter('derafu.query.operators.path'));
        self::assertSame(['eq:'], array_keys($this->service(OperatorManagerInterface::class)->getOperators()));
        $query = $this->query($this->seed());
        $condition = $this->service(ExpressionParserInterface::class)->parse('name?eq:Notebook');
        $this->service(QueryBuilderConditionApplierInterface::class)->apply($query, $condition);
        self::assertSame([['id' => 1]], $query->getQuery()->getScalarResult());
    }

    public function testCompositeFiltersExecuteOnARealSqliteDatabaseIndependentlyOfApiPlatform(): void
    {
        $this->boot();
        $query = $this->query($this->seed());
        $condition = $this->service(CompositeExpressionParserInterface::class)->parse('price?>100 && status?=active');
        $this->service(QueryBuilderConditionApplierInterface::class)->apply($query, $condition);
        self::assertSame([['id' => 1]], $query->getQuery()->getScalarResult());
    }

    public function testCustomOperatorPathsResolveApplicationParameters(): void
    {
        $path = $this->project . '/operators.yaml';
        (new Filesystem())->copy(dirname(__DIR__) . '/fixtures/operators.yaml', $path);
        $this->boot(['operators_path' => '%kernel.project_dir%/operators.yaml']);
        self::assertSame(realpath($path), $this->container()->getParameter('derafu.query.operators.path'));
        $query = $this->query($this->seed());
        $condition = $this->service(ExpressionParserInterface::class)->parse('name?eq:Notebook');
        $this->service(QueryBuilderConditionApplierInterface::class)->apply($query, $condition);
        self::assertSame([['id' => 1]], $query->getQuery()->getScalarResult());
    }

    public function testAppliedFiltersRetainTheApplicationsExistingRestrictions(): void
    {
        $this->boot();
        $query = $this->query($this->seed())->where('p.status = :visibility')->setParameter('visibility', 'active');
        $condition = $this->service(CompositeExpressionParserInterface::class)->parse('price?>100 || price?<30');
        $this->service(QueryBuilderConditionApplierInterface::class)->apply($query, $condition);
        self::assertSame([['id' => 1], ['id' => 2]], $query->getQuery()->getScalarResult());
        self::assertSame('active', $query->getParameter('visibility')?->getValue());
    }

    public function testFilterValuesRemainBoundParameters(): void
    {
        $this->boot();
        $query = $this->query($this->seed());
        $value = "Notebook' OR 1=1 --";
        $condition = $this->service(ExpressionParserInterface::class)->parse('name?=' . $value);
        $this->service(QueryBuilderConditionApplierInterface::class)->apply($query, $condition);
        self::assertSame([], $query->getQuery()->getScalarResult());
        self::assertStringNotContainsString($value, $query->getDQL());
    }

    public function testQueryTranslationTagsReachTheNativeSymfonyTranslator(): void
    {
        $this->boot();
        $translator = $this->container()->get('test.translator');
        self::assertInstanceOf(Translator::class, $translator);
        try {
            $this->service(FilterParserInterface::class)->parse('unsupported');
            self::fail('A filter without an operator was accepted.');
        } catch (TranslatableInvalidArgumentException $exception) {
            self::assertSame('No se encontró un operador válido en la expresión: unsupported', $exception->trans($translator));
        }
    }

    public function testSmartFilterIsRegisteredOnlyWhenApiPlatformIsAvailable(): void
    {
        $this->boot();
        if (interface_exists('ApiPlatform\\Doctrine\\Orm\\Filter\\FilterInterface')) {
            $this->service(SmartFilter::class);
        } else {
            self::assertFalse($this->container()->has(SmartFilter::class));
        }
        $this->service(QueryBuilderConditionApplierInterface::class);
    }

    /** @return iterable<string, array{int|bool}> */
    public static function invalidScalarPaths(): iterable
    {
        yield 'integer' => [12];
        yield 'boolean' => [false];
    }

    #[DataProvider('invalidScalarPaths')]
    public function testWrongScalarPathTypesAreRejected(int|bool $path): void
    {
        $this->expectException(TypeMismatchException::class);
        $this->boot(['operators_path' => $path]);
    }

    public function testUnknownConfigurationOptionsAreRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->boot(['unknown_option' => true]);
    }

    public function testMissingOperatorFilesReportTheOriginalPackageException(): void
    {
        $this->expectException(TranslatableInvalidArgumentException::class);
        $this->expectExceptionMessage('Configuration file not found');
        $this->boot(['operators_path' => $this->project . '/missing.yaml']);
        $this->service(OperatorManagerInterface::class);
    }

    public function testApiPlatformConsumesTheSmartFilterTagForHttpCollectionFiltering(): void
    {
        if (!interface_exists('ApiPlatform\\Doctrine\\Orm\\Filter\\FilterInterface')) {
            self::markTestSkipped('API Platform is optional and is not installed.');
        }
        $this->boot();
        $this->seed();
        $locator = $this->container()->get('test.filter_locator');
        self::assertInstanceOf(ContainerInterface::class, $locator);
        self::assertTrue($locator->has(SmartFilter::class));
        self::assertSame($this->service(SmartFilter::class), $locator->get(SmartFilter::class));
        self::assertNotNull($this->kernel);
        $request = Request::create('/api/products', 'GET', ['price' => '>100', 'status' => '=active'], server: ['HTTP_ACCEPT' => 'application/json']);
        $response = $this->kernel->handle($request);
        $content = $response->getContent();
        self::assertIsString($content);
        self::assertSame(200, $response->getStatusCode(), $content);
        self::assertSame([
            ['id' => 1, 'name' => 'Notebook', 'price' => 120, 'status' => 'active'],
        ], json_decode($content, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testApiPlatformAppliesFullCompositeExpressionsThroughTheRegisteredFilter(): void
    {
        if (!interface_exists('ApiPlatform\\Doctrine\\Orm\\Filter\\FilterInterface')) {
            self::markTestSkipped('API Platform is optional and is not installed.');
        }
        $this->boot();
        $this->seed();
        self::assertNotNull($this->kernel);
        $request = Request::create('/api/products', 'GET', ['filter' => 'status?=active&&(price?>100||price?<30)'], server: ['HTTP_ACCEPT' => 'application/json']);
        $response = $this->kernel->handle($request);
        $content = $response->getContent();
        self::assertIsString($content);
        self::assertSame(200, $response->getStatusCode(), $content);
        self::assertSame([
            ['id' => 1, 'name' => 'Notebook', 'price' => 120, 'status' => 'active'],
            ['id' => 2, 'name' => 'Mouse', 'price' => 25, 'status' => 'active'],
        ], json_decode($content, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testApiPlatformUsesTheSameCustomOperatorCatalogue(): void
    {
        if (!interface_exists('ApiPlatform\\Doctrine\\Orm\\Filter\\FilterInterface')) {
            self::markTestSkipped('API Platform is optional and is not installed.');
        }
        $this->boot(['operators_path' => dirname(__DIR__) . '/fixtures/operators.yaml']);
        $this->seed();
        self::assertNotNull($this->kernel);
        $request = Request::create('/api/products', 'GET', ['price' => 'eq:120'], server: ['HTTP_ACCEPT' => 'application/json']);
        $response = $this->kernel->handle($request);
        $content = $response->getContent();
        self::assertIsString($content);
        self::assertSame(200, $response->getStatusCode(), $content);
        self::assertSame([
            ['id' => 1, 'name' => 'Notebook', 'price' => 120, 'status' => 'active'],
        ], json_decode($content, true, flags: JSON_THROW_ON_ERROR));
    }
}
