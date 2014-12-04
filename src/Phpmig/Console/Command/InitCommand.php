<?php
/**
 * @package    Phpmig
 * @subpackage Phpmig\Console
 */
namespace Phpmig\Console\Command;

use Symfony\Component\Console\Input\InputInterface,
    Symfony\Component\Console\Output\OutputInterface,
    Symfony\Component\Console\Input\InputArgument;

/**
 * This file is part of phpmig
 *
 * Copyright (c) 2011 Dave Marshall <dave.marshall@atstsolutuions.co.uk>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Init command
 *
 * @author      Dave Marshall <david.marshall@bskyb.com>
 */
class InitCommand extends AbstractCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addOption('--directory', '-d', InputArgument::OPTIONAL, 'The directory to create the initialisation in.');
        $this->setName('init')
             ->setDescription('Initialise this directory for use with phpmig')
             ->setHelp(<<<EOT
The <info>init</info> command creates a skeleton bootstrap file, a propertyfile file and a migrations directory

<info>phpmig init</info>

EOT
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $cwd = getcwd();
        $directory = $input->getOption('directory');
        if (null === $directory) {
            $directory = $cwd;
        }
        
        $bootstrap = $directory . DIRECTORY_SEPARATOR . 'phpmig.php'; 
        $propertyfile = $directory . DIRECTORY_SEPARATOR . 'build.properties';
        $relative = 'migrations';
        
        $this->initMigrationsDir($directory . DIRECTORY_SEPARATOR . $relative, $output);
        $this->initMigrationsDir($directory . DIRECTORY_SEPARATOR . 'migrations.client', $output);
        $this->initBootstrap($bootstrap, $directory, $output);
        $this->initPropertiesFile($propertyfile, $directory, $output);
    }

    /**
     * Create migrations dir
     *
     * @param $path
     * @return void
     */
    protected function initMigrationsDir($migrations, OutputInterface $output)
    {
        if (file_exists($migrations) && is_dir($migrations)) {
            $output->writeln(
                '<info>--</info> ' .
                str_replace(getcwd(), '.', $migrations) . ' already exists -' .
                ' <comment>Place your migration files in here</comment>'
            );
            return;
        }

        if (false === mkdir($migrations)) {
            throw new \RuntimeException(sprintf('Could not create directory "%s"', $migrations));
        }

        $output->writeln(
            '<info>+d</info> ' .
            str_replace(getcwd(), '.', $migrations) . 
            ' <comment>Place your migration files in here</comment>'
        );
        return;
    }

    /**
     * Create propertyfile
     *
     * @param string $bootstrap where to put propertyfile file
     * @param string $migrations path to migrations dir relative to propertyfile
     * @return void
     */
    protected function initBootstrap($bootstrap, $migrations, OutputInterface $output)
    {
        if (file_exists($bootstrap)) {
            throw new \RuntimeException(sprintf('The file "%s" already exists', $bootstrap));
        }

        if (!is_writeable(dirname($bootstrap))) {
            throw new \RuntimeException(sprintf('THe file "%s" is not writeable', $bootstrap));
        }

        $contents = <<<PHP
<?php

define('TRACK_MIGRATIONS_IN_DB', true);

use \Phpmig\Adapter,
    \Phpmig\Pimple\Pimple,
    \Phpmig\Console\Command\AbstractCommand as PhpMig;

\$container = new Pimple();

\$container['db'] = \$container->share(function () use (\$props) {
    \$pdo = new PDO(sprintf('pgsql:dbname=%s;host=%s;password=%s', \$props['db.name'], \$props['db.host'], \$props['db.password']), \$props['db.user'], '');
    \$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return \$pdo;
});

if (TRACK_MIGRATIONS_IN_DB) {
    \$container['phpmig.adapter'] = \$container->share(function () use (\$container, \$props) {
        return new Adapter\PDO\SqlPgsql(\$container['db'], 'migrations', \$props['db.migration.schema']);
    });
} else {
    \$container['phpmig.adapter'] = \$container->share(function() {
        // replace this with a better Phpmig\Adapter\AdapterInterface 
        return new Adapter\File\Flat(__DIR__ . DIRECTORY_SEPARATOR . '$migrations/.migrations.log');
    });
}

\$container['phpmig.migrations'] = function () use (\$props) {
    return array(
        PhpMig::\$MIGTYPE_KEY_STANDARD => glob(\$props['migration.folder'] . '/*.php'),
        PhpMig::\$MIGTYPE_KEY_CUSTOM   => glob(\$props['migration.client.folder'] . '/*.php')
    );
};

\$container['phpmig.migrations.new'] = function() {
    return array(
        PhpMig::\$MIGTYPE_KEY_STANDARD => glob(\$props['migration.folder'] . '/*.php'),
        PhpMig::\$MIGTYPE_KEY_CUSTOM   => glob(\$props['migration.client.folder'] . '/*.php')            
    );
};


return \$container;
PHP;

        if (false === file_put_contents($bootstrap, $contents)) {
            throw new \RuntimeException('The file "%s" could not be written to', $bootstrap);
        }

        $output->writeln(
            '<info>+f</info> ' .
            str_replace(getcwd(), '.', $bootstrap) . 
            ' <comment>Create services in here</comment>'
        );
        return;
    }

    /**
     * Create propertyfile
     *
     * @param string $propertyfile where to put propertyfile file
     * @param string $migrations path to migrations dir relative to propertyfile
     * @return void
     */
    protected function initPropertiesFile($propertyfile, $migrations, OutputInterface $output)
    {
        if (file_exists($propertyfile)) {
            throw new \RuntimeException(sprintf('The file "%s" already exists', $propertyfile));
        }

        if (!is_writeable(dirname($propertyfile))) {
            throw new \RuntimeException(sprintf('THe file "%s" is not writeable', $propertyfile));
        }

        $contents = <<<PHP
db.user=postgres
db.name=your_database_name
db.host=localhost
db.password=password
db.migration.schema=sysops

migration.folder=migrations
migration.client.folder=migrations.client
PHP;

        if (false === file_put_contents($propertyfile, $contents)) {
            throw new \RuntimeException('THe file "%s" could not be written to', $propertyfile);
        }

        $output->writeln(
            '<info>+f</info> ' .
            str_replace(getcwd(), '.', $propertyfile) .
            ' <comment>Specify the properties here</comment>'
        );
        return;
    }

}


