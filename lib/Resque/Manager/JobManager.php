<?php

namespace Resque\Manager;

use Resque\Model\Job;

class JobManager
{
    public $backend;

    public function __construct($backend)
    {
        $this->backend = $backend;
    }

    public function reserve($queues = false)
    {
        $queues = $queues ?: array();
        if (!is_array($queues)) {
            return null;
        }

        foreach ($queues as $queue) {
            // $this->log('Checking ' . $queue);
            $job = $this->reserveJob($queue);
            if ($job) {
                // $this->log('Found job on ' . $queue);
                return $job;
            }
        }

        return false;
    }

    public function reserveJob($queue = '*')
    {
        $payload = $this->backend->pop($queue);

        if (!is_array($payload)) {
            return false;
        }

        return new Job($queue, $payload);
    }

    public function create($queue, $class, $args = null, $monitor = false)
    {
        if ($args !== null && !is_array($args)) {
            throw new \InvalidArgumentException(
                'Supplied $args must be an array.'
            );
        }

        $id = md5(uniqid('', true));
        $item = array(
            'class' => $class,
            'args'  => array($args),
            'id'    => $id,
        );

        $this->backend->sadd('queues', $queue);
        $this->backend->rpush('queue:' . $queue, json_encode($item));

        if ($monitor) {
            $status = new Status($this->resque, $id);
            $status->create();
        }

        return $id;
    }

    public function fail(Job $job, $exception)
    {
        // Event::trigger('onFailure', array(
        //     'exception' => $exception,
        //     'job' => $this,
        // ));

        // $this->updateStatus(Status::STATUS_FAILED);
        // Failure::create(
        //     $this->payload,
        //     $exception,
        //     $this->worker,
        //     $this->queue
        // );
        // Stat::incr('failed');
        // Stat::incr('failed:' . $this->worker);
    }
}
