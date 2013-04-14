<?php

namespace Resque\Model;

class Job
{
    public $queue;

    public $class;

    public $arguments;

    public $payload;

    public function __construct($queue, $payload)//$class, $arguments)
    {
        $this->queue = $queue;
        $this->payload = $payload;
        // $this->class = $class;
        // $this->arguments = $arguments;
    }

    public function fail()
    {

    }
}
