<?php

declare(strict_types=1);

namespace Doctrine\Bundle\MongoDBBundle\Loader;

use Doctrine\Bundle\MongoDBBundle\DependencyInjection\Compiler\FixturesCompilerPass;
use Doctrine\Bundle\MongoDBBundle\Fixture\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\DataFixtures\Loader;
use LogicException;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

use function array_key_exists;
use function array_values;
use function get_class;
use function sprintf;
use function trigger_deprecation;

final class SymfonyFixturesLoader extends Loader implements SymfonyFixturesLoaderInterface
{
    /** @var FixtureInterface[] */
    private array $loadedFixtures = [];

    /** @var array<string, array<string, bool>> */
    private array $groupsFixtureMapping = [];

    public function __construct(
        private ContainerInterface $container,
    ) {
    }

    /**
     * @internal
     *
     * @param array $fixtures
     */
    public function addFixtures(array $fixtures): void
    {
        // Because parent::addFixture may call $this->createFixture
        // we cannot call $this->addFixture in this loop
        foreach ($fixtures as $fixture) {
            $class                        = get_class($fixture['fixture']);
            $this->loadedFixtures[$class] = $fixture['fixture'];
            $this->addGroupsFixtureMapping($class, $fixture['groups']);
        }

        // Now that all fixtures are in the $this->loadedFixtures array,
        // it is safe to call $this->addFixture in this loop
        foreach ($this->loadedFixtures as $fixture) {
            $this->addFixture($fixture);
        }
    }

    public function addFixture(FixtureInterface $fixture): void
    {
        $class                        = $fixture::class;
        $this->loadedFixtures[$class] = $fixture;

        $reflection = new ReflectionClass($fixture);
        $this->addGroupsFixtureMapping($class, [$reflection->getShortName()]);

        if ($fixture instanceof FixtureGroupInterface) {
            $this->addGroupsFixtureMapping($class, $fixture::getGroups());
        }

        if ($fixture instanceof ContainerAwareInterface) {
            trigger_deprecation('doctrine/mongodb-odm-bundle', '4.7', 'Implementing "%s" with "%s" is deprecated, use dependency injection instead.', ContainerAwareInterface::class, FixtureInterface::class);

            $fixture->setContainer($this->container);
        }

        parent::addFixture($fixture);
    }

    /**
     * Overridden to not allow new fixture classes to be instantiated.
     *
     * @param string $class
     *
     * @return FixtureInterface
     */
    protected function createFixture($class)
    {
        /*
         * We don't actually need to create the fixture. We just
         * return the one that already exists.
         */

        if (! isset($this->loadedFixtures[$class])) {
            throw new LogicException(sprintf(
                'The "%s" fixture class is trying to be loaded, but is not available. Make sure this class is defined as a service and tagged with "%s".',
                $class,
                FixturesCompilerPass::FIXTURE_TAG,
            ));
        }

        return $this->loadedFixtures[$class];
    }

    /**
     * Returns the array of data fixtures to execute.
     *
     * @param string[] $groups
     *
     * @return FixtureInterface[]
     */
    public function getFixtures(array $groups = [])
    {
        $fixtures = parent::getFixtures();

        if (empty($groups)) {
            return $fixtures;
        }

        $filteredFixtures = [];
        foreach ($fixtures as $fixture) {
            foreach ($groups as $group) {
                $fixtureClass = $fixture::class;
                if (isset($this->groupsFixtureMapping[$group][$fixtureClass])) {
                    $filteredFixtures[$fixtureClass] = $fixture;

                    continue 2;
                }
            }
        }

        foreach ($filteredFixtures as $fixture) {
            $this->validateDependencies($filteredFixtures, $fixture);
        }

        return array_values($filteredFixtures);
    }

    /**
     * Generates an array of the groups and their fixtures
     *
     * @param string[] $groups
     */
    private function addGroupsFixtureMapping(string $className, array $groups): void
    {
        foreach ($groups as $group) {
            $this->groupsFixtureMapping[$group][$className] = true;
        }
    }

    /** @param array<class-string<FixtureInterface>, FixtureInterface> $fixtures An array of fixtures with class names as keys */
    private function validateDependencies(array $fixtures, FixtureInterface $fixture)
    {
        if (! $fixture instanceof DependentFixtureInterface) {
            return;
        }

        $dependenciesClasses = $fixture->getDependencies();

        foreach ($dependenciesClasses as $class) {
            if (! array_key_exists($class, $fixtures)) {
                throw new RuntimeException(sprintf('Fixture "%s" was declared as a dependency for fixture "%s", but it was not included in any of the loaded fixture groups.', $class, $fixture::class));
            }
        }
    }
}
