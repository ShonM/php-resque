<?php

namespace Resque;

/**
 * Resque worker that handles checking queues for jobs, fetching them
 * off the queues, running them and handling the result.
 *
 * @package		Resque/Worker
 * @author		Chris Boulton <chris@bigcommerce.com>
 * @license		http://www.opensource.org/licenses/mit-license.php
 */
class Worker
{
    /**
     * Set the ID of this worker to a given ID string.
     *
     * @param string $workerId ID for the worker.
     */
    public function setId($workerId)
    {
        $this->id = $workerId;
    }

    /**
     * Return an array containing all of the queues that this worker should use
     * when searching for jobs.
     *
     * If * is found in the list of queues, every queue will be searched in
     * alphabetic order. (@see $fetch)
     *
     * @param boolean $fetch If true, and the queue is set to *, will fetch
     * all queue names from the backend.
     * @return array Array of associated queues.
     */
    public function queues($fetch = true)
    {
        if (!in_array('*', $this->queues) || $fetch == false) {
            return $this->queues;
        }

        $queues = $this->resque->queues();
        sort($queues);

        return $queues;
    }

    /**
     * Notify the backend that we've finished working on a job, clearing the working
     * state and incrementing the job stats.
     */
    public function doneWorking()
    {
        $this->currentJob = null;
        $this->resque->getStat()->incr('processed');
        $this->resque->getStat()->incr('processed:' . (string) $this);
        $this->resque->getBackend()->del('worker:' . (string) $this);
    }

    /**
     * Return an object describing the job this worker is currently working on.
     *
     * @return object Object with details of current job.
     */
    public function job()
    {
        $job = $this->resque->getBackend()->get('worker:' . (string) $this);
        if (!$job) {
            return array();
        } else {
            return json_decode($job, true);
        }
    }

    /**
     * Get a statistic belonging to this worker.
     *
     * @param  string $stat Statistic to fetch.
     * @return int    Statistic value.
     */
    public function getStat($stat)
    {
        return $this->resque->getStat()->get($stat . ':' . (string) $this);
    }

    /**
     * @return int
     */
    public function getProcessed()
    {
        return $this->processed;
    }

    public function setInterval($interval)
    {
        $this->interval = $interval;
    }

    public function getInterval()
    {
        return $this->interval;
    }
}
