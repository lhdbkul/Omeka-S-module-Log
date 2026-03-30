<?php declare(strict_types=1);

namespace Log\Job;

use Omeka\Job\AbstractJob;

/**
 * Add indices to speed up module log.
 */
 class LogIndexes extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $connection = $services->get('Omeka\Connection');

        // Recreate index if missing.
        $sqls = <<<'SQL'
            CREATE INDEX IDX_8F3F68C57E3C61F9 ON `log` (`owner_id`);
            CREATE INDEX IDX_8F3F68C5BE04EA9 ON `log` (`job_id`);
            CREATE INDEX IDX_8F3F68C5AEA34913 ON `log` (`reference`);
            CREATE INDEX IDX_8F3F68C5F660D16B ON `log` (`severity`);
            CREATE INDEX IDX_8F3F68C5B23DB7B8 ON `log` (`created`);
            DROP INDEX user_idx ON log;
            DROP INDEX owner_idx ON `log`;
            DROP INDEX job_idx ON `log`;
            DROP INDEX reference_idx ON `log`;
            DROP INDEX severity_idx ON `log`;
            SQL;

        /*
        $sqls = array_filter(explode(";\n", $sqls));
        $connection->transactional(function($connection) use ($sqls) {
            foreach ((array) $sqls as $sql) {
                $connection->executeStatement($sql);
            }
        });
        */

        foreach (array_filter(explode(";\n", $sqls)) as $sql) {
            try {
                $connection->executeStatement($sql);
            } catch (\Throwable $e) {
                // Already created or dropped.
            }
        }
    }
}
