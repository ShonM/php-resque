<?php

namespace Resque\Job\Strategy;

use Resque\Resque;
use Resque\Model\Worker;
use Resque\Model\Job;
use Resque\Platform;

class Fork extends InProcess
{
    public $child;

    public $worker;

    public function setWorker(Worker $worker)
    {
        $this->worker = $worker;
    }

    public function perform(Job $job)
    {
        $platform = new Platform;
        $this->child = $platform->fork();

        // Forked and we're the child. Run the job.
        if ($this->child === 0) {
            parent::perform($job);
            exit(0);
        }

        // Parent process, sit and wait
        if ($this->child > 0) {
            $status = 'Forked ' . $this->child . ' at ' . strftime('%F %T');
            // $this->worker->log($status);

            // Wait until the child process finishes before continuing
            pcntl_wait($status);
            $exitStatus = pcntl_wexitstatus($status);
            if ($exitStatus !== 0) {
                $job->fail(new Job\DirtyExitException(
                    'Job exited with exit code ' . $exitStatus
                ));
            }
        }

        $this->child = null;
    }

    public function shutdown()
    {
        if (!$this->child) {
            // $this->worker->log('No child to kill.');

            return;
        }

        // $this->worker->log('Killing child at '.$this->child);
        if (exec('ps -o pid,state -p ' . $this->child, $output, $returnCode) && $returnCode != 1) {
            // $this->worker->log('Killing child at ' . $this->child);
            posix_kill($this->child, SIGKILL);
            $this->child = null;
        } else {
            // $this->worker->log('Child ' . $this->child . ' not found, restarting.');
            $this->worker->shutdown();
        }
    }
}
