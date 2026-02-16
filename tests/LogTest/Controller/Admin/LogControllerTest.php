<?php declare(strict_types=1);

namespace LogTest\Controller\Admin;

use CommonTest\AbstractHttpControllerTestCase;
use Laminas\Log\Logger;
use LogTest\LogTestTrait;

class LogControllerTest extends AbstractHttpControllerTestCase
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

    public function testBrowseActionCanBeAccessed(): void
    {
        $this->dispatch('/admin/log');
        $this->assertControllerName('Log\Controller\Admin\LogController');
        $this->assertActionName('browse');
    }

    public function testBrowseActionWithExistingLogs(): void
    {
        $this->createLog(['message' => 'Browse test log']);
        $this->dispatch('/admin/log');
        $this->assertControllerName('Log\Controller\Admin\LogController');
        $this->assertActionName('browse');
    }

    public function testBrowseRouteExists(): void
    {
        $this->dispatch('/admin/log/browse');
        $this->assertControllerName('Log\Controller\Admin\LogController');
        $this->assertActionName('browse');
    }

    public function testShowDetailsActionRequiresValidId(): void
    {
        $this->dispatch('/admin/log/999999/show-details');
        $this->assertResponseStatusCode(404);
    }

    public function testShowDetailsActionReturnsLogDetails(): void
    {
        $log = $this->createLog(['message' => 'Details test log']);
        $this->dispatch('/admin/log/' . $log->id() . '/show-details');
        $this->assertControllerName('Log\Controller\Admin\LogController');
        $this->assertActionName('show-details');
    }

    public function testDeleteConfirmActionRequiresValidId(): void
    {
        $this->dispatch('/admin/log/999999/delete-confirm');
        $this->assertResponseStatusCode(404);
    }

    public function testDeleteActionRedirectsOnGet(): void
    {
        $log = $this->createLog(['message' => 'Delete test log']);
        $this->dispatch('/admin/log/' . $log->id() . '/delete');
        $this->assertRedirectRegex('~/admin/log~');
    }

    public function testBatchDeleteActionRedirectsOnGet(): void
    {
        $this->dispatch('/admin/log/batch-delete');
        $this->assertRedirectRegex('~/admin/log~');
    }

    public function testBatchDeleteAllActionRedirectsOnGet(): void
    {
        $this->dispatch('/admin/log/batch-delete-all');
        $this->assertRedirectRegex('~/admin/log~');
    }

    public function testBrowseWithSeverityFilter(): void
    {
        $this->createLog(['message' => 'Error log', 'severity' => Logger::ERR]);
        $this->createLog(['message' => 'Info log', 'severity' => Logger::INFO]);
        $this->dispatch('/admin/log', 'GET', ['severity' => Logger::ERR]);
        $this->assertControllerName('Log\Controller\Admin\LogController');
        $this->assertActionName('browse');
    }

    public function testBrowseWithReferenceFilter(): void
    {
        $this->createLog(['message' => 'Ref log', 'reference' => 'myref']);
        $this->dispatch('/admin/log', 'GET', ['reference' => 'myref']);
        $this->assertControllerName('Log\Controller\Admin\LogController');
        $this->assertActionName('browse');
    }
}
