<?php

namespace Resque\Model;

use Resque\Job\Strategy\Fork;
use Resque\Job\Strategy\InProcess;
use Resque\Job\Strategy\StrategyInterface;

class Worker
{
    public $id;

    public $hostname;

    public $queues = array();

    public $processed = 0;

    public $interval = 5;

    public $jobStrategy;

    public $currentJob;

    public $shutdown;

    public $paused;

    public function __construct($queues)
    {
        if (!is_array($queues)) {
            $queues = array($queues);
        }

        $this->queues = $queues;
        if (function_exists('gethostname')) {
            $hostname = gethostname();
        } else {
            $hostname = php_uname('n');
        }
        $this->hostname = $hostname;
        $this->id = $this->hostname . ':' . getmypid() . ':' . implode(',', $this->queues);
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function setJobStrategy(StrategyInterface $jobStrategy)
    {
        $this->jobStrategy = $jobStrategy;
        $this->jobStrategy->setWorker($this);
    }

    public function getJobStrategy()
    {
        if (! $this->jobStrategy) {
            if (function_exists('pcntl_fork')) {
                $this->setJobStrategy(new Fork);
            } else {
                $this->setJobStrategy(new InProcess);
            }
        }

        return $this->jobStrategy;
    }

    public function perform(Job $job)
    {
        try {
            $this->resque->getEvent()->trigger('afterFork', $job);
            $job->perform();
        } catch (\Exception $e) {
            // $this->log($job . ' failed: ' . $e->getMessage());
            $job->fail($e);

            return;
        }

        $job->updateStatus(Job\Status::STATUS_COMPLETE);
        // $this->log('Done ' . $job);
    }

    public function pause()
    {
        // $this->log('USR2 received; pausing job processing');
        $this->paused = true;
    }

    public function resume()
    {
        // $this->log('CONT received; resuming job processing');
        $this->paused = false;
    }

    public function shutdown()
    {
        // $this->log('Exiting...');
        $this->shutdown = true;
    }

    public function __toString()
    {
        return $this->id;
    }
}
