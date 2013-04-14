<?php

namespace Resque;

class WorkerDaemon
{
    public $paused;

    public $shutdown;

    public $resque;

    /**
     * @param Resque $resque
     */
    public function __construct(Resque $resque)
    {
        $this->resque = $resque;
    }

    public function registerSigHandlers()
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        declare(ticks = 1);
        pcntl_signal(SIGTERM, array($this, 'kill'));
        pcntl_signal(SIGINT, array($this, 'kill'));
        pcntl_signal(SIGQUIT, array($this, 'shutdown'));
        pcntl_signal(SIGUSR1, array($this, 'killChild'));
        pcntl_signal(SIGUSR2, array($this, 'pause'));
        pcntl_signal(SIGCONT, array($this, 'resume'));
        pcntl_signal(SIGPIPE, array($this, 'reconnect'));
        $this->log('Registered signals');
    }

    public function pause()
    {
        $this->log('USR2 received; pausing job processing');
        $this->paused = true;
    }

    public function resume()
    {
        $this->log('CONT received; resuming job processing');
        $this->paused = false;
    }

    public function shutdown()
    {
        $this->shutdown = true;
        $this->log('Exiting...');
    }

    public function kill()
    {
        $this->shutdown();
        $this->killChild();
    }

    public function killChild()
    {
        if ($this->jobStrategy) {
            $this->jobStrategy->shutdown();
        }
    }

    public function reconnect()
    {
        $this->log('SIGPIPE received; attempting to reconnect');
        $this->resque->getBackend()->establishConnection();
    }
}
