<?php declare(strict_types=1);

namespace LogTest;

use Laminas\Log\Logger;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Manager as ApiManager;
use Omeka\Entity\Job;

/**
 * Shared test helpers for Log module tests.
 */
trait LogTestTrait
{
    /**
     * @var bool Whether admin is logged in.
     */
    protected bool $isLoggedIn = false;

    /**
     * @var array IDs of logs created during tests (for cleanup).
     */
    protected array $createdLogs = [];

    /**
     * Get the service locator.
     */
    protected function getServiceLocator(): ServiceLocatorInterface
    {
        if (isset($this->application) && $this->application !== null) {
            return $this->application->getServiceManager();
        }
        return $this->getApplication()->getServiceManager();
    }

    /**
     * Get the API manager.
     */
    protected function api(): ApiManager
    {
        if ($this->isLoggedIn) {
            $this->ensureLoggedIn();
        }
        return $this->getServiceLocator()->get('Omeka\ApiManager');
    }

    /**
     * Get the entity manager.
     */
    public function getEntityManager(): \Doctrine\ORM\EntityManager
    {
        return $this->getServiceLocator()->get('Omeka\EntityManager');
    }

    /**
     * Login as admin user.
     */
    protected function loginAdmin(): void
    {
        $this->isLoggedIn = true;
        $this->ensureLoggedIn();
    }

    /**
     * Ensure admin is logged in on the current application instance.
     */
    protected function ensureLoggedIn(): void
    {
        $services = $this->getServiceLocator();
        $auth = $services->get('Omeka\AuthenticationService');

        if ($auth->hasIdentity()) {
            return;
        }

        $adapter = $auth->getAdapter();
        $adapter->setIdentity('admin@example.com');
        $adapter->setCredential('root');
        $auth->authenticate();
    }

    /**
     * Logout current user.
     */
    protected function logout(): void
    {
        $this->isLoggedIn = false;
        $auth = $this->getServiceLocator()->get('Omeka\AuthenticationService');
        $auth->clearIdentity();
    }

    /**
     * Create a log entry via the API.
     *
     * @param array $data Log data. Keys:
     *   - severity: int (Logger constant, default INFO)
     *   - message: string (default 'Test log message')
     *   - context: array (default [])
     *   - reference: string (default '')
     * @return \Log\Api\Representation\LogRepresentation
     */
    protected function createLog(array $data = [])
    {
        $services = $this->getServiceLocator();
        $auth = $services->get('Omeka\AuthenticationService');
        $owner = $auth->getIdentity();

        $logData = [
            'o:owner' => $owner ? ['o:id' => $owner->getId()] : null,
            'o:job' => $data['o:job'] ?? null,
            'o:reference' => $data['reference'] ?? $data['o:reference'] ?? '',
            'o:severity' => $data['severity'] ?? $data['o:severity'] ?? Logger::INFO,
            'o:message' => $data['message'] ?? $data['o:message'] ?? 'Test log message',
            'o:context' => $data['context'] ?? $data['o:context'] ?? [],
        ];

        $response = $this->api()->create('logs', $logData);
        $log = $response->getContent();
        $this->createdLogs[] = $log->id();
        return $log;
    }

    /**
     * Create multiple log entries for testing.
     *
     * @param int $count Number of logs to create.
     * @param array $overrides Data to override for each log.
     * @return array Array of LogRepresentation.
     */
    protected function createLogs(int $count, array $overrides = []): array
    {
        $logs = [];
        for ($i = 1; $i <= $count; $i++) {
            $data = array_merge([
                'message' => "Test log message #$i",
            ], $overrides);
            $logs[] = $this->createLog($data);
        }
        return $logs;
    }

    /**
     * Clean up created logs after test.
     */
    protected function cleanupLogs(): void
    {
        foreach ($this->createdLogs as $logId) {
            try {
                $this->api()->delete('logs', $logId);
            } catch (\Exception $e) {
                // Ignore errors during cleanup.
            }
        }
        $this->createdLogs = [];
    }
}
