<?php

namespace Resque;

use Resque\Model\Job;
use Resque\Model\Worker;

/**
 * Worker tests.
 *
 * @package		Resque/Tests
 * @author		Chris Boulton <chris@bigcommerce.com>
 * @license		http://www.opensource.org/licenses/mit-license.php
 */
class WorkerTest extends TestCase
{
    public $workerManager;

    public function setUp()
    {
        parent::setUp();

        $this->workerManager = $this->resque->getWorkerManager();
    }

    public function testWorkerRegistersInList()
    {
        $worker = new Worker('*');
        $this->workerManager->register($worker);

        // Make sure the worker is in the list
        $this->assertTrue((bool) $this->redis->sismember('resque:workers', (string) $worker));
    }

    public function testGetAllWorkers()
    {
        $num = 3;
        // Register a few workers
        for ($i = 0; $i < $num; ++$i) {
            $worker = new Worker('queue_' . $i);
            $this->workerManager->register($worker);
        }

        // Now try to get them
        $this->assertEquals($num, count($this->workerManager->all()));
    }

    public function testGetWorkerById()
    {
        $worker = new Worker('*');
        $this->workerManager->register($worker);

        $newWorker = $this->workerManager->find((string) $worker);
        $this->assertEquals((string) $worker, (string) $newWorker);
    }

    public function testWorkerCanUnregister()
    {
        $worker = new Worker('*');
        $this->workerManager->register($worker);
        $this->workerManager->unregister($worker);

        $this->assertFalse($this->workerManager->exists((string) $worker));
        $this->assertEquals(array(), $this->workerManager->all());
        $this->assertEquals(array(), $this->redis->smembers('resque:workers'));
    }

    public function testPausedWorkerDoesNotPickUpJobs()
    {
        $worker = new Worker('*');
        $worker->pause();
        $this->resque->enqueue('jobs', 'Test_Job');
        $this->workerManager->work($worker, 0);
        $this->workerManager->work($worker, 0);
        $this->assertEquals(0, $this->resque->getStat()->get('processed'));
    }

    public function testResumedWorkerPicksUpJobs()
    {
        $worker = new Worker('*');
        $worker->pause();
        $this->resque->enqueue('jobs', 'Test_Job');
        $this->workerManager->work($worker, 0);
        $this->assertEquals(0, $this->resque->getStat()->get('processed'));
        $worker->resume();
        $this->workerManager->work($worker, 0);
        $this->assertEquals(1, $this->resque->getStat()->get('processed'));
    }

    public function testWorkerCanWorkOverMultipleQueues()
    {
        $worker = new Worker(array(
            'queue1',
            'queue2'
        ));
        $this->workerManager->register($worker);
        $this->resque->enqueue('queue1', 'Test_Job_1');
        $this->resque->enqueue('queue2', 'Test_Job_2');

        $job = $this->resque->getJobManager()->reserve(array('queue1', 'queue2'));
        $this->assertEquals('queue1', $job->queue);

        $job = $this->resque->getJobManager()->reserve(array('queue1', 'queue2'));
        $this->assertEquals('queue2', $job->queue);
    }

    public function testWorkerWorksQueuesInSpecifiedOrder()
    {
        $worker = new Worker(array(
            'high',
            'medium',
            'low'
        ));
        $this->workerManager->register($worker);

        // Queue the jobs in a different order
        $this->resque->enqueue('low', 'Test_Job_1');
        $this->resque->enqueue('high', 'Test_Job_2');
        $this->resque->enqueue('medium', 'Test_Job_3');

        // Now check we get the jobs back in the right order
        $job = $this->resque->getJobManager()->reserve($worker->queues);
        $this->assertEquals('high', $job->queue);

        $job = $this->resque->getJobManager()->reserve($worker->queues);
        $this->assertEquals('medium', $job->queue);

        $job = $this->resque->getJobManager()->reserve($worker->queues);
        $this->assertEquals('low', $job->queue);
    }

    public function testWildcardQueueWorkerWorksAllQueues()
    {
        $worker = new Worker('*');
        $this->workerManager->register($worker);

        $this->resque->enqueue('queue1', 'Test_Job_1');
        $this->resque->enqueue('queue2', 'Test_Job_2');

        $job = $this->resque->getJobManager()->reserve(array('queue1', 'queue2'));
        $this->assertEquals('queue1', $job->queue);

        $job = $this->resque->getJobManager()->reserve(array('queue1', 'queue2'));
        $this->assertEquals('queue2', $job->queue);
    }

    public function testWorkerDoesNotWorkOnUnknownQueues()
    {
        $worker = new Worker('queue1');
        $this->workerManager->register($worker);
        $this->resque->enqueue('queue2', 'Test_Job');

        $this->assertFalse($job = $this->resque->getJobManager()->reserve($worker->queues));
    }

    public function testWorkerClearsItsStatusWhenNotWorking()
    {
        $this->resque->enqueue('jobs', 'Test_Job');
        $worker = new Worker('jobs');
        $job = $job = $this->resque->getJobManager()->reserve(array('jobs'));
        $this->workerManager->workingOn($worker, $job);
        $this->workerManager->doneWorking($worker, $job);
        $this->assertEquals(array(), $worker->currentJob);
    }

    public function testWorkerRecordsWhatItIsWorkingOn()
    {
        $worker = new Worker('jobs');
        $this->workerManager->register($worker);

        $payload = array(
            'class' => 'Test_Job'
        );
        $job = new Job('jobs', $payload);
        $this->workerManager->workingOn($worker, $job);

        $job = $worker->currentJob;
        $this->assertEquals('jobs', $job->queue);
        if (!isset($job->run_at)) {
            $this->fail('Job does not have run_at time');
        }
        $this->assertEquals($payload, $job->payload);
    }

    public function testWorkerErasesItsStatsWhenShutdown()
    {
        $this->resque->enqueue('jobs', 'Test_Job');
        $this->resque->enqueue('jobs', 'Invalid_Job');

        $worker = new Worker('jobs');
        $this->workerManager->work($worker, 0);
        $this->workerManager->work($worker, 0);

        $this->assertEquals(0, $this->resque->getStat('processed'));
        $this->assertEquals(0, $this->resque->worker->getStat('failed'));
    }

    public function testWorkerCleansUpDeadWorkersOnStartup()
    {
        // Register a good worker
        $goodWorker = new Worker('jobs');
        $this->workerManager->register($goodWorker);
        $workerId = explode(':', $goodWorker);

        // Register some bad workers
        $worker = new Worker('jobs');
        $worker->setId($workerId[0].':1:jobs');
        $this->workerManager->register($worker);

        $worker = new Worker(array('high', 'low'));
        $worker->setId($workerId[0].':2:high,low');
        $this->workerManager->register($worker);

        $this->assertEquals(3, count($this->workerManager->all()));

        $this->workerManager->prune();

        // There should only be $goodWorker left now
        $this->assertEquals(1, count($this->workerManager->all()));
    }

    public function testDeadWorkerCleanUpDoesNotCleanUnknownWorkers()
    {
        // Register a bad worker on this machine
        $worker = new Worker('jobs');
        $workerId = explode(':', $worker);
        $worker->setId($workerId[0].':1:jobs');
        $this->workerManager->register($worker);

        // Register some other false workers
        $worker = new Worker('jobs');
        $worker->setId('my.other.host:1:jobs');
        $this->workerManager->register($worker);

        $this->assertEquals(2, count($this->workerManager->all()));

        $this->workerManager->prune();

        // my.other.host should be left
        $workers = $this->workerManager->all();
        $this->assertEquals(1, count($workers));
        $this->assertEquals((string) $worker, (string) $workers[0]);
    }

    public function testWorkerFailsUncompletedJobsOnExit()
    {
        $worker = new Worker('jobs');
        $this->workerManager->register($worker);

        $payload = array(
            'class' => 'Test_Job'
        );
        $job = new Job('jobs', $payload);

        $this->workerManager->workingOn($worker, $job);
        $this->workerManager->unregister($worker);

        $this->assertEquals(1, $this->resque->getStat()->get('failed'));
    }
}
