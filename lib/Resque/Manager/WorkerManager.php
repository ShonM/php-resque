<?php

namespace Resque\Manager;

use Resque\Model\Job;
use Resque\Model\Worker;
use Resque\Job\Status;
use Resque\Job\DirtyExitException;

class WorkerManager
{
    public $backend;

    public $jobManager;

    public $hostname;

    public function __construct($backend, $jobManager)
    {
        $this->backend = $backend;
        $this->jobManager = $jobManager;

        if (function_exists('gethostname')) {
            $hostname = gethostname();
        } else {
            $hostname = php_uname('n');
        }
        $this->hostname = $hostname;
    }

    public function prune()
    {
        $workerPids = $this->workerPids();
        $workers = $this->all();

        foreach ($workers as $worker) {
            if (is_object($worker)) {
                list($host, $pid) = explode(':', (string) $worker, 2);

                if ($host != $this->hostname || in_array($pid, $workerPids) || $pid == getmypid()) {
                    continue;
                }

                // $this->log('Pruning dead worker: ' . (string) $worker);
                $this->unregister($worker);
            }
        }
    }

    public function workerPids()
    {
        $pids = array();
        exec('ps -A -o pid,command | grep [r]esque', $cmdOutput);
        foreach ($cmdOutput as $line) {
            list($pids[],) = explode(' ', trim($line), 2);
        }

        return $pids;
    }

    public function all()
    {
        $workers = $this->backend->smembers('workers');
        if (!is_array($workers)) {
            $workers = array();
        }

        $instances = array();
        foreach ($workers as $workerId) {
            $instances[] = $this->find($workerId);
        }

        return $instances;
    }

    public function find($workerId)
    {
        if (!$this->exists($workerId) || false === strpos($workerId, ":")) {
            return false;
        }

        list(,,$queues) = explode(':', $workerId, 3);
        $queues = explode(',', $queues);

        $worker = new Worker($queues);
        $worker->setId($workerId);

        return $worker;
    }

    public function register(Worker $worker)
    {
        $this->backend->sadd('workers', (string) $worker);
        $this->backend->set('worker:' . (string) $worker . ':started', strftime('%a %b %d %H:%M:%S %Z %Y'));
    }

    public function unregister(Worker $worker)
    {
        if (is_object($worker->currentJob)) {
            // $worker->currentJob->fail(new Job\DirtyExitException);
            $this->jobManager->fail($worker->currentJob, new DirtyExitException);
        }

        $id = (string) $worker;
        $this->backend->srem('workers', $id);
        $this->backend->del('worker:' . $id);
        $this->backend->del('worker:' . $id . ':started');
        // $this->getStat()->clear('processed:' . $id);
        // $this->getStat()->clear('failed:' . $id);
    }

    public function exists($workerId)
    {
        return (bool) $this->backend->sismember('workers', $workerId);
    }

    public function workingOn(Worker $worker, Job $job)
    {
        $job->worker = $worker;
        $worker->currentJob = $job;
        // $job->updateStatus(Status::STATUS_RUNNING);

        $data = json_encode(array(
            'queue' => $job->queue,
            'run_at' => strftime('%a %b %d %H:%M:%S %Z %Y'),
            'payload' => $job->payload
        ));

        $this->backend->set('worker:' . $job->worker, $data);
    }

    public function work(Worker $worker, $interval = 5)
    {
        if (! is_null($interval)) {
            $worker->interval = $interval;
        }

        // $this->resque->getEvent()->trigger('beforeFirstFork', $this);

        while (true) {
            if ($worker->shutdown) {
                break;
            }

            // Attempt to find and reserve a job
            $job = false;
            if (!$worker->paused) {
                $job = $this->jobManager->reserve($worker->queues);
            }

            if (!$job) {
                // For an interval of 0, break now - helps with unit testing etc
                if ($interval == 0) {
                    break;
                }
                // If no job was found, we sleep for $interval before continuing and checking again
                // $this->log('Sleeping for ' . $interval);
                usleep($interval * 1000000);
                continue;
            }

            // $this->log('Received ' . $job);
            // $this->resque->getEvent()->trigger('beforeFork', $job);
            $this->workingOn($worker, $job);

            $worker->getJobStrategy()->perform($job);

            $worker->processed++;

            $this->doneWorking($worker);
        }

        $this->unregister($worker);
    }

    public function doneWorking(Worker $worker)
    {
        $worker->currentJob = null;
        // $this->resque->getStat()->incr('processed');
        // $this->resque->getStat()->incr('processed:' . (string) $this);
        $this->backend->del('worker:' . (string) $worker);
    }
}
