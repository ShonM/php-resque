<?php

namespace Resque\Manager;

class QueueManager
{
    public $backend;

    public function __construct($backend)
    {
        $this->backend = $backend;
    }

    public function all()
    {
        $queues = $this->backend->smembers('queues');
        if (!is_array($queues)) {
            $queues = array();
        }

        return $queues;
    }
}
