<?php declare(strict_types=1);

namespace LogTest\Job;

use CommonTest\AbstractHttpControllerTestCase;
use Doctrine\DBAL\Types\Types;
use Laminas\Log\Logger;
use LogTest\LogTestTrait;

/**
 * Test the ArchiveLogsJob query logic, especially the delete_job_logs option.
 *
 * These tests verify the DBAL query builder conditions used by ArchiveLogsJob
 * to select logs for archival/deletion, without dispatching the full job.
 */
class ArchiveLogsJobTest extends AbstractHttpControllerTestCase
{
    use LogTestTrait;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var int[]
     */
    protected $createdJobIds = [];

    /**
     * @var int[]
     */
    protected $createdLogIds = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->connection = $this->getServiceLocator()->get('Omeka\Connection');
    }

    public function tearDown(): void
    {
        // Cleanup logs created via DBAL (before jobs, due to FK).
        foreach ($this->createdLogIds as $logId) {
            try {
                $this->connection->delete('log', ['id' => $logId]);
            } catch (\Exception $e) {
            }
        }
        $this->createdLogIds = [];
        // Cleanup logs created via API (trait).
        $this->cleanupLogs();
        $this->cleanupJobs();
        parent::tearDown();
    }

    /**
     * Create a job entity directly in the database for testing.
     */
    protected function createTestJob(string $class = 'Omeka\Job\BatchUpdate'): int
    {
        $services = $this->getServiceLocator();
        $auth = $services->get('Omeka\AuthenticationService');
        $owner = $auth->getIdentity();

        $this->connection->insert('job', [
            'pid' => null,
            'status' => 'completed',
            'class' => $class,
            'args' => '{}',
            'log' => null,
            'owner_id' => $owner->getId(),
            'started' => date('Y-m-d H:i:s'),
            'ended' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->connection->lastInsertId();
        $this->createdJobIds[] = $id;
        return $id;
    }

    /**
     * Create a log directly via DBAL (bypasses API, allows setting job_id).
     */
    protected function createTestLog(array $data): int
    {
        $services = $this->getServiceLocator();
        $auth = $services->get('Omeka\AuthenticationService');
        $owner = $auth->getIdentity();

        $this->connection->insert('log', [
            'owner_id' => $owner->getId(),
            'job_id' => $data['job_id'] ?? null,
            'reference' => $data['reference'] ?? '',
            'severity' => $data['severity'] ?? Logger::INFO,
            'message' => $data['message'] ?? 'Test log',
            'context' => $data['context'] ?? '[]',
            'created' => $data['created'] ?? date('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->connection->lastInsertId();
        $this->createdLogIds[] = $id;
        return $id;
    }

    protected function cleanupJobs(): void
    {
        foreach ($this->createdJobIds as $jobId) {
            try {
                $this->connection->delete('job', ['id' => $jobId]);
            } catch (\Exception $e) {
            }
        }
        $this->createdJobIds = [];
    }

    /**
     * Build a query builder mimicking ArchiveLogsJob's logic.
     *
     * @return int[] Selected log IDs.
     */
    protected function getArchiveSelectedIds(bool $deleteJobLogs, ?string $olderThan = null): array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select('id')
            ->from('log');

        if ($olderThan) {
            $qb->where('created < :date')
                ->setParameter('date', $olderThan);
        }

        // This is the logic from ArchiveLogsJob.
        if (!$deleteJobLogs) {
            $qb->andWhere('(job_id IS NULL OR severity >= :keep_job_severity)')
                ->setParameter('keep_job_severity', Logger::INFO, Types::INTEGER);
        }

        $qb->orderBy('id', 'ASC');
        $results = $qb->execute()->fetchAllAssociative();
        return array_map('intval', array_column($results, 'id'));
    }

    public function testDeleteJobLogsFalsePreservesJobLogsWithHighSeverity(): void
    {
        $jobId = $this->createTestJob();

        // Log with job + error severity: should be preserved.
        $logError = $this->createTestLog([
            'job_id' => $jobId,
            'severity' => Logger::ERR,
            'message' => 'Import failed',
            'created' => '2020-01-01 00:00:00',
        ]);

        // Log with job + info severity: should be selected for deletion.
        $logInfo = $this->createTestLog([
            'job_id' => $jobId,
            'severity' => Logger::INFO,
            'message' => 'Import started',
            'created' => '2020-01-01 00:00:00',
        ]);

        // Log without job + error severity: should be selected for deletion.
        $logNoJob = $this->createTestLog([
            'job_id' => null,
            'severity' => Logger::ERR,
            'message' => 'System error',
            'created' => '2020-01-01 00:00:00',
        ]);

        $selectedIds = $this->getArchiveSelectedIds(false, '2025-01-01 00:00:00');

        $this->assertNotContains($logError, $selectedIds,
            'Error log with job should be preserved when delete_job_logs is false');
        $this->assertContains($logInfo, $selectedIds,
            'Info log with job should be selected for deletion');
        $this->assertContains($logNoJob, $selectedIds,
            'Log without job should always be selected for deletion');
    }

    public function testDeleteJobLogsTrueDeletesAllLogs(): void
    {
        $jobId = $this->createTestJob();

        $logError = $this->createTestLog([
            'job_id' => $jobId,
            'severity' => Logger::ERR,
            'message' => 'Import failed',
            'created' => '2020-01-01 00:00:00',
        ]);

        $logInfo = $this->createTestLog([
            'job_id' => $jobId,
            'severity' => Logger::INFO,
            'message' => 'Import started',
            'created' => '2020-01-01 00:00:00',
        ]);

        $selectedIds = $this->getArchiveSelectedIds(true, '2025-01-01 00:00:00');

        $this->assertContains($logError, $selectedIds,
            'Error log with job should be selected when delete_job_logs is true');
        $this->assertContains($logInfo, $selectedIds,
            'Info log with job should be selected when delete_job_logs is true');
    }

    public function testDeleteJobLogsFalsePreservesAllSeveritiesAboveInfo(): void
    {
        $jobId = $this->createTestJob();

        $severities = [
            Logger::EMERG => true,   // 0 - preserved
            Logger::ALERT => true,   // 1 - preserved
            Logger::CRIT => true,    // 2 - preserved
            Logger::ERR => true,     // 3 - preserved
            Logger::WARN => true,    // 4 - preserved
            Logger::NOTICE => true,  // 5 - preserved
            Logger::INFO => false,   // 6 - deleted
            Logger::DEBUG => false,  // 7 - deleted
        ];

        $logIds = [];
        foreach ($severities as $severity => $shouldBePreserved) {
            $logIds[$severity] = $this->createTestLog([
                'job_id' => $jobId,
                'severity' => $severity,
                'message' => "Severity $severity",
                'created' => '2020-01-01 00:00:00',
            ]);
        }

        $selectedIds = $this->getArchiveSelectedIds(false, '2025-01-01 00:00:00');

        foreach ($severities as $severity => $shouldBePreserved) {
            if ($shouldBePreserved) {
                $this->assertNotContains($logIds[$severity], $selectedIds,
                    "Job log with severity $severity should be preserved");
            } else {
                $this->assertContains($logIds[$severity], $selectedIds,
                    "Job log with severity $severity should be selected for deletion");
            }
        }
    }

    public function testDeleteJobLogsFalseDoesNotAffectLogsWithoutJob(): void
    {
        $logError = $this->createTestLog([
            'job_id' => null,
            'severity' => Logger::ERR,
            'message' => 'System error',
            'created' => '2020-01-01 00:00:00',
        ]);

        $logEmerg = $this->createTestLog([
            'job_id' => null,
            'severity' => Logger::EMERG,
            'message' => 'System emergency',
            'created' => '2020-01-01 00:00:00',
        ]);

        $selectedIds = $this->getArchiveSelectedIds(false, '2025-01-01 00:00:00');

        $this->assertContains($logError, $selectedIds,
            'Error log without job should be selected even with delete_job_logs=false');
        $this->assertContains($logEmerg, $selectedIds,
            'Emergency log without job should be selected even with delete_job_logs=false');
    }

    public function testDateFilterStillApplies(): void
    {
        $jobId = $this->createTestJob();

        $logOldInfo = $this->createTestLog([
            'job_id' => $jobId,
            'severity' => Logger::INFO,
            'message' => 'Old info',
            'created' => '2020-01-01 00:00:00',
        ]);

        $logRecentInfo = $this->createTestLog([
            'job_id' => $jobId,
            'severity' => Logger::INFO,
            'message' => 'Recent info',
            'created' => '2026-01-01 00:00:00',
        ]);

        $selectedIds = $this->getArchiveSelectedIds(false, '2025-01-01 00:00:00');

        $this->assertContains($logOldInfo, $selectedIds,
            'Old info log should be selected');
        $this->assertNotContains($logRecentInfo, $selectedIds,
            'Recent info log should not be selected (date filter)');
    }
}
