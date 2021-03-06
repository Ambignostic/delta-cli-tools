<?php

namespace DeltaCli\Script;

use DeltaCli\Exception\InvalidOptions as InvalidOptionsException;
use DeltaCli\Log\LogInterface;
use DeltaCli\Project;
use DeltaCli\Script;
use React\EventLoop\Factory as EventLoopFactory;
use React\EventLoop\LoopInterface;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class LogsWatch extends Script
{
    private $include = [];

    private $exclude = [];

    private $only;

    private $includeAll;

    public function __construct(Project $project)
    {
        parent::__construct(
            $project,
            'logs:watch',
            'Watch the logs on a remote environment.'
        );
    }

    public function setInclude(array $include)
    {
        $this->include = $include;

        return $this;
    }

    public function setExclude(array $exclude)
    {
        $this->exclude = $exclude;

        return $this;
    }

    public function setOnly($only)
    {
        $this->only = $only;

        return $this;
    }

    public function setIncludeAll($includeAll)
    {
        $this->includeAll = $includeAll;

        return $this;
    }

    protected function configure()
    {
        $this->requireEnvironment();

        $this
            ->addSetterOption(
                'include',
                null,
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Include a log not watched by default.'
            )
            ->addSetterOption(
                'exclude',
                null,
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Exclude a log that would otherwise be watched.'
            )
            ->addSetterOption('only', null, InputOption::VALUE_REQUIRED, 'Only watch the specified log.')
            ->addSetterOption('include-all', null, InputOption::VALUE_NONE, 'Watch all logs.');

        parent::configure();
    }

    protected function addSteps()
    {
        $this->validateOptions();

        $findLogsStep = $this->getProject()->findLogs();

        $this
            ->addStep($findLogsStep)
            ->addStep(
                'watch-logs',
                function () use ($findLogsStep) {
                    $loop   = EventLoopFactory::create();
                    $output = $this->getProject()->getOutput();
                    $logs   = $findLogsStep->getLogs();

                    $watchedLogs = $this->determineWhichLogsToWatch($logs);

                    foreach ($logs as $log) {
                        if (false === $this->logArrayHasLog($watchedLogs, $log)) {
                            $output->writeln(sprintf('<fg=cyan>Skipped %s.</>', $log->getName()));
                        }
                    }

                    $this->startLoop($loop, $watchedLogs);
                }
            );
    }

    private function startLoop(LoopInterface $loop, array $watchedLogs)
    {
        $output = $this->getProject()->getOutput();

        foreach ($watchedLogs as $log) {
            $log->attachToEventLoop($loop, $this->getProject()->getOutput());
        }

        if ('vagrant' !== $this->getEnvironment()->getName()) {
            $this->areYouStillWatching($loop, $watchedLogs);
        }

        if (count($watchedLogs)) {
            $loop->run();
        } else {
            $output->writeln('<error>No logs to watch.</error>');
        }
    }

    /**
     * @param LoopInterface $loop
     * @param LogInterface[] $logs
     */
    private function areYouStillWatching(LoopInterface $loop, array $logs)
    {
        $startTime = time();

        $durationBeforeAsking = (15 * 60); // 15 minutes

        $loop->addPeriodicTimer(
            15,
            function () use ($loop, $logs, &$startTime, $durationBeforeAsking) {
                if (time() - $durationBeforeAsking > $startTime) {
                    $this->stop($loop, $logs);

                    /* @var $helper QuestionHelper */
                    $helper = $this->getHelper('question');
                    $question = new ConfirmationQuestion('<fg=cyan>Are you still watching? [yes]</>' . PHP_EOL);

                    $answer = $helper->ask(
                        $this->getProject()->getInput(),
                        $this->getProject()->getOutput(),
                        $question
                    );

                    if ($answer) {
                        $this->getProject()->getOutput()->writeln('<fg=green>Enjoy!</>');
                        $this->startLoop($loop, $logs);
                    } else {
                        $this->getProject()->getOutput()->writeln('<comment>Shutting down log watchers.</comment>');
                    }
                }
            }
        );
    }

    /**
     * @param LoopInterface $loop
     * @param LogInterface[] $logs
     */
    private function stop(LoopInterface $loop, array $logs)
    {
        foreach ($logs as $log) {
            $log->stop();
        }

        $loop->stop();
    }

    /**
     * @param LogInterface[] $logs
     * @return LogInterface[]
     */
    private function determineWhichLogsToWatch(array $logs)
    {
        if ($this->includeAll) {
            return $logs;
        }

        if ($this->only) {
            foreach ($logs as $log) {
                if ($log->getName() === $this->only) {
                    return [$log];
                }
            }
        }

        $logsToWatch = [];

        foreach ($logs as $log) {
            if ($log->getWatchByDefault()) {
                $logsToWatch[] = $log;
            }
        }

        foreach ($logs as $log) {
            if (in_array($log->getName(), $this->include) && false === $this->logArrayHasLog($logsToWatch, $log)) {
                $logsToWatch[] = $log;
            }
        }

        foreach ($logs as $log) {
            if (!in_array($log->getName(), $this->exclude)) {
                continue;
            }

            $logIndex = $this->logArrayHasLog($logsToWatch, $log);

            if (false !== $logIndex) {
                unset($logsToWatch[$logIndex]);
            }
        }

        return $logsToWatch;
    }

    /**
     * @param LogInterface[] $log
     * @param LogInterface $log
     * @return integer|boolean
     */
    private function logArrayHasLog(array $logs, LogInterface $logToCheckFor)
    {
        foreach ($logs as $index => $log) {
            if ($log->getName() === $logToCheckFor->getName()) {
                return $index;
            }
        }

        return false;
    }

    private function validateOptions()
    {
        if ($this->only && $this->includeAll) {
            throw new InvalidOptionsException('Cannot use both only and include-all.');
        }

        if ($this->only && (count($this->include) || count($this->exclude))) {
            throw new InvalidOptionsException('Cannot use only and include/exclude logs.');
        }

        if ($this->includeAll && (count($this->include) || count($this->exclude))) {
            throw new InvalidOptionsException('Cannot use include-all and include/exclude logs.');
        }
    }
}
