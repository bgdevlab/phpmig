<?php
/**
 * @package
 * @subpackage
 */
namespace Phpmig\Console\Command;

use Symfony\Component\Console\Command\Command,
    Symfony\Component\Console\Input\InputInterface,
    Symfony\Component\Console\Input\InputArgument,
    Symfony\Component\Console\Output\OutputInterface,
    Symfony\Component\Config\FileLocator,
    Phpmig\Migration\Migration,
    Phpmig\Migration\Migrator,
    Phpmig\Adapter\AdapterInterface;

/**
 * This file is part of phpmig
 *
 * Copyright (c) 2011 Dave Marshall <dave.marshall@atstsolutuions.co.uk>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Abstract command, contains bootstrapping info
 *
 * @author      Dave Marshall <david.marshall@atstsolutions.co.uk>
 */
abstract class AbstractCommand extends Command
{
    public static $MIGTYPE_KEY_STANDARD = '.';
    public static $MIGTYPE_KEY_CUSTOM = 'C';

    /**
     * @var \ArrayAccess
     */
    protected $container = null;

    /**
     * @var \Phpmig\Adapter\AdapterInterface
     */
    protected $adapter = null;

    /**
     * @var string
     */
    protected $bootstrap = null;

    /**
     * @var array
     */
    protected $migrations = array();

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addOption('--bootstrap', '-b', InputArgument::OPTIONAL, 'The bootstrap file to load');
        $this->addOption('--propertyfile', '-p', InputArgument::OPTIONAL, 'The custom properties file to load');
    }

    /**
     * Bootstrap phpmig
     *
     * @return void
     */
    protected function bootstrap(InputInterface $input, OutputInterface $output)
    {
        /**
         * Bootstrap
         */
        $bootstrap      = $input->getOption('bootstrap');
        $propertiesFile = $input->getOption('propertyfile');

        if (null === $bootstrap) {
            $bootstrap = 'phpmig.php';
        }

        if (null === $propertiesFile) {
            $propertiesFile = "build.properties";
        }

        $cwd = getcwd();

        $locator   = new FileLocator(array(
            $cwd . DIRECTORY_SEPARATOR . 'config',
        ));
        $bootstrap = $locator->locate($bootstrap, $cwd, $first = true);
        $this->setBootstrap($bootstrap);

        $props = $this->getCustomProperties($output, $propertiesFile, $cwd);

        /**
         * Prevent scope clashes
         */
        $func = function () use ($bootstrap, $props) {
            return require $bootstrap;
        };

        $container = $func();
        if (!($container instanceof \ArrayAccess)) {
            throw new \RuntimeException($bootstrap . " must return object of type \ArrayAccess");
        }
        $this->setContainer($container);

        /**
         * Adapter
         */
        if (!isset($container['phpmig.adapter'])) {
            throw new \RuntimeException($bootstrap . " must return container with service at phpmig.adapter");
        }

        $adapter = $container['phpmig.adapter'];

        if (!($adapter instanceof \Phpmig\Adapter\AdapterInterface)) {
            throw new \RuntimeException("phpmig.adapter must be an instance of \Phpmig\Adapter\AdapterInterface");
        }

        if (!$adapter->hasSchema()) {
            $adapter->createSchema();
        }

        $this->setAdapter($adapter);

        /**
         * Migrations
         */
        if (!isset($container['phpmig.migrations'])) {
            throw new \RuntimeException($bootstrap . " must return container with array at phpmig.migrations");
        }

        if ($this->usesMultipleMigrationPaths()) {
            // quick hack to track the associated array the migration file came from - used in display
            $migrations = array_merge(
                array_values($container['phpmig.migrations'][AbstractCommand::$MIGTYPE_KEY_CUSTOM]),
                array_values($container['phpmig.migrations'][AbstractCommand::$MIGTYPE_KEY_STANDARD])
            );
        } else {
            // traditional phpmig code.
            $migrations = $container['phpmig.migrations'];
        }


        if (!is_array($migrations)) {
            throw new \RuntimeException("phpmig.migrations must be an array of paths to migrations");
        }

        $versions = array();
        $names    = array();

        foreach ($migrations as $path) {
            if (!preg_match('/^[0-9]+/', basename($path), $matches)) {
                throw new \InvalidArgumentException(sprintf('The file "%s" does not have a valid migration filename', $path));
            }

            $version = $matches[0];

            if (isset($versions[$version])) {
                throw new \InvalidArgumentException(sprintf('Duplicate migration, "%s" has the same version as "%s"', $path, $versions[$version]));
            }

            $class = preg_replace('/^[0-9]+_/', '', basename($path));
            $class = str_replace('_', ' ', $class);
            $class = ucwords($class);
            $class = str_replace(' ', '', $class);
            if (false !== strpos($class, '.')) {
                $class = substr($class, 0, strpos($class, '.'));
            }

            if (isset($names[$class])) {
                throw new \InvalidArgumentException(sprintf(
                    'Migration "%s" has the same name as "%s"',
                    $path,
                    $names[$class]
                ));
            }
            $names[$class] = $path;

            require_once $path;
            if (!class_exists($class)) {
                throw new \InvalidArgumentException(sprintf(
                    'Could not find class "%s" in file "%s"',
                    $class,
                    $path
                ));
            }

            $migration = new $class($version);

            if (!($migration instanceof Migration)) {
                throw new \InvalidArgumentException(sprintf(
                    'The class "%s" in file "%s" must extend \Phpmig\Migration\Migration',
                    $class,
                    $path
                ));
            }

            $migration->setOutput($output); // inject output

            $versions[$version] = $migration;

        }

        ksort($versions);

        /**
         * Setup migrator
         */
        $container['phpmig.migrator'] = $container->share(function () use ($container, $adapter, $output) {
            return new Migrator($adapter, $container, $output);
        });

        $this->setMigrations($versions);
    }

    protected
    function usesMultipleMigrationPaths()
    {
        $container = $this->getContainer();
        if (!isset($container['phpmig.migrations'])) {
            return false;
        }
        if (array_key_exists(AbstractCommand::$MIGTYPE_KEY_STANDARD, $container['phpmig.migrations']) &&
            array_key_exists(AbstractCommand::$MIGTYPE_KEY_CUSTOM, $container['phpmig.migrations'])
        ) {
            return true;
        }

        return false;
    }


    protected
    function getKeyName($migrationName)
    {
        $container  = $this->getContainer();
        $migrations = $container['phpmig.migrations'];

        foreach ($migrations as $type => $files) {

            foreach ($files as $file) {

                if (strpos($file, $migrationName) !== FALSE) {
                    return $type;
                }
            }
        }

        return "?";

    }

    /**
     * Set bootstrap
     *
     * @var string
     * @return AbstractCommand
     */
    public
    function setBootstrap($bootstrap)
    {
        $this->bootstrap = $bootstrap;

        return $this;
    }

    /**
     * Get bootstrap
     *
     * @return string
     */
    public
    function getBootstrap()
    {
        return $this->bootstrap;
    }

    /**
     * Set migrations
     *
     * @param array $migrations
     * @return AbstractCommand
     */
    public
    function setMigrations(array $migrations)
    {
        $this->migrations = $migrations;

        return $this;
    }

    /**
     * Get migrations
     *
     * @return array
     */
    public
    function getMigrations()
    {
        return $this->migrations;
    }

    /**
     * Set container
     *
     * @var \ArrayAccess
     * @return AbstractCommand
     */
    public
    function setContainer(\ArrayAccess $container)
    {
        $this->container = $container;

        return $this;
    }

    /**
     * Get container
     *
     * @return \ArrayAccess
     */
    public
    function getContainer()
    {
        return $this->container;
    }

    /**
     * Set adapter
     *
     * @param AdapterInterface $adapter
     * @return AbstractCommand
     */
    public
    function setAdapter(AdapterInterface $adapter)
    {
        $this->adapter = $adapter;

        return $this;
    }

    /**
     * Get Adapter
     *
     * @return AdapterInterface
     */
    public
    function getAdapter()
    {
        return $this->adapter;
    }

    /**
     * Custom  property load
     * @param OutputInterface $output
     * @param                 $propertiesFile
     * @param                 $directory
     * @return mixed
     * @throws \RuntimeException
     */
    protected
    function getCustomProperties(OutputInterface $output, $propertiesFile, $directory)
    {
        $configLocator = new FileLocator(array(
            dirname($propertiesFile),
            $directory . DIRECTORY_SEPARATOR . 'config',
        ));

        $propertiesFile        = $configLocator->locate(basename($propertiesFile), dirname($propertiesFile), $first = true);
        $props                 = parse_ini_file($propertiesFile);
        $props['propertyfile'] = $propertiesFile;

        $mandatoryProps = array('db.name'                 => 'the database name',
                                'db.host'                 => 'the database host',
                                'db.user'                 => 'the database username',
                                'db.migration.schema'     => 'the database schema where the migrations table is stored.',
                                'migration.folder'        => 'standard migrations folder',
                                'migration.client.folder' => 'client-site specific migrations folder');

        $explanationList = null;
        foreach ($mandatoryProps as $keyCheck => $explanation) {
            if (!isset($props[$keyCheck]) || empty($props[$keyCheck])) {
                $explanationList .= sprintf("\tmissing key (or empty value) '%s' - %s\n", $keyCheck, $explanation);
            }
        }

        if ($explanationList != null) {
            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                print_r($props);
            }
            throw new \RuntimeException("!! The property file $propertiesFile is invalid.\n" . $explanationList);
        }

        /**
         * Check the properties loaded in current dir OR for absolute path for the migration folders...
         * transform any relative directories to absolute
         * don't progress if they are empty ( locate throws an exception ).
         */
        $migLocator = new FileLocator(array($directory));
        try {
            $props['migration.folder']        = $migLocator->locate($props['migration.folder']);
            $props['migration.client.folder'] = $migLocator->locate($props['migration.client.folder']);

        } catch (\InvalidArgumentException $e) {
            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                print_r($props);
            }
            throw $e;
        }

        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            print_r($props);
        }

        return $props;
    }

}


