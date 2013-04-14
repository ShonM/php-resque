<?php

namespace Resque;

use Resque\Backend\RedisBackend;
use Resque\Backend\BackendInterface;
use Resque\Manager\JobManager;
use Resque\Manager\QueueManager;
use Resque\Manager\WorkerManager;
use Resque\Model\Job;

class Resque
{
    public $stat;
    public $event;
    public $backend;
    public $jobManager;
    public $queueManager;
    public $workerManager;

    public function __construct(array $config = array())
    {
        $this->config = $config;
    }

    public function getStat()
    {
        if (! $this->stat) {
            $this->stat = new Stat($this->getBackend());
        }

        return $this->stat;
    }

    public function getEvent()
    {
        if (! $this->event) {
            $this->event = new Event;
        }

        return $this->event;
    }

    public function setBackend(BackendInterface $backend)
    {
        $this->backend = $backend;
    }

    public function getBackend()
    {
        if (! $this->backend) {
            $this->backend = new RedisBackend($this->config ?: array(
                'server' => 'localhost:6379',
            ));
        }

        return $this->backend;
    }

    public function getJobManager()
    {
        if (! $this->jobManager) {
            $this->jobManager = new JobManager($this->getBackend());
        }

        return $this->jobManager;
    }

    public function getQueueManager()
    {
        if (! $this->queueManager) {
            $this->queueManager = new QueueManager($this->getBackend());
        }

        return $this->queueManager;
    }

    public function getWorkerManager()
    {
        if (! $this->workerManager) {
            $this->workerManager = new WorkerManager($this->getBackend(), $this->getJobManager());
        }

        return $this->workerManager;
    }

    public function enqueue($queue, $class, $arguments = array(), $trackStatus = false)
    {
        $job = new Job($queue, $class, $arguments);
        $result = $this->getJobManager()->create($queue, $class, $arguments, $trackStatus);

        if ($result) {
            $this->getEvent()->trigger('afterEnqueue', array(
                'class' => $class,
                'args'  => $arguments,
                'queue' => $queue,
            ));
        }

        return $result;
    }
}
