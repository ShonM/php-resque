<?php

namespace Resque\Model;

class Queue
{
    public $id;

    public function __construct($id)
    {
        $this->id = $id;
    }

    public function pop($queue)
    {
        $item = $this->getBackend()->lpop('queue:' . $queue);
        if (!$item) {
            return null;
        }

        return json_decode($item, true);
    }

    public function push($queue, $item)
    {
        $this->getBackend()->sadd('queues', $queue);
        $this->getBackend()->rpush('queue:' . $queue, json_encode($item));
    }

    public function size($queue)
    {
        return $this->getBackend()->llen('queue:' . $queue);
    }
}
