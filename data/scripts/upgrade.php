<?php declare(strict_types=1);

namespace Log;

use Common\Stdlib\PsrMessage;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Omeka\Api\Manager $api
 * @var \Omeka\View\Helper\Url $url
 * @var \Laminas\Log\Logger $logger
 * @var \Omeka\Settings\Settings $settings
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Laminas\Mvc\I18n\Translator $translator
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Settings\SiteSettings $siteSettings
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$url = $plugins->get('url');
$api = $plugins->get('api');
$logger = $services->get('Omeka\Logger');
$settings = $services->get('Omeka\Settings');
$translator = $services->get('MvcTranslator');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$siteSettings = $services->get('Omeka\Settings\Site');
$entityManager = $services->get('Omeka\EntityManager');

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.76')) {
    $message = new \Omeka\Stdlib\Message(
        $translator->translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.76'
    );
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
}

if (version_compare($oldVersion, '3.2.1', '<')) {
    $sqls = <<<'SQL'
        ALTER TABLE `log` DROP FOREIGN KEY FK_8F3F68C5A76ED395;
        DROP INDEX user_idx ON `log`;
        ALTER TABLE `log` CHANGE `user_id` `owner_id` int(11) NULL AFTER `id`;
        ALTER TABLE `log` ADD CONSTRAINT FK_8F3F68C57E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE SET NULL;
        SQL;
    foreach (explode(";\n", $sqls) as $sql) {
        try {
            $connection->executeStatement($sql);
        } catch (\Exception $e) {
            // Already created.
        }
    }
}

if (version_compare($oldVersion, '3.3.12.6', '<')) {
    // @link https://www.doctrine-project.org/projects/doctrine-dbal/en/2.6/reference/types.html#array-types
    $sql = <<<'SQL'
        ALTER TABLE `log` CHANGE `context` `context` LONGTEXT NOT NULL COMMENT '(DC2Type:json)';
        SQL;
    try {
        $connection->executeStatement($sql);
    } catch (\Exception $e) {
        // Already created.
    }
}

if (version_compare($oldVersion, '3.4.18', '<')) {
    $message = new PsrMessage(
        'Support of the third party service Sentry was moved to a separate module, {link}Log Sentry{link_end}.', // @translate
        ['link' => '<a href="https://gitlab.com/Daniel-KM/Omeka-S-module-LogSentry" target="_blank" rel="noopener">', 'link_end' => '</a>']
    );
    $message->setEscapeHtml(false);
    $messenger->addWarning($message);
}

if (version_compare($oldVersion, '3.4.33', '<')) {
    // Cron, store and delete are disabled on upgrade to let the admin chooses.
    // Furthermore, the first process may be intensive.
    $settings->set('log_cron_days', 0);
    $settings->set('log_archive_days', 30);
    $settings->set('log_archive_severity_max', 0);
    $settings->set('log_archive_references', []);
    $settings->set('log_archive_store', false);
    $settings->set('log_archive_format', 'tsv');
    $settings->set('log_archive_compress', true);
    $settings->set('log_archive_include_id', false);
    $settings->set('log_archive_translate', true);
    $settings->set('log_archive_delete', false);
    $settings->set('log_cron_last', 0);

    $message = new PsrMessage(
        'Logs can be archived and purged regularly. Go to {link}config form{link_end} for params.', // @translate
        ['link' => sprintf('<a href="%s">', $url->fromRoute('admin/default', ['controler' => 'module', 'action' => 'configure'], ['query' => ['id' => 'Log']], true)), 'link_end' => '</a>']
    );
    $message->setEscapeHtml(false);
    $messenger->addWarning($message);

    $message = new PsrMessage(
        'A regular deletion of old logs is recommended to keep omeka fluid.' // @translate
    );
    $messenger->addWarning($message);
}

/**
 * In all cases, check the directory to store logs.
 */

// Create but not forbid install, because storing is not required.
$config = $this->getServiceLocator()->get('Config');
$basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
if (!$this->checkDestinationDir($basePath . '/backup/log')) {
    $message = new PsrMessage(
        'The directory "{directory}" is not writeable, so old logs cannot be archived.', // @translate
        ['directory' => $basePath . '/backup/log']
    );
    $messenger->addWarning($message);
}

/**
 * In all cases, check indexes and run the job if needed.
 *
 * @see \Common\Module::fixIndexes().
 */

// Check if all indices exists.
$indexColumns = [
    'IDX_8F3F68C57E3C61F9' => 'owner_id',
    'IDX_8F3F68C5BE04EA9' => 'job_id',
    'IDX_8F3F68C5AEA34913' => 'reference',
    'IDX_8F3F68C5F660D16B' => 'severity',
    'IDX_8F3F68C5B23DB7B8' => 'created',
];

$newIndices = [];
foreach ($indexColumns as $index => $column) {
    $stmt = $connection->executeQuery("SHOW INDEX FROM `log` WHERE `column_name` = '$column';");
    $result = $stmt->fetchAssociative();
    if (!$result || $result['Key_name'] !== $index) {
        $newIndices[$index] = $column;
    }
}

$indexOlds = [
    'user_idx' => 'owner_id',
    'owner_idx' => 'owner_id',
    'job_idx' => 'job_id',
    'reference_idx' => 'reference',
    'severity_idx' => 'severity',
];

$indexToRemove = [];
foreach ($indexOlds as $index => $column) {
    $stmt = $connection->executeQuery("SHOW INDEX FROM `log` WHERE `column_name` = '$column';");
    $result = $stmt->fetchAssociative();
    if ($result && $result['Key_name'] === $index) {
        $indexToRemove[$index] = $column;
    }
}

if ($newIndices || $indexToRemove) {
    // Dispatch background job to add indexes.
    // The class is not available during upgrade or install.
    require_once dirname(__DIR__, 2) . '/src/Job/LogIndexes.php';
    $dispatcher = $services->get('Omeka\Job\Dispatcher');
    $dispatcher->dispatch(\Log\Job\LogIndexes::class);
    $message = new \Common\Stdlib\PsrMessage(
        'A background job has been started to add database indices. If it fails, you should create them manually. Missing indices: {list}.', // @translate
        ['list' => json_encode(array_values($newIndices))]
    );
    $messenger->addWarning($message);
}
