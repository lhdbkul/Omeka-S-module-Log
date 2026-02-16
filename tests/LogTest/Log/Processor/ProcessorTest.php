<?php declare(strict_types=1);

namespace LogTest\Log\Processor;

use Log\Log\Processor\JobId;
use Log\Log\Processor\UserId;
use Omeka\Entity\Job;
use Omeka\Entity\User;
use PHPUnit\Framework\TestCase;

class ProcessorTest extends TestCase
{
    public function testUserIdProcessorAddsUserIdToEvent(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(42);

        $processor = new UserId($user);
        $event = ['message' => 'test'];
        $result = $processor->process($event);

        $this->assertArrayHasKey('extra', $result);
        $this->assertEquals(42, $result['extra']['userId']);
    }

    public function testUserIdProcessorHandlesNullUser(): void
    {
        $processor = new UserId(null);
        $event = ['message' => 'test'];
        $result = $processor->process($event);

        $this->assertArrayHasKey('extra', $result);
        $this->assertNull($result['extra']['userId']);
    }

    public function testUserIdProcessorPreservesExistingExtra(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(5);

        $processor = new UserId($user);
        $event = ['message' => 'test', 'extra' => ['existing' => 'data']];
        $result = $processor->process($event);

        $this->assertEquals('data', $result['extra']['existing']);
        $this->assertEquals(5, $result['extra']['userId']);
    }

    public function testJobIdProcessorAddsJobIdToEvent(): void
    {
        $job = $this->createMock(Job::class);
        $job->method('getId')->willReturn(99);

        $processor = new JobId($job);
        $event = ['message' => 'test'];
        $result = $processor->process($event);

        $this->assertArrayHasKey('extra', $result);
        $this->assertEquals(99, $result['extra']['jobId']);
    }

    public function testJobIdProcessorPreservesExistingExtra(): void
    {
        $job = $this->createMock(Job::class);
        $job->method('getId')->willReturn(7);

        $processor = new JobId($job);
        $event = ['message' => 'test', 'extra' => ['foo' => 'bar']];
        $result = $processor->process($event);

        $this->assertEquals('bar', $result['extra']['foo']);
        $this->assertEquals(7, $result['extra']['jobId']);
    }
}
