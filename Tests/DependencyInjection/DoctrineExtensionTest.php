<?php

namespace Doctrine\Bundle\DoctrineBundle\Tests\DependencyInjection;

use Closure;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\Bundle\DoctrineBundle\CacheWarmer\DoctrineMetadataCacheWarmer;
use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\CacheCompatibilityPass;
use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use Doctrine\Bundle\DoctrineBundle\Tests\Builder\BundleConfigurationBuilder;
use Doctrine\Bundle\DoctrineBundle\Tests\DependencyInjection\Fixtures\Php8EntityListener;
use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Cache\Psr6\DoctrineProvider;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Sharding\PoolingShardManager;
use Doctrine\DBAL\Sharding\SQLAzure\SQLAzureShardManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Bridge\Doctrine\Messenger\DoctrineClearEntityManagerWorkerSubscriber;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\PhpArrayAdapter;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\ResolveChildDefinitionsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\MessageBusInterface;

use function array_values;
use function class_exists;
use function interface_exists;
use function method_exists;
use function sprintf;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

class DoctrineExtensionTest extends TestCase
{
    /**
     * https://github.com/doctrine/orm/pull/7953 needed, otherwise ORM classes we define services for trigger deprecations
     *
     * @group legacy
     */
    public function testAutowiringAlias(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->getContainer();
        $extension = new DoctrineExtension();
        $config    = BundleConfigurationBuilder::createBuilderWithBaseValues()->build();

        $extension->load([$config], $container);

        $expectedAliases = [
            DriverConnection::class => 'database_connection',
            Connection::class => 'database_connection',
            EntityManagerInterface::class => 'doctrine.orm.entity_manager',
        ];

        foreach ($expectedAliases as $id => $target) {
            $this->assertTrue($container->hasAlias($id), sprintf('The container should have a `%s` alias for autowiring support.', $id));

            $alias = $container->getAlias($id);
            $this->assertEquals($target, (string) $alias, sprintf('The autowiring for `%s` should use `%s`.', $id, $target));
            $this->assertFalse($alias->isPublic(), sprintf('The autowiring alias for `%s` should be private.', $id));
        }
    }

    public function testConnectionAutowiringAlias()
    {
        $container = $this->getContainer();
        $extension = new DoctrineExtension();

        $config = BundleConfigurationBuilder::createBuilder()
            ->addConnection([
                'connections' => [
                    'default' => ['password' => 'foo'],
                    'purchase_logs' => ['password' => 'foo'],
                ],
            ])
            ->build();

        $extension->load([$config], $container);

        $expectedAliases = [
            Connection::class . ' $defaultConnection' => 'doctrine.dbal.default_connection',
            Connection::class . ' $purchaseLogsConnection' => 'doctrine.dbal.purchase_logs_connection',
        ];

        foreach ($expectedAliases as $id => $target) {
            $this->assertTrue($container->hasAlias($id), sprintf('The container should have a `%s` alias for autowiring support.', $id));

            $alias = $container->getAlias($id);
            $this->assertEquals($target, (string) $alias, sprintf('The autowiring for `%s` should use `%s`.', $id, $target));
            $this->assertFalse($alias->isPublic(), sprintf('The autowiring alias for `%s` should be private.', $id));
        }
    }

    public function testEntityManagerAutowiringAlias()
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->getContainer([
            'YamlBundle',
            'XmlBundle',
        ]);
        $extension = new DoctrineExtension();

        $config = BundleConfigurationBuilder::createBuilder()
            ->addBaseConnection()
            ->addEntityManager([
                'entity_managers' => [
                    'default' => [
                        'mappings' => ['YamlBundle' => []],
                    ],
                    'purchase_logs' => [
                        'mappings' => ['XmlBundle' => []],
                    ],
                ],
            ])
            ->build();

        $extension->load([$config], $container);

        $expectedAliases = [
            EntityManagerInterface::class . ' $defaultEntityManager' => 'doctrine.orm.default_entity_manager',
            EntityManagerInterface::class . ' $purchaseLogsEntityManager' => 'doctrine.orm.purchase_logs_entity_manager',
        ];

        foreach ($expectedAliases as $id => $target) {
            $this->assertTrue($container->hasAlias($id), sprintf('The container should have a `%s` alias for autowiring support.', $id));

            $alias = $container->getAlias($id);
            $this->assertEquals($target, (string) $alias, sprintf('The autowiring for `%s` should use `%s`.', $id, $target));
            $this->assertFalse($alias->isPublic(), sprintf('The autowiring alias for `%s` should be private.', $id));
        }
    }

    public function testPublicServicesAndAliases(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->getContainer();
        $extension = new DoctrineExtension();
        $config    = BundleConfigurationBuilder::createBuilderWithBaseValues()->build();

        $extension->load([$config], $container);

        $this->assertTrue($container->getDefinition('doctrine')->isPublic());
        $this->assertTrue($container->getAlias('doctrine.orm.entity_manager')->isPublic());
        $this->assertTrue($container->getAlias('database_connection')->isPublic());
    }

    public function testDbalGenerateDefaultConnectionConfiguration(): void
    {
        $container = $this->getContainer();
        $extension = new DoctrineExtension();

        $container->registerExtension($extension);

        $extension->load([['dbal' => []]], $container);

        // doctrine.dbal.default_connection
        $this->assertEquals('%doctrine.default_connection%', $container->getDefinition('doctrine')->getArgument(3));
        $this->assertEquals('default', $container->getParameter('doctrine.default_connection'));
        $this->assertEquals('root', $container->getDefinition('doctrine.dbal.default_connection')->getArgument(0)['user']);
        $this->assertNull($container->getDefinition('doctrine.dbal.default_connection')->getArgument(0)['password']);
        $this->assertEquals('localhost', $container->getDefinition('doctrine.dbal.default_connection')->getArgument(0)['host']);
        $this->assertNull($container->getDefinition('doctrine.dbal.default_connection')->getArgument(0)['port']);
        $this->assertEquals('pdo_mysql', $container->getDefinition('doctrine.dbal.default_connection')->getArgument(0)['driver']);
        $this->assertEquals([], $container->getDefinition('doctrine.dbal.default_connection')->getArgument(0)['driverOptions']);
    }

    public function testDbalOverrideDefaultConnection(): void
    {
        $container = $this->getContainer();
        $extension = new DoctrineExtension();

        $container->registerExtension($extension);

        $extension->load([[], ['dbal' => ['default_connection' => 'foo']], []], $container);

        // doctrine.dbal.default_connection
        $this->assertEquals('%doctrine.default_connection%', $container->getDefinition('doctrine')->getArgument(3), '->load() overrides existing configuration options');
        $this->assertEquals('foo', $container->getParameter('doctrine.default_connection'), '->load() overrides existing configuration options');
    }

    public function testOrmRequiresDbal(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $extension = new DoctrineExtension();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Configuring the ORM layer requires to configure the DBAL layer as well.'
        );
        $extension->load([['orm' => ['auto_mapping' => true]]], $this->getContainer());
    }

    /** @return mixed[][][][] */
    public function getAutomappingConfigurations(): array
    {
        return [
            [
                [
                    'em1' => [
                        'mappings' => ['YamlBundle' => null],
                    ],
                    'em2' => [
                        'mappings' => ['XmlBundle' => null],
                    ],
                ],
            ],
            [
                [
                    'em1' => ['auto_mapping' => true],
                    'em2' => [
                        'mappings' => ['XmlBundle' => null],
                    ],
                ],
            ],
            [
                [
                    'em1' => [
                        'auto_mapping' => true,
                        'mappings' => ['YamlBundle' => null],
                    ],
                    'em2' => [
                        'mappings' => ['XmlBundle' => null],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param mixed[][][][] $entityManagers
     *
     * @dataProvider getAutomappingConfigurations
     */
    public function testAutomapping(array $entityManagers): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $extension = new DoctrineExtension();

        $container = $this->getContainer([
            'YamlBundle',
            'XmlBundle',
        ]);

        $extension->load(
            [
                [
                    'dbal' => [
                        'default_connection' => 'cn1',
                        'connections' => [
                            'cn1' => [],
                            'cn2' => [],
                        ],
                    ],
                    'orm' => ['entity_managers' => $entityManagers],
                ],
            ],
            $container
        );

        $configEm1 = $container->getDefinition('doctrine.orm.em1_configuration');
        $configEm2 = $container->getDefinition('doctrine.orm.em2_configuration');

        $this->assertContains(
            [
                'setEntityNamespaces',
                [
                    ['YamlBundle' => 'Fixtures\Bundles\YamlBundle\Entity'],
                ],
            ],
            $configEm1->getMethodCalls()
        );

        $this->assertContains(
            [
                'setEntityNamespaces',
                [
                    ['XmlBundle' => 'Fixtures\Bundles\XmlBundle\Entity'],
                ],
            ],
            $configEm2->getMethodCalls()
        );
    }

    public function testDbalLoad(): void
    {
        $container = $this->getContainer();
        $extension = new DoctrineExtension();

        $extension->load([
            ['dbal' => ['connections' => ['default' => ['password' => 'foo']]]],
            [],
            ['dbal' => ['default_connection' => 'foo']],
            [],
        ], $container);

        $config = $container->getDefinition('doctrine.dbal.default_connection')->getArgument(0);

        $this->assertEquals('foo', $config['password']);
        $this->assertEquals('root', $config['user']);
    }

    public function testDbalWrapperClass(): void
    {
        $container = $this->getContainer();
        $extension = new DoctrineExtension();

        $extension->load(
            [
                [
                    'dbal' => [
                        'connections' => [
                            'default' => ['password' => 'foo', 'wrapper_class' => TestWrapperClass::class],
                            'second' => ['password' => 'boo'],
                        ],
                    ],
                ],
                [],
                ['dbal' => ['default_connection' => 'foo']],
                [],
            ],
            $container
        );

        $this->assertEquals(TestWrapperClass::class, $container->getDefinition('doctrine.dbal.default_connection')->getClass());
        $this->assertNull($container->getDefinition('doctrine.dbal.second_connection')->getClass());
    }

    public function testDependencyInjectionConfigurationDefaults(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->getContainer();
        $extension = new DoctrineExtension();
        $config    = BundleConfigurationBuilder::createBuilderWithBaseValues()->build();

        $extension->load([$config], $container);

        $this->assertFalse($container->getParameter('doctrine.orm.auto_generate_proxy_classes'));
        $this->assertEquals('Doctrine\ORM\Configuration', $container->getParameter('doctrine.orm.configuration.class'));
        $this->assertEquals('Doctrine\ORM\EntityManager', $container->getParameter('doctrine.orm.entity_manager.class'));
        $this->assertEquals('Proxies', $container->getParameter('doctrine.orm.proxy_namespace'));
        $this->assertEquals('Doctrine\Common\Cache\ArrayCache', $container->getParameter('doctrine.orm.cache.array.class'));
        $this->assertEquals('Doctrine\Common\Cache\ApcCache', $container->getParameter('doctrine.orm.cache.apc.class'));
        $this->assertEquals('Doctrine\Common\Cache\MemcacheCache', $container->getParameter('doctrine.orm.cache.memcache.class'));
        $this->assertEquals('localhost', $container->getParameter('doctrine.orm.cache.memcache_host'));
        $this->assertEquals('11211', $container->getParameter('doctrine.orm.cache.memcache_port'));
        $this->assertEquals('Memcache', $container->getParameter('doctrine.orm.cache.memcache_instance.class'));
        $this->assertEquals('Doctrine\Common\Cache\XcacheCache', $container->getParameter('doctrine.orm.cache.xcache.class'));
        $this->assertEquals('Doctrine\Persistence\Mapping\Driver\MappingDriverChain', $container->getParameter('doctrine.orm.metadata.driver_chain.class'));
        $this->assertEquals('Doctrine\ORM\Mapping\Driver\AnnotationDriver', $container->getParameter('doctrine.orm.metadata.annotation.class'));
        $this->assertEquals('Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver', $container->getParameter('doctrine.orm.metadata.xml.class'));
        $this->assertEquals('Doctrine\ORM\Mapping\Driver\SimplifiedYamlDriver', $container->getParameter('doctrine.orm.metadata.yml.class'));

        // second-level cache
        $this->assertEquals('Doctrine\ORM\Cache\DefaultCacheFactory', $container->getParameter('doctrine.orm.second_level_cache.default_cache_factory.class'));
        $this->assertEquals('Doctrine\ORM\Cache\Region\DefaultRegion', $container->getParameter('doctrine.orm.second_level_cache.default_region.class'));
        $this->assertEquals('Doctrine\ORM\Cache\Region\FileLockRegion', $container->getParameter('doctrine.orm.second_level_cache.filelock_region.class'));
        $this->assertEquals('Doctrine\ORM\Cache\Logging\CacheLoggerChain', $container->getParameter('doctrine.orm.second_level_cache.logger_chain.class'));
        $this->assertEquals('Doctrine\ORM\Cache\Logging\StatisticsCacheLogger', $container->getParameter('doctrine.orm.second_level_cache.logger_statistics.class'));
        $this->assertEquals('Doctrine\ORM\Cache\CacheConfiguration', $container->getParameter('doctrine.orm.second_level_cache.cache_configuration.class'));
        $this->assertEquals('Doctrine\ORM\Cache\RegionsConfiguration', $container->getParameter('doctrine.orm.second_level_cache.regions_configuration.class'));

        $config = BundleConfigurationBuilder::createBuilder()
            ->addBaseConnection()
            ->addEntityManager([
                'proxy_namespace' => 'MyProxies',
                'auto_generate_proxy_classes' => true,
                'default_entity_manager' => 'default',
                'entity_managers' => [
                    'default' => [
                        'mappings' => ['YamlBundle' => []],
                    ],
                ],
            ])
            ->build();

        $container = $this->getContainer();
        $extension->load([$config], $container);
        $this->compileContainer($container);

        $definition = $container->getDefinition('doctrine.dbal.default_connection');

        $args = $definition->getArguments();
        $this->assertEquals('pdo_mysql', $args[0]['driver']);
        $this->assertEquals('localhost', $args[0]['host']);
        $this->assertEquals('root', $args[0]['user']);
        $this->assertEquals('doctrine.dbal.default_connection.configuration', (string) $args[1]);
        $this->assertEquals('doctrine.dbal.default_connection.event_manager', (string) $args[2]);
        $this->assertCount(0, $definition->getMethodCalls());

        $definition = $container->getDefinition('doctrine.orm.default_entity_manager');
        $this->assertEquals('%doctrine.orm.entity_manager.class%', $definition->getClass());
        $this->assertEquals(['%doctrine.orm.entity_manager.class%', 'create'], $definition->getFactory());

        $this->assertEquals(['default' => 'doctrine.orm.default_entity_manager'], $container->getParameter('doctrine.entity_managers'), 'Set of the existing EntityManagers names is incorrect.');
        $this->assertEquals('%doctrine.entity_managers%', $container->getDefinition('doctrine')->getArgument(2), 'Set of the existing EntityManagers names is incorrect.');

        $arguments = $definition->getArguments();
        $this->assertInstanceOf('Symfony\Component\DependencyInjection\Reference', $arguments[0]);
        $this->assertEquals('doctrine.dbal.default_connection', (string) $arguments[0]);
        $this->assertInstanceOf('Symfony\Component\DependencyInjection\Reference', $arguments[1]);
        $this->assertEquals('doctrine.orm.default_configuration', (string) $arguments[1]);

        $definition = $container->getDefinition('doctrine.orm.default_configuration');
        $calls      = array_values($definition->getMethodCalls());
        $this->assertEquals(['YamlBundle' => 'Fixtures\Bundles\YamlBundle\Entity'], $calls[0][1][0]);
        $this->assertEquals('doctrine.orm.default_metadata_cache', (string) $calls[1][1][0]);
        $this->assertEquals('doctrine.orm.default_query_cache', (string) $calls[2][1][0]);
        $this->assertEquals('doctrine.orm.default_result_cache', (string) $calls[3][1][0]);

        $this->assertEquals('doctrine.orm.naming_strategy.default', (string) $calls[10][1][0]);
        $this->assertEquals('doctrine.orm.quote_strategy.default', (string) $calls[11][1][0]);
        $this->assertEquals('doctrine.orm.default_entity_listener_resolver', (string) $calls[12][1][0]);

        $definition = $container->getDefinition('doctrine.orm.default_metadata_cache_warmer');
        $this->assertSame(DoctrineMetadataCacheWarmer::class, $definition->getClass());
        $this->assertEquals(
            [
                new Reference('doctrine.orm.default_entity_manager'),
                '%kernel.cache_dir%/doctrine/orm/default_metadata.php',
            ],
            $definition->getArguments()
        );

        $definition = $container->getDefinition((string) $container->getAlias('doctrine.orm.default_metadata_cache'));
        $this->assertEquals(PhpArrayAdapter::class, $definition->getClass());

        $arguments = $definition->getArguments();
        $this->assertSame('%kernel.cache_dir%/doctrine/orm/default_metadata.php', $arguments[0]);
        $wrappedDefinition = $arguments[1];
        $this->assertSame(ArrayAdapter::class, $wrappedDefinition->getClass());

        $definition = $container->getDefinition((string) $container->getAlias('doctrine.orm.default_query_cache'));
        $this->assertEquals([DoctrineProvider::class, 'wrap'], $definition->getFactory());
        $arguments = $definition->getArguments();
        $this->assertInstanceOf(Reference::class, $arguments[0]);
        $this->assertEquals('cache.doctrine.orm.default.query', (string) $arguments[0]);
        $this->assertSame(ArrayAdapter::class, $container->getDefinition((string) $arguments[0])->getClass());

        $definition = $container->getDefinition((string) $container->getAlias('doctrine.orm.default_result_cache'));
        $this->assertEquals([DoctrineProvider::class, 'wrap'], $definition->getFactory());
        $arguments = $definition->getArguments();
        $this->assertInstanceOf(Reference::class, $arguments[0]);
        $this->assertEquals('cache.doctrine.orm.default.result', (string) $arguments[0]);
        $this->assertSame(ArrayAdapter::class, $container->getDefinition((string) $arguments[0])->getClass());
    }

    public function testUseSavePointsAddMethodCallToAddSavepointsToTheConnection(): void
    {
        $container = $this->getContainer();
        $extension = new DoctrineExtension();

        $extension->load([
            [
                'dbal' => [
                    'connections' => [
                        'default' => ['password' => 'foo', 'use_savepoints' => true],
                    ],
                ],
            ],
        ], $container);

        $calls = $container->getDefinition('doctrine.dbal.default_connection')->getMethodCalls();
        $this->assertCount(1, $calls);
        $this->assertEquals('setNestTransactionsWithSavepoints', $calls[0][0]);
        $this->assertTrue($calls[0][1][0]);
    }

    public function testAutoGenerateProxyClasses(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->getContainer();
        $extension = new DoctrineExtension();

        $config = BundleConfigurationBuilder::createBuilder()
            ->addBaseConnection()
            ->addEntityManager([
                'proxy_namespace' => 'MyProxies',
                'auto_generate_proxy_classes' => 'eval',
                'default_entity_manager' => 'default',
                'entity_managers' => [
                    'default' => [
                        'mappings' => ['YamlBundle' => []],
                    ],
                ],
            ])
            ->build();

        $extension->load([$config], $container);

        $this->assertEquals(3 /* \Doctrine\Common\Proxy\AbstractProxyFactory::AUTOGENERATE_EVAL */, $container->getParameter('doctrine.orm.auto_generate_proxy_classes'));
    }

    public function testSingleEntityManagerWithDefaultConfiguration(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->getContainer();
        $extension = new DoctrineExtension();

        $configurationArray = BundleConfigurationBuilder::createBuilderWithBaseValues()->build();

        $extension->load([$configurationArray], $container);
        $this->compileContainer($container);

        $definition = $container->getDefinition('doctrine.orm.default_entity_manager');
        $this->assertEquals('%doctrine.orm.entity_manager.class%', $definition->getClass());
        $this->assertEquals(['%doctrine.orm.entity_manager.class%', 'create'], $definition->getFactory());

        $this->assertDICConstructorArguments($definition, [
            new Reference('doctrine.dbal.default_connection'),
            new Reference('doctrine.orm.default_configuration'),
        ]);
    }

    public function testSingleEntityManagerWithDefaultSecondLevelCacheConfiguration(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->getContainer();
        $extension = new DoctrineExtension();

        $configurationArray = BundleConfigurationBuilder::createBuilderWithBaseValues()
            ->addBaseSecondLevelCache()
            ->build();

        $extension->load([$configurationArray], $container);
        $this->compileContainer($container);

        $definition = $container->getDefinition('doctrine.orm.default_entity_manager');
        $this->assertEquals('%doctrine.orm.entity_manager.class%', $definition->getClass());
        $this->assertEquals(['%doctrine.orm.entity_manager.class%', 'create'], $definition->getFactory());

        $this->assertDICConstructorArguments($definition, [
            new Reference('doctrine.dbal.default_connection'),
            new Reference('doctrine.orm.default_configuration'),
        ]);

        $slcDefinition = $container->getDefinition('doctrine.orm.default_second_level_cache.default_cache_factory');
        $this->assertEquals('%doctrine.orm.second_level_cache.default_cache_factory.class%', $slcDefinition->getClass());
    }

    /** @group legacy */
    public function testSingleEntityManagerWithCustomSecondLevelCacheConfiguration(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->getContainer();
        $extension = new DoctrineExtension();

        $configurationArray = BundleConfigurationBuilder::createBuilderWithBaseValues()
            ->addSecondLevelCache([
                'region_cache_driver' => ['type' => 'service', 'id' => 'my_cache'],
                'regions' => [
                    'hour_region' => ['lifetime' => 3600],
                ],
                'factory' => 'YamlBundle\Cache\MyCacheFactory',
            ])
            ->build();

        $extension->load([$configurationArray], $container);
        $this->compileContainer($container);

        $definition = $container->getDefinition('doctrine.orm.default_entity_manager');
        $this->assertEquals('%doctrine.orm.entity_manager.class%', $definition->getClass());
        $this->assertEquals(['%doctrine.orm.entity_manager.class%', 'create'], $definition->getFactory());

        $this->assertDICConstructorArguments($definition, [
            new Reference('doctrine.dbal.default_connection'),
            new Reference('doctrine.orm.default_configuration'),
        ]);

        $slcDefinition = $container->getDefinition('doctrine.orm.default_second_level_cache.default_cache_factory');
        $this->assertEquals('YamlBundle\Cache\MyCacheFactory', $slcDefinition->getClass());
    }

    public function testBundleEntityAliases(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->getContainer();
        $extension = new DoctrineExtension();

        $config        = BundleConfigurationBuilder::createBuilder()
             ->addBaseConnection()
             ->build();
        $config['orm'] = ['default_entity_manager' => 'default', 'entity_managers' => ['default' => ['mappings' => ['YamlBundle' => []]]]];
        $extension->load([$config], $container);

        $definition = $container->getDefinition('doctrine.orm.default_configuration');
        $this->assertDICDefinitionMethodCallOnce(
            $definition,
            'setEntityNamespaces',
            [['YamlBundle' => 'Fixtures\Bundles\YamlBundle\Entity']]
        );
    }

    public function testOverwriteEntityAliases(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->getContainer();
        $extension = new DoctrineExtension();

        $config        = BundleConfigurationBuilder::createBuilder()
             ->addBaseConnection()
             ->build();
        $config['orm'] = ['default_entity_manager' => 'default', 'entity_managers' => ['default' => ['mappings' => ['YamlBundle' => ['alias' => 'yml']]]]];
        $extension->load([$config], $container);

        $definition = $container->getDefinition('doctrine.orm.default_configuration');
        $this->assertDICDefinitionMethodCallOnce(
            $definition,
            'setEntityNamespaces',
            [['yml' => 'Fixtures\Bundles\YamlBundle\Entity']]
        );
    }

    public function testYamlBundleMappingDetection(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->getContainer(['YamlBundle']);
        $extension = new DoctrineExtension();

        $config = BundleConfigurationBuilder::createBuilder()
            ->addBaseConnection()
            ->addBaseEntityManager()
            ->build();
        $extension->load([$config], $container);

        $definition = $container->getDefinition('doctrine.orm.default_metadata_driver');
        $this->assertDICDefinitionMethodCallOnce($definition, 'addDriver', [
            new Reference('doctrine.orm.default_yml_metadata_driver'),
            'Fixtures\Bundles\YamlBundle\Entity',
        ]);
    }

    public function testXmlBundleMappingDetection(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->getContainer(['XmlBundle']);
        $extension = new DoctrineExtension();

        $config = BundleConfigurationBuilder::createBuilder()
            ->addBaseConnection()
            ->addEntityManager([
                'default_entity_manager' => 'default',
                'entity_managers' => [
                    'default' => [
                        'mappings' => [
                            'XmlBundle' => [],
                        ],
                    ],
                ],
            ])
            ->build();
        $extension->load([$config], $container);

        $definition = $container->getDefinition('doctrine.orm.default_metadata_driver');
        $this->assertDICDefinitionMethodCallOnce($definition, 'addDriver', [
            new Reference('doctrine.orm.default_xml_metadata_driver'),
            'Fixtures\Bundles\XmlBundle\Entity',
        ]);
    }

    public function testAnnotationsBundleMappingDetection(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->getContainer(['AnnotationsBundle']);
        $extension = new DoctrineExtension();

        $config = BundleConfigurationBuilder::createBuilder()
            ->addBaseConnection()
            ->addEntityManager([
                'default_entity_manager' => 'default',
                'entity_managers' => [
                    'default' => [
                        'mappings' => [
                            'AnnotationsBundle' => [],
                        ],
                    ],
                ],
            ])
            ->build();
        $extension->load([$config], $container);

        $definition = $container->getDefinition('doctrine.orm.default_metadata_driver');
        $this->assertDICDefinitionMethodCallOnce($definition, 'addDriver', [
            new Reference('doctrine.orm.default_annotation_metadata_driver'),
            'Fixtures\Bundles\AnnotationsBundle\Entity',
        ]);
    }

    public function testAttributesBundleMappingDetection(): void
    {
        if (! class_exists(AttributeDriver::class)) {
            self::markTestSkipped('This test requires ORM 2.9 with attribute driver.');
        }

        if (PHP_VERSION_ID < 70400) {
            self::markTestSkipped('This test requires PHP 7.4.');
        }

        $container = $this->getContainer(['AttributesBundle']);
        $extension = new DoctrineExtension();

        $config = BundleConfigurationBuilder::createBuilder()
            ->addBaseConnection()
            ->addEntityManager([
                'default_entity_manager' => 'default',
                'entity_managers' => [
                    'default' => [
                        'mappings' => [
                            'AttributesBundle' => ['type' => 'attribute'],
                        ],
                    ],
                ],
            ])
            ->build();
        $extension->load([$config], $container);

        $definition = $container->getDefinition('doctrine.orm.default_metadata_driver');
        $this->assertDICDefinitionMethodCallOnce($definition, 'addDriver', [
            new Reference('doctrine.orm.default_attribute_metadata_driver'),
            'Fixtures\Bundles\AttributesBundle\Entity',
        ]);

        $attributeDriver = $container->get('doctrine.orm.default_attribute_metadata_driver');
        $this->assertInstanceOf(AttributeDriver::class, $attributeDriver);
    }

    public function testOrmMergeConfigs(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->getContainer(['XmlBundle', 'AnnotationsBundle', 'AttributesBundle']);
        $extension = new DoctrineExtension();

        $config1 = BundleConfigurationBuilder::createBuilder()
            ->addBaseConnection()
            ->addEntityManager([
                'auto_generate_proxy_classes' => true,
                'default_entity_manager' => 'default',
                'entity_managers' => [
                    'default' => [
                        'mappings' => [
                            'AnnotationsBundle' => [],
                            'AttributesBundle' => ['type' => 'attribute'],
                        ],
                    ],
                ],
            ])
            ->build();
        $config2 = BundleConfigurationBuilder::createBuilder()
            ->addBaseConnection()
            ->addEntityManager([
                'auto_generate_proxy_classes' => false,
                'default_entity_manager' => 'default',
                'entity_managers' => [
                    'default' => [
                        'mappings' => [
                            'XmlBundle' => [],
                        ],
                    ],
                ],
            ])
            ->build();
        $extension->load([$config1, $config2], $container);

        $definition = $container->getDefinition('doctrine.orm.default_metadata_driver');
        $this->assertDICDefinitionMethodCallAt(0, $definition, 'addDriver', [
            new Reference('doctrine.orm.default_annotation_metadata_driver'),
            'Fixtures\Bundles\AnnotationsBundle\Entity',
        ]);
        $this->assertDICDefinitionMethodCallAt(1, $definition, 'addDriver', [
            new Reference('doctrine.orm.default_attribute_metadata_driver'),
            'Fixtures\Bundles\AttributesBundle\Entity',
        ]);
        $this->assertDICDefinitionMethodCallAt(2, $definition, 'addDriver', [
            new Reference('doctrine.orm.default_xml_metadata_driver'),
            'Fixtures\Bundles\XmlBundle\Entity',
        ]);

        $configDef = $container->getDefinition('doctrine.orm.default_configuration');
        $this->assertDICDefinitionMethodCallOnce($configDef, 'setAutoGenerateProxyClasses');

        $calls = $configDef->getMethodCalls();
        foreach ($calls as $call) {
            if ($call[0] === 'setAutoGenerateProxyClasses') {
                $this->assertFalse($container->getParameterBag()->resolveValue($call[1][0]));

                break;
            }
        }
    }

    public function testAnnotationsBundleMappingDetectionWithVendorNamespace(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->getContainer(['AnnotationsBundle'], 'Vendor');
        $extension = new DoctrineExtension();

        $config = BundleConfigurationBuilder::createBuilder()
            ->addBaseConnection()
            ->addEntityManager([
                'default_entity_manager' => 'default',
                'entity_managers' => [
                    'default' => [
                        'mappings' => [
                            'AnnotationsBundle' => [],
                        ],
                    ],
                ],
            ])
            ->build();
        $extension->load([$config], $container);

        $calls = $container->getDefinition('doctrine.orm.default_metadata_driver')->getMethodCalls();
        $this->assertEquals('doctrine.orm.default_annotation_metadata_driver', (string) $calls[0][1][0]);
        $this->assertEquals('Fixtures\Bundles\Vendor\AnnotationsBundle\Entity', $calls[0][1][1]);
    }

    public function testMessengerIntegration(): void
    {
        /** @psalm-suppress UndefinedClass */
        if (! interface_exists(MessageBusInterface::class)) {
            $this->markTestSkipped('Symfony Messenger component is not installed');
        }

        $container = $this->getContainer();
        $extension = new DoctrineExtension();

        $config = BundleConfigurationBuilder::createBuilder()
            ->addBaseConnection()
            ->build();
        $extension->load([$config], $container);

        $this->assertNotNull($middlewarePrototype = $container->getDefinition('messenger.middleware.doctrine_transaction'));
        $this->assertCount(1, $middlewarePrototype->getArguments());
        $this->assertNotNull($middlewarePrototype = $container->getDefinition('messenger.middleware.doctrine_ping_connection'));
        $this->assertCount(1, $middlewarePrototype->getArguments());
        $this->assertNotNull($middlewarePrototype = $container->getDefinition('messenger.middleware.doctrine_close_connection'));
        $this->assertCount(1, $middlewarePrototype->getArguments());

        if (class_exists(DoctrineClearEntityManagerWorkerSubscriber::class)) {
            $this->assertNotNull($subscriber = $container->getDefinition('doctrine.orm.messenger.event_subscriber.doctrine_clear_entity_manager'));
            $this->assertCount(1, $subscriber->getArguments());
        } else {
            $this->assertFalse($container->hasDefinition('doctrine.orm.messenger.event_subscriber.doctrine_clear_entity_manager'));
        }
    }

    /** @group legacy */
    public function testInvalidCacheConfiguration(): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->getContainer();
        $extension = new DoctrineExtension();

        $config = BundleConfigurationBuilder::createBuilder()
            ->addBaseConnection()
            ->addEntityManager(['metadata_cache_driver' => 'redis'])
            ->build();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown cache of type "redis" configured for cache "metadata_cache" in entity manager "default"');

        $extension->load([$config], $container);
    }

    /**
     * @param array{pool?: string, type: ?string} $cacheConfig
     *
     * @dataProvider cacheConfigurationProvider
     */
    public function testCacheConfiguration(string $expectedAliasName, string $expectedTarget, string $cacheName, $cacheConfig): void
    {
        if (! interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('This test requires ORM');
        }

        $container = $this->getContainer();
        $extension = new DoctrineExtension();

        $config = BundleConfigurationBuilder::createBuilder()
            ->addBaseConnection()
            ->addEntityManager([$cacheName => $cacheConfig])
            ->build();

        $extension->load([$config], $container);

        $this->assertTrue($container->hasAlias($expectedAliasName));
        $alias = $container->getAlias($expectedAliasName);
        $this->assertEquals($expectedTarget, (string) $alias);
    }

    /**
     * @param array{pool?: string, type: ?string} $cacheConfig
     *
     * @dataProvider legacyCacheConfigurationProvider
     * @group legacy
     */
    public function testLegacyCacheConfiguration(string $expectedAliasName, string $expectedAliasTarget, string $cacheName, array $cacheConfig): void
    {
        $this->testCacheConfiguration($expectedAliasName, $expectedAliasTarget, $cacheName, $cacheConfig);
    }

    /** @return array<string, array<string, string|array{type: ?string, pool?: string}>> */
    public static function legacyCacheConfigurationProvider(): array
    {
        return [
            'metadata_cache_default' => [
                'expectedAliasName' => 'doctrine.orm.default_metadata_cache',
                'expectedAliasTarget' => 'cache.doctrine.orm.default.metadata',
                'cacheName' => 'metadata_cache_driver',
                'cacheConfig' => ['type' => null],
            ],
            'metadata_cache_pool' => [
                'expectedAliasName' => 'doctrine.orm.default_metadata_cache',
                'expectedAliasTarget' => 'metadata_cache_pool',
                'cacheName' => 'metadata_cache_driver',
                'cacheConfig' => ['type' => 'pool', 'pool' => 'metadata_cache_pool'],
            ],
            'metadata_cache_service' => [
                'expectedAliasName' => 'doctrine.orm.default_metadata_cache',
                'expectedAliasTarget' => 'service_target_metadata',
                'cacheName' => 'metadata_cache_driver',
                'cacheConfig' => ['type' => 'service', 'id' => 'service_target_metadata'],
            ],
            'query_cache_service' => [
                'expectedAliasName' => 'doctrine.orm.default_query_cache',
                'expectedAliasTarget' => 'service_target_query',
                'cacheName' => 'query_cache_driver',
                'cacheConfig' => ['type' => 'service', 'id' => 'service_target_query'],
            ],
            'result_cache_service' => [
                'expectedAliasName' => 'doctrine.orm.default_result_cache',
                'expectedAliasTarget' => 'service_target_result',
                'cacheName' => 'result_cache_driver',
                'cacheConfig' => ['type' => 'service', 'id' => 'service_target_result'],
            ],
        ];
    }

    /** @return array<string, array<string, string|array{type: ?string, pool?: string}>> */
    public static function cacheConfigurationProvider(): array
    {
        return [
            'query_cache_default' => [
                'expectedAliasName' => 'doctrine.orm.default_query_cache',
                'expectedTarget' => 'cache.doctrine.orm.default.query',
                'cacheName' => 'query_cache_driver',
                'cacheConfig' => ['type' => null],
            ],
            'result_cache_default' => [
                'expectedAliasName' => 'doctrine.orm.default_result_cache',
                'expectedTarget' => 'cache.doctrine.orm.default.result',
                'cacheName' => 'result_cache_driver',
                'cacheConfig' => ['type' => null],
            ],
            'query_cache_pool' => [
                'expectedAliasName' => 'doctrine.orm.default_query_cache',
                'expectedTarget' => 'query_cache_pool',
                'cacheName' => 'query_cache_driver',
                'cacheConfig' => ['type' => 'pool', 'pool' => 'query_cache_pool'],
            ],
            'result_cache_pool' => [
                'expectedAliasName' => 'doctrine.orm.default_result_cache',
                'expectedTarget' => 'result_cache_pool',
                'cacheName' => 'result_cache_driver',
                'cacheConfig' => ['type' => 'pool', 'pool' => 'result_cache_pool'],
            ],
            'query_cache_service' => [
                'expectedAliasName' => 'doctrine.orm.default_query_cache',
                'expectedTarget' => 'service_target_query',
                'cacheName' => 'query_cache_driver',
                'cacheConfig' => ['type' => 'service', 'id' => 'service_target_query'],
            ],
            'result_cache_service' => [
                'expectedAliasName' => 'doctrine.orm.default_result_cache',
                'expectedTarget' => 'service_target_result',
                'cacheName' => 'result_cache_driver',
                'cacheConfig' => ['type' => 'service', 'id' => 'service_target_result'],
            ],
        ];
    }

    public function testShardManager(): void
    {
        $container    = $this->getContainer();
        $extension    = new DoctrineExtension();
        $managerClass = SQLAzureShardManager::class;

        $config = BundleConfigurationBuilder::createBuilder()
             ->addConnection([
                 'connections' => [
                     'foo' => [
                         'shards' => [
                             'test' => ['id' => 1],
                         ],
                     ],
                     'bar' => [],
                     'baz' => [
                         'shards' => [
                             'test' => ['id' => 1],
                         ],
                         'shard_manager_class' => $managerClass,
                     ],
                 ],
             ])
            ->build();

        $extension->load([$config], $container);

        $this->assertTrue($container->hasDefinition('doctrine.dbal.foo_shard_manager'));
        $this->assertFalse($container->hasDefinition('doctrine.dbal.bar_shard_manager'));
        $this->assertTrue($container->hasDefinition('doctrine.dbal.baz_shard_manager'));

        $fooManagerDef = $container->getDefinition('doctrine.dbal.foo_shard_manager');
        $bazManagerDef = $container->getDefinition('doctrine.dbal.baz_shard_manager');

        $this->assertEquals(PoolingShardManager::class, $fooManagerDef->getClass());
        $this->assertEquals($managerClass, $bazManagerDef->getClass());
    }

    /**
     * @requires PHP 8
     */
    public function testAsEntityListenerAttribute()
    {
        if (! method_exists(ContainerBuilder::class, 'getAutoconfiguredAttributes')) {
            $this->markTestSkipped('symfony/dependency-injection 5.3.0 needed');
        }

        $container = $this->getContainer();
        $extension = new DoctrineExtension();

        $config = BundleConfigurationBuilder::createBuilder()
            ->addBaseConnection()
            ->addBaseEntityManager()
            ->build();

        $extension->load([$config], $container);

        $attributes = $container->getAutoconfiguredAttributes();
        $this->assertInstanceOf(Closure::class, $attributes[AsEntityListener::class]);

        $reflector  = new ReflectionClass(Php8EntityListener::class);
        $definition = new ChildDefinition('');
        $attribute  = $reflector->getAttributes(AsEntityListener::class)[0]->newInstance();

        $attributes[AsEntityListener::class]($definition, $attribute);

        $expected = [
            'event'          => null,
            'method'         => null,
            'lazy'           => null,
            'entity_manager' => null,
        ];
        $this->assertSame([$expected], $definition->getTag('doctrine.orm.entity_listener'));
    }

    /** @param list<string> $bundles */
    private function getContainer(array $bundles = ['YamlBundle'], string $vendor = ''): ContainerBuilder
    {
        $map = [];
        foreach ($bundles as $bundle) {
            require_once __DIR__ . '/Fixtures/Bundles/' . ($vendor ? $vendor . '/' : '') . $bundle . '/' . $bundle . '.php';

            $map[$bundle] = 'Fixtures\\Bundles\\' . ($vendor ? $vendor . '\\' : '') . $bundle . '\\' . $bundle;
        }

        $container = new ContainerBuilder(new ParameterBag([
            'kernel.debug' => false,
            'kernel.bundles' => $map,
            'kernel.cache_dir' => sys_get_temp_dir(),
            'kernel.environment' => 'test',
            'kernel.root_dir' => __DIR__ . '/../../', // src dir
        ]));

        // Register dummy cache services so we don't have to load the FrameworkExtension
        $container->setDefinition('cache.system', (new Definition(ArrayAdapter::class))->setPublic(true));
        $container->setDefinition('cache.app', (new Definition(ArrayAdapter::class))->setPublic(true));
        $container->setDefinition('my_pool', (new Definition(ArrayAdapter::class))->setPublic(true));
        $container->setDefinition('my_cache', (new Definition(Cache::class))->setPublic(true));
        $container->setDefinition('service_target_metadata', (new Definition(Cache::class))->setPublic(true));
        $container->setDefinition('service_target_query', (new Definition(Cache::class))->setPublic(true));
        $container->setDefinition('service_target_result', (new Definition(Cache::class))->setPublic(true));
        $container->setDefinition('service_target_metadata_psr6', (new Definition(ArrayAdapter::class))->setPublic(true));

        return $container;
    }

    /** @param list<mixed> $args */
    private function assertDICConstructorArguments(Definition $definition, array $args): void
    {
        $this->assertEquals($args, $definition->getArguments(), "Expected and actual DIC Service constructor arguments of definition '" . $definition->getClass() . "' don't match.");
    }

    /** @param list<mixed> $params */
    private function assertDICDefinitionMethodCallAt(int $pos, Definition $definition, string $methodName, ?array $params = null): void
    {
        $calls = $definition->getMethodCalls();
        if (! isset($calls[$pos][0])) {
            return;
        }

        $this->assertEquals($methodName, $calls[$pos][0], "Method '" . $methodName . "' is expected to be called at position " . $pos . '.');

        if ($params === null) {
            return;
        }

        $this->assertEquals($params, $calls[$pos][1], "Expected parameters to methods '" . $methodName . "' do not match the actual parameters.");
    }

    /**
     * Assertion for the DI Container, check if the given definition contains a method call with the given parameters.
     *
     * @param list<mixed> $params
     */
    private function assertDICDefinitionMethodCallOnce(Definition $definition, string $methodName, ?array $params = null): void
    {
        $calls  = $definition->getMethodCalls();
        $called = false;
        foreach ($calls as $call) {
            if ($call[0] !== $methodName) {
                continue;
            }

            if ($called) {
                $this->fail("Method '" . $methodName . "' is expected to be called only once, a second call was registered though.");
            } else {
                $called = true;
                if ($params !== null) {
                    $this->assertEquals($params, $call[1], "Expected parameters to methods '" . $methodName . "' do not match the actual parameters.");
                }
            }
        }

        if ($called) {
            return;
        }

        $this->fail("Method '" . $methodName . "' is expected to be called once, definition does not contain a call though.");
    }

    private function compileContainer(ContainerBuilder $container): void
    {
        $container->getCompilerPassConfig()->setOptimizationPasses([new ResolveChildDefinitionsPass()]);
        $container->getCompilerPassConfig()->setRemovingPasses([]);
        $container->addCompilerPass(new CacheCompatibilityPass());
        $container->compile();
    }
}

class TestWrapperClass extends Connection
{
}
