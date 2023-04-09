<?php

namespace TheCodingMachine\GraphQLite\Bundle\DependencyInjection;

use Doctrine\Common\Annotations\AnnotationReader as DoctrineAnnotationReader;
use Doctrine\Common\Annotations\PsrCachedReader;
use GraphQL\Server\ServerConfig;
use GraphQL\Validator\Rules\DisableIntrospection;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth;
use Mouf\Composer\ClassNameMapper;
use Psr\SimpleCache\CacheInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use TheCodingMachine\CacheUtils\ClassBoundCache;
use TheCodingMachine\CacheUtils\ClassBoundCacheContract;
use TheCodingMachine\CacheUtils\ClassBoundCacheContractInterface;
use TheCodingMachine\CacheUtils\ClassBoundMemoryAdapter;
use TheCodingMachine\CacheUtils\FileBoundCache;
use TheCodingMachine\ClassExplorer\Glob\GlobClassExplorer;
use TheCodingMachine\GraphQLite\AggregateControllerQueryProviderFactory;
use TheCodingMachine\GraphQLite\AnnotationReader;
use TheCodingMachine\GraphQLite\Annotations\Autowire;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Mutation;
use TheCodingMachine\GraphQLite\Annotations\Query;
use TheCodingMachine\GraphQLite\Bundle\Controller\GraphQL\LoginController;
use TheCodingMachine\GraphQLite\Bundle\Controller\GraphQL\MeController;
use TheCodingMachine\GraphQLite\Bundle\Types\SymfonyUserInterfaceType;
use TheCodingMachine\GraphQLite\GraphQLRuntimeException as GraphQLException;
use TheCodingMachine\GraphQLite\Mappers\StaticClassListTypeMapperFactory;
use TheCodingMachine\GraphQLite\Mappers\StaticTypeMapper;
use TheCodingMachine\GraphQLite\SchemaFactory;
use Webmozart\Assert\Assert;
use function class_exists;
use function filter_var;
use function ini_get;
use function interface_exists;
use function strpos;

/**
 * Detects controllers and types automatically and tag them.
 */
class GraphQLiteCompilerPass implements CompilerPassInterface
{
    private ?AnnotationReader $annotationReader = null;

    private ?CacheInterface $cache = null;

    private string $cacheDir;

    private ?ClassBoundCacheContractInterface $codeCache = null;

    private static function getParametersByName(ReflectionMethod $method): array
    {
        $parameters = [];
        foreach ($method->getParameters() as $parameter) {
            $parameters[$parameter->getName()] = $parameter;
        }

        return $parameters;
    }

    /**
     * You can modify the container here before it is dumped to PHP code.
     */
    public function process(ContainerBuilder $container): void
    {
        $reader = $this->getAnnotationReader();
        $cacheDir = $container->getParameter('kernel.cache_dir');
        Assert::string($cacheDir);
        $this->cacheDir = $cacheDir;
        //$inputTypeUtils = new InputTypeUtils($reader, $namingStrategy);

        // Let's scan the whole container and tag the services that belong to the namespace we want to inspect.
        $controllersNamespaces = $container->getParameter('graphqlite.namespace.controllers');
        Assert::isIterable($controllersNamespaces);
        $typesNamespaces = $container->getParameter('graphqlite.namespace.types');
        Assert::isIterable($typesNamespaces);

        $firewallName = $container->getParameter('graphqlite.security.firewall_name');
        Assert::string($firewallName);
        $firewallConfigServiceName = 'security.firewall.map.config.'.$firewallName;

        // 2 seconds of TTL in environment mode. Otherwise, let's cache forever!

        $schemaFactory = $container->getDefinition(SchemaFactory::class);

        $env = $container->getParameter('kernel.environment');
        if ($env === 'prod') {
            $schemaFactory->addMethodCall('prodMode');
        } elseif ($env === 'dev') {
            $schemaFactory->addMethodCall('devMode');
        }

        $disableLogin = false;

        if ($container->getParameter('graphqlite.security.enable_login') === 'auto'
            && (!$container->has($firewallConfigServiceName) ||
                !$container->has(UserPasswordHasherInterface::class) ||
                !$container->has(TokenStorageInterface::class) ||
                !$container->has('session.factory')
            )) {
            $disableLogin = true;
        }

        if ($container->getParameter('graphqlite.security.enable_login') === 'off') {
            $disableLogin = true;
        }

        // If the security is disabled, let's remove the LoginController
        if ($disableLogin === true) {
            $container->removeDefinition(LoginController::class);
        }

        if ($container->getParameter('graphqlite.security.enable_login') === 'on') {
            if (!$container->has('session.factory')) {
                throw new GraphQLException(
                    'In order to enable the login/logout mutations (via the graphqlite.security.enable_login parameter), you need to enable session support (via the "framework.session.enabled" config parameter).'
                );
            }

            if (!$container->has(UserPasswordHasherInterface::class)
                || !$container->has(TokenStorageInterface::class)
                || !$container->has($firewallConfigServiceName)
            ) {
                throw new GraphQLException(
                    'In order to enable the login/logout mutations (via the graphqlite.security.enable_login parameter), you need to install the security bundle. Please be sure to correctly configure the user provider (in the security.providers configuration settings)'
                );
            }
        }

        if ($disableLogin === false) {
            // Let's do some dark magic. We need the user provider. We need its name. It is stored in the "config" object.
            $provider = $container->findDefinition('security.firewall.map.config.'.$firewallName)->getArgument(5);
            $container->findDefinition(LoginController::class)->setArgument(0, new Reference($provider));
            $this->registerController(LoginController::class, $container);
        }

        $disableMe = false;
        if ($container->getParameter('graphqlite.security.enable_me') === 'auto'
            && !$container->has(TokenStorageInterface::class)) {
            $disableMe = true;
        }

        if ($container->getParameter('graphqlite.security.enable_me') === 'off') {
            $disableMe = true;
        }

        // If the security is disabled, let's remove the LoginController
        if ($disableMe === true) {
            $container->removeDefinition(MeController::class);
        }

        if ($container->getParameter('graphqlite.security.enable_me') === 'on') {
            if (!$container->has(TokenStorageInterface::class)) {
                throw new GraphQLException(
                    'In order to enable the "me" query (via the graphqlite.security.enable_me parameter), you need to install the security bundle.'
                );
            }
        }

        // ServerConfig rules
        $serverConfigDefinition = $container->findDefinition(ServerConfig::class);
        $rulesDefinition = [];
        if ($container->getParameter('graphqlite.security.introspection') === false) {
            $rulesDefinition[] = $container->findDefinition(DisableIntrospection::class);
        }

        $complexity = $container->getParameter('graphqlite.security.maximum_query_complexity');
        if ($complexity) {
            Assert::integerish($complexity);

            $rulesDefinition[] = $container->findDefinition(QueryComplexity::class)
                ->setArgument(0, (int) $complexity);
        }

        $depth = $container->getParameter('graphqlite.security.maximum_query_depth');
        if ($depth) {
            Assert::integerish($depth);

            $rulesDefinition[] = $container->findDefinition(QueryDepth::class)
                ->setArgument(0, (int) $depth);
        }

        $serverConfigDefinition->addMethodCall('setValidationRules', [$rulesDefinition]);

        if ($disableMe === false) {
            $this->registerController(MeController::class, $container);
        }

        // Perf improvement: let's remove the AggregateControllerQueryProviderFactory if it is empty.
        if (empty($container->findDefinition(AggregateControllerQueryProviderFactory::class)->getArgument(0))) {
            $container->removeDefinition(AggregateControllerQueryProviderFactory::class);
        }

        // Let's register the mapping with UserInterface if UserInterface is available.
        if (interface_exists(UserInterface::class)) {
            $staticTypes = $container->getDefinition(StaticClassListTypeMapperFactory::class)->getArgument(0);
            $staticTypes[] = SymfonyUserInterfaceType::class;
            $container->getDefinition(StaticClassListTypeMapperFactory::class)->setArgument(0, $staticTypes);
        }

        foreach ($container->getDefinitions() as $id => $definition) {
            if ($definition->isAbstract() || $definition->getClass() === null) {
                continue;
            }

            /**
             * @var class-string $class
             */
            $class = $definition->getClass();
            /*            foreach ($controllersNamespaces as $controllersNamespace) {
                            if (strpos($class, $controllersNamespace) === 0) {
                                $definition->addTag('graphql.annotated.controller');
                            }
                        }*/

            foreach ($typesNamespaces as $typesNamespace) {
                if (strpos($class, $typesNamespace) === 0) {
                    //$definition->addTag('graphql.annotated.type');
                    // Set the types public
                    $reflectionClass = new ReflectionClass($class);
                    $typeAnnotation = $this->getAnnotationReader()->getTypeAnnotation($reflectionClass);

                    if ($typeAnnotation !== null && $typeAnnotation->isSelfType()) {
                        continue;
                    }

                    if ($typeAnnotation !== null
                        || $this->getAnnotationReader()->getExtendTypeAnnotation($reflectionClass) !== null
                    ) {
                        $definition->setPublic(true);
                    }

                    foreach ($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                        $factory = $reader->getFactoryAnnotation($method);

                        if ($factory !== null) {
                            $definition->setPublic(true);
                        }
                    }
                }
            }
        }

        foreach ($controllersNamespaces as $controllersNamespace) {
            $schemaFactory->addMethodCall('addControllerNamespace', [$controllersNamespace]);

            foreach ($this->getClassList($controllersNamespace) as $className => $refClass) {
                $this->makePublicInjectedServices($refClass, $reader, $container, true);
            }
        }

        foreach ($typesNamespaces as $typeNamespace) {
            $schemaFactory->addMethodCall('addTypeNamespace', [$typeNamespace]);

            foreach ($this->getClassList($typeNamespace) as $className => $refClass) {
                $this->makePublicInjectedServices($refClass, $reader, $container, false);
            }
        }

        // Register custom output types
        $taggedServices = $container->findTaggedServiceIds('graphql.output_type');

        $customTypes = [];
        $customNotMappedTypes = [];
        foreach ($taggedServices as $id => $tags) {
            foreach ($tags as $attributes) {
                if (isset($attributes["class"])) {
                    $phpClass = $attributes["class"];

                    if (!class_exists($phpClass)) {
                        throw new RuntimeException(
                            sprintf(
                                'The class attribute of the graphql.output_type annotation of the %s service must point to an existing PHP class. Value passed: %s',
                                $id,
                                $phpClass
                            )
                        );
                    }

                    $customTypes[$phpClass] = new Reference($id);
                } else {
                    $customNotMappedTypes[] = new Reference($id);
                }
            }
        }

        if (!empty($customTypes)) {
            $definition = $container->getDefinition(StaticTypeMapper::class);
            $definition->addMethodCall('setTypes', [$customTypes]);
        }

        if (!empty($customNotMappedTypes)) {
            $definition = $container->getDefinition(StaticTypeMapper::class);
            $definition->addMethodCall('setNotMappedTypes', [$customNotMappedTypes]);
        }

        // Register graphql.queryprovider
        $this->mapAdderToTag('graphql.queryprovider', 'addQueryProvider', $container, $schemaFactory);
        $this->mapAdderToTag('graphql.queryprovider_factory', 'addQueryProviderFactory', $container, $schemaFactory);
        $this->mapAdderToTag(
            'graphql.root_type_mapper_factory',
            'addRootTypeMapperFactory',
            $container,
            $schemaFactory
        );
        $this->mapAdderToTag('graphql.parameter_middleware', 'addParameterMiddleware', $container, $schemaFactory);
        $this->mapAdderToTag('graphql.field_middleware', 'addFieldMiddleware', $container, $schemaFactory);
        $this->mapAdderToTag('graphql.type_mapper', 'addTypeMapper', $container, $schemaFactory);
        $this->mapAdderToTag('graphql.type_mapper_factory', 'addTypeMapperFactory', $container, $schemaFactory);

        // Configure cache
        if (ApcuAdapter::isSupported()
            && (PHP_SAPI !== 'cli' || filter_var(ini_get('apc.enable_cli'), FILTER_VALIDATE_BOOLEAN))
        ) {
            $container->setAlias('graphqlite.cache', 'graphqlite.apcucache');
        } else {
            $container->setAlias('graphqlite.cache', 'graphqlite.phpfilescache');
        }
    }

    /**
     * Returns a cached Doctrine annotation reader.
     * Note: we cannot get the annotation reader service in the container as we are in a compiler pass.
     */
    private function getAnnotationReader(): AnnotationReader
    {
        if ($this->annotationReader === null) {
            $doctrineAnnotationReader = new DoctrineAnnotationReader();

            if (ApcuAdapter::isSupported()) {
                $doctrineAnnotationReader = new PsrCachedReader(
                    $doctrineAnnotationReader,
                    new ApcuAdapter('graphqlite'),
                    true
                );
            }

            $this->annotationReader = new AnnotationReader($doctrineAnnotationReader, AnnotationReader::LAX_MODE);
        }

        return $this->annotationReader;
    }

    /**
     * Returns the array of globbed classes.
     * Only instantiable classes are returned.
     *
     * @return array<string,ReflectionClass<object>> Key: fully qualified class name
     */
    private function getClassList(string $namespace, int $globTtl = 2, bool $recursive = true): array
    {
        $explorer = new GlobClassExplorer(
            $namespace,
            $this->getPsr16Cache(),
            $globTtl,
            ClassNameMapper::createFromComposerFile(null, null, true),
            $recursive
        );
        $allClasses = $explorer->getClassMap();
        $classes = [];

        foreach ($allClasses as $className => $phpFile) {
            if (!class_exists($className, false)) {
                // Let's try to load the file if it was not imported yet.
                // We are importing the file manually to avoid triggering the autoloader.
                // The autoloader might trigger errors if the file does not respect PSR-4 or if the
                // Symfony DebugAutoLoader is installed. (see https://github.com/thecodingmachine/graphqlite/issues/216)
                require_once $phpFile;

                // @phpstan-ignore-next-line Does it exist now?
                if (!class_exists($className, false)) {
                    continue;
                }
            }

            $refClass = new ReflectionClass($className);

            if (!$refClass->isInstantiable()) {
                continue;
            }

            $classes[$className] = $refClass;
        }

        return $classes;
    }

    private function getCodeCache(): ClassBoundCacheContractInterface
    {
        if ($this->codeCache === null) {
            $this->codeCache = new ClassBoundCacheContract(
                new ClassBoundMemoryAdapter(new ClassBoundCache(new FileBoundCache($this->getPsr16Cache())))
            );
        }

        return $this->codeCache;
    }

    private function getListOfInjectedServices(ReflectionMethod $method, ContainerBuilder $container): array
    {
        $services = [];

        /**
         * @var Autowire[] $autowireAnnotations
         */
        $autowireAnnotations = $this->getAnnotationReader()->getMethodAnnotations($method, Autowire::class);

        $parametersByName = null;

        foreach ($autowireAnnotations as $autowire) {
            $target = $autowire->getTarget();

            if ($parametersByName === null) {
                $parametersByName = self::getParametersByName($method);
            }

            if (!isset($parametersByName[$target])) {
                throw new GraphQLException(
                    'In method '.$method->getDeclaringClass()->getName().'::'.$method->getName(
                    ).', the @Autowire annotation refers to a non existing parameter named "'.$target.'"'
                );
            }

            $id = $autowire->getIdentifier();
            if ($id !== null) {
                $services[$id] = $id;
            } else {
                $parameter = $parametersByName[$target];
                $type = $parameter->getType();

                if ($type !== null && $type instanceof ReflectionNamedType) {
                    $fqcn = $type->getName();

                    if ($container->has($fqcn)) {
                        $services[$fqcn] = $fqcn;
                    }
                }
            }
        }

        return $services;
    }

    private function getPsr16Cache(): CacheInterface
    {
        if ($this->cache === null) {
            if (ApcuAdapter::isSupported()) {
                $this->cache = new Psr16Cache(new ApcuAdapter('graphqlite_bundle'));
            } else {
                $this->cache = new Psr16Cache(new PhpFilesAdapter('graphqlite_bundle', 0, $this->cacheDir));
            }
        }

        return $this->cache;
    }

    private function makePublicInjectedServices(
        ReflectionClass  $refClass,
        AnnotationReader $reader,
        ContainerBuilder $container,
        bool             $isController
    ): void {
        $services = $this->getCodeCache()->get(
            $refClass,

            function () use ($refClass, $reader, $container, $isController): array {
                $services = [];

                foreach ($refClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    $field = $reader->getRequestAnnotation($method, Field::class)
                        ?? $reader->getRequestAnnotation($method, Query::class)
                        ?? $reader->getRequestAnnotation($method, Mutation::class);

                    if ($field !== null) {
                        if ($isController) {
                            $services[$refClass->getName()] = $refClass->getName();
                        }

                        $services += $this->getListOfInjectedServices($method, $container);

                        if ($field instanceof Field && $field->getPrefetchMethod() !== null) {
                            $services += $this->getListOfInjectedServices(
                                $refClass->getMethod($field->getPrefetchMethod()),
                                $container
                            );
                        }
                    }
                }

                return $services;
            }
        );

        foreach ($services as $service) {
            if ($container->hasAlias($service)) {
                $container->getAlias($service)->setPublic(true);
            } else {
                $container->getDefinition($service)->setPublic(true);
            }
        }
    }

    /**
     * Register a method call on SchemaFactory for each tagged service, passing the service in parameter.
     */
    private function mapAdderToTag(
        string           $tag,
        string           $methodName,
        ContainerBuilder $container,
        Definition       $schemaFactory
    ): void {
        $taggedServices = $container->findTaggedServiceIds($tag);

        foreach ($taggedServices as $id => $tags) {
            $schemaFactory->addMethodCall($methodName, [new Reference($id)]);
        }
    }

    private function registerController(string $controllerClassName, ContainerBuilder $container): void
    {
        $aggregateQueryProvider = $container->findDefinition(AggregateControllerQueryProviderFactory::class);
        $controllersList = $aggregateQueryProvider->getArgument(0);
        $controllersList[] = $controllerClassName;
        $aggregateQueryProvider->setArgument(0, $controllersList);
    }
}
