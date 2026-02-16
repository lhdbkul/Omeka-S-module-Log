<?php declare(strict_types=1);

namespace LogTest\Entity;

use CommonTest\AbstractHttpControllerTestCase;
use Laminas\Log\Logger;
use Log\Entity\Log;
use LogTest\LogTestTrait;

class LogEntityTest extends AbstractHttpControllerTestCase
{
    use LogTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    public function tearDown(): void
    {
        $this->cleanupLogs();
        parent::tearDown();
    }

    public function testEntityGettersAndSetters(): void
    {
        $entity = new Log();
        $now = new \DateTime('now');

        $entity->setReference('test-ref');
        $this->assertEquals('test-ref', $entity->getReference());

        $entity->setSeverity(Logger::WARN);
        $this->assertEquals(Logger::WARN, $entity->getSeverity());

        $entity->setMessage('Test message');
        $this->assertEquals('Test message', $entity->getMessage());

        $entity->setContext(['key' => 'value']);
        $this->assertEquals(['key' => 'value'], $entity->getContext());

        $entity->setCreated($now);
        $this->assertSame($now, $entity->getCreated());
    }

    public function testEntitySeverityCastsToInt(): void
    {
        $entity = new Log();
        $entity->setSeverity('4');
        $this->assertSame(4, $entity->getSeverity());
    }

    public function testEntityDefaultValues(): void
    {
        $entity = new Log();
        $this->assertEquals('', $entity->getReference());
        $this->assertEquals(0, $entity->getSeverity());
    }

    public function testEntityOwnerCanBeNull(): void
    {
        $entity = new Log();
        $entity->setOwner(null);
        $this->assertNull($entity->getOwner());
    }

    public function testEntityJobCanBeNull(): void
    {
        $entity = new Log();
        $entity->setJob(null);
        $this->assertNull($entity->getJob());
    }

    public function testEntityPersistence(): void
    {
        $em = $this->getEntityManager();
        $auth = $this->getServiceLocator()->get('Omeka\AuthenticationService');
        $owner = $auth->getIdentity();

        $entity = new Log();
        $entity->setOwner($owner);
        $entity->setReference('persist-ref');
        $entity->setSeverity(Logger::ERR);
        $entity->setMessage('Persisted message');
        $entity->setContext(['action' => 'test']);
        $entity->setCreated(new \DateTime('now'));

        $em->persist($entity);
        $em->flush();

        $id = $entity->getId();
        $this->assertNotNull($id);

        // Clear entity manager cache and reload.
        $em->clear();
        $reloaded = $em->find(Log::class, $id);

        $this->assertNotNull($reloaded);
        $this->assertEquals('persist-ref', $reloaded->getReference());
        $this->assertEquals(Logger::ERR, $reloaded->getSeverity());
        $this->assertEquals('Persisted message', $reloaded->getMessage());
        $this->assertEquals(['action' => 'test'], $reloaded->getContext());
        $this->assertNotNull($reloaded->getOwner());
        $this->assertEquals($owner->getId(), $reloaded->getOwner()->getId());

        // Cleanup.
        $em->remove($reloaded);
        $em->flush();
    }
}
