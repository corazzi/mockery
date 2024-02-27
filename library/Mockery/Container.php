<?php

/**
 * Mockery (https://docs.mockery.io/)
 *
 * @copyright https://github.com/mockery/mockery/blob/HEAD/COPYRIGHT.md
 * @license   https://github.com/mockery/mockery/blob/HEAD/LICENSE BSD 3-Clause License
 * @link      https://github.com/mockery/mockery for the canonical source repository
 */

namespace Mockery;

use Mockery;
use Mockery\Exception\InvalidOrderException;
use Mockery\Exception\RuntimeException;
use Mockery\Generator\Generator;
use Mockery\Generator\MockConfigurationBuilder;
use Mockery\Loader\Loader as LoaderInterface;
use ReflectionClass;
use ReflectionException;
use Throwable;

class Container
{
    public const BLOCKS = Mockery::BLOCKS;

    /**
     * Order number of allocation
     *
     * @var int
     */
    protected $_allocatedOrder = 0;

    /**
     * Current ordered number
     *
     * @var int
     */
    protected $_currentOrder = 0;

    /**
     * @var Generator
     */
    protected $_generator;

    /**
     * Ordered groups
     *
     * @var array<string,int>
     */
    protected $_groups = [];

    /**
     * @var LoaderInterface
     */
    protected $_loader;

    /**
     * Store of mock objects
     *
     * @var array<class-string<LegacyMockInterface|MockInterface>|int,LegacyMockInterface|MockInterface>
     */
    protected $_mocks = [];

    /**
     * @var array<string,string>
     */
    protected $_namedMocks = [];

    /**
     * @var Instantiator
     */
    protected $instantiator;

    public function __construct(Generator $generator = null, LoaderInterface $loader = null, Instantiator $instantiator = null)
    {
        $this->_generator = $generator ?: Mockery::getDefaultGenerator();
        $this->_loader = $loader ?: Mockery::getDefaultLoader();
        $this->instantiator = $instantiator ?: new Instantiator();
    }

    /**
     * Return a specific remembered mock according to the array index it
     * was stored to in this container instance
     *
     * @return null|LegacyMockInterface|MockInterface
     */
    public function fetchMock($reference)
    {
        return $this->_mocks[$reference] ?? null;
    }

    /**
     * @return Generator
     */
    public function getGenerator()
    {
        return $this->_generator;
    }

    /**
     * @param  string      $method
     * @param  string      $parent
     * @return string|null
     */
    public function getKeyOfDemeterMockFor($method, $parent)
    {
        $keys = array_keys($this->_mocks);

        $match = preg_grep('/__demeter_' . md5($parent) . "_{$method}$/", $keys);

        if (count($match) === 1) {
            $res = array_values($match);

            if (count($res) > 0) {
                return $res[0];
            }
        }

        return null;
    }

    /**
     * @return LoaderInterface
     */
    public function getLoader()
    {
        return $this->_loader;
    }

    /**
     * @return array<class-string<LegacyMockInterface|MockInterface>|int,LegacyMockInterface|MockInterface>
     */
    public function getMocks()
    {
        return $this->_mocks;
    }

    public function instanceMock()
    {
    }

    /**
     * see http://php.net/manual/en/language.oop5.basic.php
     * @param  string $className
     * @return bool
     */
    public function isValidClassName($className)
    {
        if ($className[0] === '\\') {
            $className = substr($className, 1); // remove the first backslash
        }
        // all the namespaces and class name should match the regex
        return array_filter(
            explode('\\', $className),
            static function ($name): bool {
                return ! preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/', $name);
            }
        ) === [];
    }

    /**
     * Generates a new mock object for this container
     *
     * I apologies in advance for this. A God Method just fits the API which
     * doesn't require differentiating between classes, interfaces, abstracts,
     * names or partials - just so long as it's something that can be mocked.
     * I'll refactor it one day so it's easier to follow.
     *
     * @template TMock of LegacyMockInterface&MockInterface&object
     *
     * @param array|string ...$args
     *
     * @return TMock
     * @throws RuntimeException|ReflectionException
     */
    public function mock(...$args)
    {
        /** @var null|MockConfigurationBuilder $builder */
        $builder = null;
        /** @var null|callable $expectationClosure */
        $expectationClosure = null;
        $partialMethods = null;
        $quickDefinitions = [];
        $constructorArgs = null;
        $blocks = [];

        if (count($args) > 1) {
            $finalArg = array_pop($args);

            if (is_callable($finalArg) && is_object($finalArg)) {
                $expectationClosure = $finalArg;
            } else {
                $args[] = $finalArg;
            }
        }

        foreach ($args as $k => $arg) {
            if ($arg instanceof MockConfigurationBuilder) {
                $builder = $arg;

                unset($args[$k]);
            }
        }

        reset($args);

        $builder = $builder ?? new MockConfigurationBuilder();
        $mockeryConfiguration = Mockery::getConfiguration();
        $builder->setParameterOverrides($mockeryConfiguration->getInternalClassMethodParamMaps());
        $builder->setConstantsMap($mockeryConfiguration->getConstantsMap());

        while (count($args) > 0) {
            $arg = array_shift($args);

            // check for multiple interfaces
            if (is_string($arg)) {
                foreach (explode('|', $arg) as $type) {
                    if ($arg === 'null') {
                        // skip PHP 8 'null's
                        continue;
                    }

                    if (strpos($type, ',') && ! strpos($type, ']')) {
                        $interfaces = explode(',', str_replace(' ', '', $type));

                        $builder->addTargets($interfaces);

                        continue;
                    }

                    if (strpos($type, 'alias:') === 0) {
                        $type = str_replace('alias:', '', $type);

                        $builder->addTarget('stdClass');
                        $builder->setName($type);

                        continue;
                    }

                    if (strpos($type, 'overload:') === 0) {
                        $type = str_replace('overload:', '', $type);

                        $builder->setInstanceMock(true);
                        $builder->addTarget('stdClass');
                        $builder->setName($type);

                        continue;
                    }

                    if ($type[strlen($type) - 1] === ']') {
                        $parts = explode('[', $type);

                        $class = $parts[0];

                        if (
                            ! class_exists($class, true)
                            && ! interface_exists($class, true)
                        ) {
                            throw new Exception('Can only create a partial mock from'
                            . ' an existing class or interface');
                        }

                        $builder->addTarget($class);

                        $partialMethods = array_filter(
                            explode(
                                ',',
                                strtolower(
                                    rtrim(
                                        str_replace(
                                            ' ',
                                            '',
                                            $parts[1]
                                        ),
                                        ']'
                                    )
                                )
                            )
                        );

                        foreach ($partialMethods as $partialMethod) {
                            if ($partialMethod[0] === '!') {
                                $builder->addBlackListedMethod(substr($partialMethod, 1));

                                continue;
                            }

                            $builder->addWhiteListedMethod($partialMethod);
                        }

                        continue;
                    }

                    if (! $this->isValidClassName($type)) {
                        throw new Exception('Class name contains invalid characters');
                    }

                    if (
                        class_exists($type, true)
                        || interface_exists($type, true)
                        || trait_exists($type, true)
                    ) {
                        $builder->addTarget($type);

                        continue;
                    }

                    if (! $mockeryConfiguration->mockingNonExistentMethodsAllowed()) {
                        throw new Exception("Mockery can't find '{$type}' so can't mock it");
                    }

                    $builder->addTarget($type);

                    // unions are "sum" types and not "intersections", and so we must only process the first part
                    break;
                }

                continue;
            }

            if (is_object($arg)) {
                $builder->addTarget($arg);

                continue;
            }

            if (is_array($arg)) {
                if ($arg !== [] && array_keys($arg) !== range(0, count($arg) - 1)) {
                    // if associative array
                    if (array_key_exists(self::BLOCKS, $arg)) {
                        $blocks = $arg[self::BLOCKS];
                    }

                    unset($arg[self::BLOCKS]);

                    $quickDefinitions = $arg;
                } else {
                    $constructorArgs = $arg;
                }

                continue;
            }

            throw new Exception(sprintf(
                'Unable to parse arguments sent to %s::mock()',
                static::class
            ));
        }

        $builder->addBlackListedMethods($blocks);

        if ($constructorArgs !== null) {
            $builder->addBlackListedMethod('__construct'); // we need to pass through
        } else {
            $builder->setMockOriginalDestructor(true);
        }

        if ($partialMethods !== null && $constructorArgs === null) {
            $constructorArgs = [];
        }

        $config = $builder->getMockConfiguration();

        $this->checkForNamedMockClashes($config);

        $def = $this->getGenerator()->generate($config);

        $className = $def->getClassName();
        if (class_exists($className, $attemptAutoload = false)) {
            $rfc = new ReflectionClass($className);
            if (! $rfc->implementsInterface(LegacyMockInterface::class)) {
                throw new RuntimeException("Could not load mock {$className}, class already exists");
            }
        }

        $this->getLoader()->load($def);

        $mock = $this->_getInstance($className, $constructorArgs);
        $mock->mockery_init($this, $config->getTargetObject(), $config->isInstanceMock());

        if ($quickDefinitions !== []) {
            if ($mockeryConfiguration->getQuickDefinitions()->shouldBeCalledAtLeastOnce()) {
                $mock->shouldReceive($quickDefinitions)->atLeast()->once();
            } else {
                $mock->shouldReceive($quickDefinitions)->byDefault();
            }
        }

        if ($expectationClosure !== null) {
            $expectationClosure($mock);
        }

        return $this->rememberMock($mock);
    }

    /**
     * Fetch the next available allocation order number
     *
     * @return int
     */
    public function mockery_allocateOrder()
    {
        return ++$this->_allocatedOrder;
    }

    /**
     * Reset the container to its original state
     */
    public function mockery_close()
    {
        foreach ($this->_mocks as $mock) {
            $mock->mockery_teardown();
        }
        $this->_mocks = [];
    }

    /**
     * Get current ordered number
     *
     * @return int
     */
    public function mockery_getCurrentOrder()
    {
        return $this->_currentOrder;
    }

    /**
     * Gets the count of expectations on the mocks
     *
     * @return int
     */
    public function mockery_getExpectationCount()
    {
        $count = 0;
        foreach ($this->_mocks as $mock) {
            $count += $mock->mockery_getExpectationCount();
        }
        return $count;
    }

    /**
     * Fetch array of ordered groups
     *
     * @return array<string,int>
     */
    public function mockery_getGroups()
    {
        return $this->_groups;
    }

    /**
     * Set current ordered number
     *
     * @param  int $order
     * @return int The current order number that was set
     */
    public function mockery_setCurrentOrder($order)
    {
        return $this->_currentOrder = $order;
    }

    /**
     * Set ordering for a group
     *
     * @param string $group
     * @param int    $order
     */
    public function mockery_setGroup($group, $order)
    {
        $this->_groups[$group] = $order;
    }

    /**
     *  Tear down tasks for this container
     *
     * @throws \Exception
     */
    public function mockery_teardown()
    {
        try {
            $this->mockery_verify();
        } catch (\Exception $e) {
            $this->mockery_close();
            throw $e;
        }
    }

    /**
     * Retrieves all exceptions thrown by mocks
     *
     * @return array<Throwable>
     */
    public function mockery_thrownExceptions()
    {
        /** @var array<Throwable> $exceptions */
        $exceptions = [];

        foreach ($this->_mocks as $mock) {
            foreach ($mock->mockery_thrownExceptions() as $exception) {
                $exceptions[] = $exception;
            }
        }

        return $exceptions;
    }

    /**
     * Validate the current mock's ordering
     *
     * @param  string    $method
     * @param  int       $order
     * @throws Exception
     */
    public function mockery_validateOrder($method, $order, LegacyMockInterface $mock)
    {
        if ($order < $this->_currentOrder) {
            $exception = new InvalidOrderException(
                sprintf(
                    'Method %s called out of order: expected order %d, was %d',
                    $method,
                    $order,
                    $this->_currentOrder
                )
            );

            $exception->setMock($mock)
                ->setMethodName($method)
                ->setExpectedOrder($order)
                ->setActualOrder($this->_currentOrder);

            throw $exception;
        }
        $this->mockery_setCurrentOrder($order);
    }

    /**
     * Verify the container mocks
     */
    public function mockery_verify()
    {
        foreach ($this->_mocks as $mock) {
            $mock->mockery_verify();
        }
    }

    /**
     * Store a mock and set its container reference
     *
     * @param  LegacyMockInterface|MockInterface $mock
     * @return LegacyMockInterface|MockInterface
     */
    public function rememberMock(LegacyMockInterface $mock)
    {
        $class = get_class($mock);

        if (! array_key_exists($class, $this->_mocks)) {
            return $this->_mocks[$class] = $mock;
        }

        /**
         * This condition triggers for an instance mock where origin mock
         * is already remembered
         */
        return $this->_mocks[] = $mock;
    }

    /**
     * Retrieve the last remembered mock object, which is the same as saying
     * retrieve the current mock being programmed where you have yet to call
     * mock() to change it - thus why the method name is "self" since it will be
     * be used during the programming of the same mock.
     *
     * @return LegacyMockInterface|MockInterface
     */
    public function self()
    {
        $mocks = array_values($this->_mocks);
        $index = count($mocks) - 1;
        return $mocks[$index];
    }

    /**
     * @template TMock of LegacyMockInterface&MockInterface&object
     *
     * @param class-string<TMock>   $mockName
     * @param array<int,mixed>|null $constructorArgs
     *
     * @return TMock
     */
    protected function _getInstance($mockName, $constructorArgs = null)
    {
        if ($constructorArgs !== null) {
            return (new ReflectionClass($mockName))->newInstanceArgs($constructorArgs);
        }

        try {
            /** @var TMock $instance */
            $instance = $this->instantiator->instantiate($mockName);
        } catch (\Exception $ex) {
            /** @var class-string<TMock> $internalMockName */
            $internalMockName = $mockName . '_Internal';

            if (! class_exists($internalMockName)) {
                eval(sprintf(
                    'class %s extends %s { public function __construct() {} }',
                    $internalMockName,
                    $mockName
                ));
            }

            $instance = new $internalMockName();
        }

        return $instance;
    }

    protected function checkForNamedMockClashes($config)
    {
        $name = $config->getName();

        if ($name === null) {
            return;
        }

        $hash = $config->getHash();

        if (array_key_exists($name, $this->_namedMocks) && $hash !== $this->_namedMocks[$name]) {
            throw new Exception(
                "The mock named '{$name}' has been already defined with a different mock configuration"
            );
        }

        $this->_namedMocks[$name] = $hash;
    }
}
