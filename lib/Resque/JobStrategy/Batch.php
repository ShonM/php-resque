<?php

/**
 * Processes > 1 jobs per fork
 * Is naive, will exit if there's no additional jobs in the queue
 */
class Resque_JobStrategy_Batch extends Resque_JobStrategy_Fork
{
    protected $jobsPerFork;

    public function __construct($jobsPerFork = 10)
    {
        $this->jobsPerFork = $jobsPerFork;
    }

    public function perform(Resque_Job $job)
    {
        $this->child = Resque::fork();

        // Forked and we're the child
        if ($this->child === 0) {
            // Perform the first job
            Resque_JobStrategy_InProcess::perform($job);

            // Perform additional jobs up to $jobsPerFork
            $processed = 0;
            while ($processed < $this->jobsPerFork) {
                // Eager break - don't wait for more jobs in-fork, just go back to the parent
                // On slow queues, this means forking every job - that's ok!
                // This entire strategy is meant as a pressure release technique
                if (!$job = $this->worker->reserve()) {
                    break;
                }

                $this->worker->workingOn($job);
                Resque_JobStrategy_InProcess::perform($job);
                $this->worker->doneWorking();

                $processed++;
            }

            exit(0);
        }

        // Parent process, sit and wait
        if($this->child > 0) {
            $status = 'Forked ' . $this->child . ' at ' . strftime('%F %T');
            $this->worker->updateProcLine($status);
            $this->worker->log($status, Resque_Worker::LOG_VERBOSE);

            // Wait until the child process finishes before continuing
            pcntl_wait($status);
            pcntl_wexitstatus($status);
        }

        $this->child = null;
    }

    public function shouldFork()
    {
        return true;
    }
}
