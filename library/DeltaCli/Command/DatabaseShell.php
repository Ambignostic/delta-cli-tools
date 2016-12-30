<?php

namespace DeltaCli\Command;

use DeltaCli\Command;
use DeltaCli\Debug;
use DeltaCli\Project;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DatabaseShell extends Command
{
    /**
     * @var Project
     */
    private $project;

    public function __construct(Project $project)
    {
        parent::__construct(null);

        $this->project = $project;
    }

    protected function configure()
    {
        $this
            ->setName('db:shell')
            ->setDescription('Open a database command-line shell.')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment where you want to open a shell.')
            ->addOption('hostname', null, InputOption::VALUE_REQUIRED, 'The specific hostname you want to connect to.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $env  = $this->project->getSelectedEnvironment();
        $host = $env->getSelectedHost($input->getOption('hostname'));

        $findDatabasesStep = $this->project->findDatabases()
            ->setSelectedEnvironment($env);

        $findDatabasesStep->run();

        $databases = $findDatabasesStep->getDatabases();

        if (0 === count($databases)) {
            echo 'No databases found.' . PHP_EOL;
            exit;
        } else if (1 < count($databases)) {
            echo 'More than one database found and no mechanism to select which one yet.' . PHP_EOL;
            exit;
        }

        $database = reset($databases);

        $tunnel = $host->getSshTunnel();
        $tunnel->setUp();

        $command = $tunnel->assembleSshCommand($database->getShellCommand(), '-t');
        Debug::log("Opening DB shell with `{$command}`...");
        passthru($command);

        $tunnel->tearDown();
    }
}
