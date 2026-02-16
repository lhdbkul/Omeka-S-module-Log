<?php declare(strict_types=1);

namespace LogTest\Api\Adapter;

use CommonTest\AbstractHttpControllerTestCase;
use Laminas\Log\Logger;
use LogTest\LogTestTrait;

class LogAdapterTest extends AbstractHttpControllerTestCase
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

    public function testCreateLog(): void
    {
        $log = $this->createLog([
            'message' => 'Create test',
            'severity' => Logger::WARN,
            'reference' => 'test-ref',
            'context' => ['key' => 'value'],
        ]);

        $this->assertNotNull($log->id());
        $this->assertEquals(Logger::WARN, $log->severity());
        $this->assertEquals('test-ref', $log->reference());
    }

    public function testSearchLogsReturnsResults(): void
    {
        $this->createLog(['message' => 'Searchable log']);

        $response = $this->api()->search('logs', []);
        $this->assertGreaterThanOrEqual(1, $response->getTotalResults());
    }

    public function testSearchByOwnerId(): void
    {
        $log = $this->createLog(['message' => 'Owner test']);
        $services = $this->getServiceLocator();
        $auth = $services->get('Omeka\AuthenticationService');
        $ownerId = $auth->getIdentity()->getId();

        $response = $this->api()->search('logs', ['owner_id' => $ownerId]);
        $this->assertGreaterThanOrEqual(1, $response->getTotalResults());
    }

    public function testSearchByReference(): void
    {
        $this->createLog(['message' => 'Ref search', 'reference' => 'unique-ref-123']);
        $this->createLog(['message' => 'Other log', 'reference' => 'other-ref']);

        $response = $this->api()->search('logs', ['reference' => 'unique-ref-123']);
        $results = $response->getContent();
        $this->assertGreaterThanOrEqual(1, count($results));
        foreach ($results as $result) {
            $this->assertEquals('unique-ref-123', $result->reference());
        }
    }

    public function testSearchBySeverity(): void
    {
        $this->createLog(['message' => 'Error log', 'severity' => Logger::ERR]);
        $this->createLog(['message' => 'Debug log', 'severity' => Logger::DEBUG]);

        $response = $this->api()->search('logs', ['severity' => (string) Logger::ERR]);
        $results = $response->getContent();
        $this->assertGreaterThanOrEqual(1, count($results));
        foreach ($results as $result) {
            $this->assertEquals(Logger::ERR, $result->severity());
        }
    }

    public function testSearchBySeverityComparison(): void
    {
        $this->createLog(['message' => 'Alert log', 'severity' => Logger::ALERT]);
        $this->createLog(['message' => 'Debug log', 'severity' => Logger::DEBUG]);

        // Severity <= WARN (4) should include EMERG(0), ALERT(1), CRIT(2), ERR(3), WARN(4).
        $response = $this->api()->search('logs', ['severity_min' => (string) Logger::WARN]);
        $results = $response->getContent();
        $this->assertGreaterThanOrEqual(1, count($results));
        foreach ($results as $result) {
            $this->assertLessThanOrEqual(Logger::WARN, $result->severity());
        }
    }

    public function testSearchByMessageContains(): void
    {
        $this->createLog(['message' => 'The quick brown fox jumps']);
        $this->createLog(['message' => 'Lazy dog sleeps']);

        // The adapter expects message as a nested array of search conditions.
        $response = $this->api()->search('logs', [
            'message' => [['text' => 'brown fox', 'type' => 'in']],
        ]);
        $results = $response->getContent();
        $this->assertGreaterThanOrEqual(1, count($results));
    }

    public function testSearchByMessageNotContains(): void
    {
        $this->createLog(['message' => 'Included message test']);
        $this->createLog(['message' => 'Excluded message test']);

        $response = $this->api()->search('logs', [
            'message' => [['text' => 'Excluded', 'type' => 'nin']],
        ]);
        $results = $response->getContent();
        $this->assertGreaterThanOrEqual(1, count($results));
        foreach ($results as $result) {
            $this->assertStringNotContainsString('Excluded', (string) $result->message());
        }
    }

    public function testSearchByMessageEquals(): void
    {
        $this->createLog(['message' => 'Exact match message']);
        $this->createLog(['message' => 'Another message']);

        $response = $this->api()->search('logs', [
            'message' => [['text' => 'Exact match message', 'type' => 'eq']],
        ]);
        $results = $response->getContent();
        $this->assertGreaterThanOrEqual(1, count($results));
        foreach ($results as $result) {
            $this->assertEquals('Exact match message', (string) $result->message());
        }
    }

    public function testSearchByMessageNotEquals(): void
    {
        $this->createLog(['message' => 'Keep this message']);
        $this->createLog(['message' => 'Reject this message']);

        $response = $this->api()->search('logs', [
            'message' => [['text' => 'Reject this message', 'type' => 'neq']],
        ]);
        $results = $response->getContent();
        $this->assertGreaterThanOrEqual(1, count($results));
        foreach ($results as $result) {
            $this->assertNotEquals('Reject this message', (string) $result->message());
        }
    }

    public function testReadLog(): void
    {
        $log = $this->createLog([
            'message' => 'Read test',
            'severity' => Logger::NOTICE,
            'reference' => 'read-ref',
            'context' => ['foo' => 'bar'],
        ]);

        $readLog = $this->api()->read('logs', $log->id())->getContent();
        $this->assertEquals($log->id(), $readLog->id());
        $this->assertEquals(Logger::NOTICE, $readLog->severity());
        $this->assertEquals('read-ref', $readLog->reference());
    }

    public function testDeleteLog(): void
    {
        $log = $this->createLog(['message' => 'Delete test']);
        $logId = $log->id();

        // Remove from cleanup list since we delete it here.
        $this->createdLogs = array_filter(
            $this->createdLogs,
            fn($id) => $id !== $logId
        );

        $this->api()->delete('logs', $logId);

        $this->expectException(\Omeka\Api\Exception\NotFoundException::class);
        $this->api()->read('logs', $logId);
    }

    public function testSortById(): void
    {
        $log1 = $this->createLog(['message' => 'Sort test 1']);
        $log2 = $this->createLog(['message' => 'Sort test 2']);

        $response = $this->api()->search('logs', [
            'sort_by' => 'id',
            'sort_order' => 'asc',
        ]);
        $results = $response->getContent();
        $this->assertGreaterThanOrEqual(2, count($results));

        $ids = array_map(fn($r) => $r->id(), $results);
        $sorted = $ids;
        sort($sorted);
        $this->assertEquals($sorted, $ids);
    }

    public function testSortBySeverity(): void
    {
        $this->createLog(['message' => 'Err', 'severity' => Logger::ERR]);
        $this->createLog(['message' => 'Debug', 'severity' => Logger::DEBUG]);
        $this->createLog(['message' => 'Alert', 'severity' => Logger::ALERT]);

        $response = $this->api()->search('logs', [
            'sort_by' => 'severity',
            'sort_order' => 'asc',
        ]);
        $results = $response->getContent();
        $this->assertGreaterThanOrEqual(3, count($results));

        $severities = array_map(fn($r) => $r->severity(), $results);
        $sorted = $severities;
        sort($sorted);
        $this->assertEquals($sorted, $severities);
    }

    public function testLogRepresentationSeverityLabel(): void
    {
        $log = $this->createLog(['severity' => Logger::ERR]);
        $this->assertEquals('error', $log->severityLabel());

        $logWarn = $this->createLog(['severity' => Logger::WARN]);
        $this->assertEquals('warning', $logWarn->severityLabel());

        $logDebug = $this->createLog(['severity' => Logger::DEBUG]);
        $this->assertEquals('debug', $logDebug->severityLabel());
    }

    public function testLogRepresentationAccessors(): void
    {
        $log = $this->createLog([
            'message' => 'Accessor test',
            'severity' => Logger::INFO,
            'reference' => 'accessor-ref',
            'context' => ['foo' => 'bar'],
        ]);

        $this->assertEquals(Logger::INFO, $log->severity());
        $this->assertEquals('accessor-ref', $log->reference());
        $this->assertEquals(['foo' => 'bar'], $log->context());
        $this->assertEquals('o:Log', $log->getJsonLdType());
        $this->assertEquals('log', $log->getControllerName());
        $this->assertNotNull($log->owner());
        $this->assertNull($log->job());
    }

    public function testLogRepresentationJsonLd(): void
    {
        $log = $this->createLog([
            'message' => 'JsonLD test',
            'severity' => Logger::INFO,
            'reference' => 'jsonld-ref',
            'context' => ['key' => 'value'],
        ]);

        $jsonLd = $log->getJsonLd();
        $this->assertArrayHasKey('o:severity', $jsonLd);
        $this->assertArrayHasKey('o:message', $jsonLd);
        $this->assertArrayHasKey('o:reference', $jsonLd);
        $this->assertArrayHasKey('o:context', $jsonLd);
        $this->assertArrayHasKey('o:created', $jsonLd);
        $this->assertEquals(Logger::INFO, $jsonLd['o:severity']);
        $this->assertEquals('jsonld-ref', $jsonLd['o:reference']);
        $this->assertEquals(['key' => 'value'], $jsonLd['o:context']);
    }

    public function testLogRepresentationMessage(): void
    {
        $log = $this->createLog([
            'message' => 'Hello {name}',
            'context' => ['name' => 'World'],
        ]);

        $message = $log->message();
        $this->assertEquals('Hello World', (string) $message);
    }

    public function testLogCreatedDateIsSet(): void
    {
        $log = $this->createLog(['message' => 'Date test']);
        $created = $log->created();
        $this->assertInstanceOf(\DateTime::class, $created);
        // Created should be within the last minute.
        $diff = (new \DateTime('now'))->getTimestamp() - $created->getTimestamp();
        $this->assertLessThan(60, abs($diff));
    }
}
