<?php

namespace Resque;

class Platform
{
    public function fork()
    {
        if (!function_exists('pcntl_fork')) {
            return -1;
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('Unable to fork.');
        }

        return $pid;
    }
}
