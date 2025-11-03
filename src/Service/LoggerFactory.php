<?php declare(strict_types=1);

namespace Log\Service;

use Interop\Container\ContainerInterface;
use Laminas\Log\Exception;
use Laminas\Log\Logger;
use Laminas\Log\Writer\Noop;
use Laminas\Log\Writer\Stream;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Log\Log\Processor\UserId;
use Log\Log\Writer\Doctrine as DoctrineWriter;
use Log\Service\Log\Processor\UserIdFactory;

/**
 * Logger factory.
 */
class LoggerFactory implements FactoryInterface
{
    /**
     * Create the logger service.
     *
     * @return Logger
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $config = $services->get('Config');

        if (empty($config['logger']['log'])) {
            return (new Logger)->addWriter(new Noop);
        }

        $enabledWriters = array_filter($config['logger']['writers']);
        $writers = array_intersect_key($config['logger']['options']['writers'], $enabledWriters);
        if (empty($writers)) {
            return (new Logger)->addWriter(new Noop);
        }

        // For compatibility with Omeka default config, that may be customized.
        // Most of the time, the stream is disabled (see default config of the
        // module), so there is no stream.

        if (!empty($writers['stream'])) {
            if (isset($config['logger']['priority'])) {
                $writers['stream']['options']['filters'] = $config['logger']['priority'];
            }
            if (isset($config['logger']['path'])) {
                $writers['stream']['options']['stream'] = $config['logger']['path'];
            }
            // The stream may not be a file (php://stdout), so check file only.
            // The check is slightly different in Stream when there is no file.
            if (is_file($writers['stream']['options']['stream'])) {
                if (!is_writeable($writers['stream']['options']['stream'])) {
                    unset($writers['stream']);
                    error_log('[Omeka S] File logging disabled: not writeable.'); // @translate
                }
            } else {
                // Early check the stream name to follow upstream process.
                try {
                    new Stream($writers['stream']['options']['stream']);
                } catch (Exception\RuntimeException $e) {
                    unset($writers['stream']);
                    error_log('Omeka S log initialization failed: ' . $e->getMessage());
                }
            }
        }

        // Replace Laminas Db writer with Doctrine writer.
        if (!empty($writers['doctrine'])) {
            $connection = $this->getDoctrineConnection($services);
            if ($connection) {
                // Create Doctrine writer manually and add it to logger.
                $doctrineWriter = $this->createDoctrineWriter($connection, $writers['doctrine']);

                // Remove the db writer from config array (will be added manually).
                unset($writers['doctrine']);

                // Create logger with remaining writers.
                if (!empty($config['logger']['options']['processors']['userid']['name'])) {
                    $config['logger']['options']['processors']['userid']['name'] = $this->addUserIdProcessor($services);
                }

                $config['logger']['options']['writers'] = $writers;
                $logger = empty($writers) ? new Logger : new Logger($config['logger']['options']);

                // Add Doctrine writer.
                $logger->addWriter($doctrineWriter);

                return $logger;
            } else {
                unset($writers['doctrine']);
                error_log('[Omeka S] Database logging disabled: connection unavailable.'); // @translate
            }
        }

        if (empty($writers)) {
            return (new Logger)->addWriter(new Noop);
        }

        $config['logger']['options']['writers'] = $writers;
        if (!empty($config['logger']['options']['processors']['userid']['name'])) {
            $config['logger']['options']['processors']['userid']['name'] = $this->addUserIdProcessor($services);
        }

        // Checks are managed via the constructor.
        return new Logger($config['logger']['options']);
    }

    /**
     * Create a Doctrine writer from configuration.
     *
     * @param \Doctrine\DBAL\Connection $connection
     * @param array $writerConfig
     * @return DoctrineWriter
     */
    protected function createDoctrineWriter($connection, array $writerConfig)
    {
        $tableName = $writerConfig['options']['table'] ?? 'log';
        $columnMap = $writerConfig['options']['column'] ?? null;
        $separator = $writerConfig['options']['separator'] ?? null;

        // Create writer with connection, table, and column mapping.
        $doctrineWriter = new DoctrineWriter($connection, $tableName, $columnMap, $separator);

        // Set formatter if specified.
        if (!empty($writerConfig['options']['formatter'])) {
            $formatterClass = $writerConfig['options']['formatter'];
            if (class_exists($formatterClass)) {
                $formatter = new $formatterClass();
                $doctrineWriter->setFormatter($formatter);
            }
        }

        // Add filters if specified.
        if (!empty($writerConfig['options']['filters'])) {
            $filters = $writerConfig['options']['filters'];
            if (!is_array($filters)) {
                $filters = [$filters];
            }
            foreach ($filters as $filter) {
                $doctrineWriter->addFilter($filter);
            }
        }

        return $doctrineWriter;
    }

    /**
     * Get Doctrine DBAL connection.
     *
     * Supports external database via config/database-log.ini.
     * If no external config exists, uses Omeka's main database connection.
     *
     * To disable the database, set `"db" => false` in the module config.
     *
     * For performance, flexibility and stability reasons, the write process
     * uses Doctrine DBAL. The read/delete process in api or ui uses the
     * default doctrine entity manager.
     *
     * @param ContainerInterface $services
     * @return \Doctrine\DBAL\Connection|null
     */
    protected function getDoctrineConnection(ContainerInterface $services)
    {
        $iniConfigPath = OMEKA_PATH . '/config/database-log.ini';

        // Check for external database configuration.
        if (file_exists($iniConfigPath) && is_readable($iniConfigPath)) {
            try {
                $reader = new \Laminas\Config\Reader\Ini;
                $iniConfig = $reader->fromFile($iniConfigPath);
                $iniConfig = array_filter($iniConfig);

                if (!empty($iniConfig)) {
                    return $this->createConnectionFromConfig($iniConfig);
                }
            } catch (\Exception $e) {
                error_log('[Omeka S] Failed to read database-log.ini: ' . $e->getMessage());
            }
        }

        // Use Omeka's main database connection.
        try {
            return $services->get('Omeka\Connection');
        } catch (\Exception $e) {
            error_log('[Omeka S] Failed to get Doctrine connection: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Create a Doctrine DBAL connection from INI configuration.
     *
     * @param array $config Configuration array from database-log.ini.
     * @return \Doctrine\DBAL\Connection
     * @throws \Doctrine\DBAL\Exception
     */
    protected function createConnectionFromConfig(array $config)
    {
        // Map INI config to Doctrine DBAL parameters.
        $params = [
            'dbname' => $config['database'] ?? $config['dbname'] ?? null,
            'user' => $config['username'] ?? $config['user'] ?? null,
            'password' => $config['password'] ?? null,
            'host' => $config['host'] ?? 'localhost',
            'driver' => 'pdo_mysql',
        ];

        // Add port if specified.
        if (!empty($config['port'])) {
            $params['port'] = (int) $config['port'];
        }

        // Add unix socket if specified.
        if (!empty($config['unix_socket'])) {
            $params['unix_socket'] = $config['unix_socket'];
            unset($params['host']); // Unix socket takes precedence over host.
        }

        // Add charset if specified.
        if (!empty($config['charset'])) {
            $params['charset'] = $config['charset'];
        }

        // Add driver options (e.g., SSL certificates).
        if (!empty($config['driverOptions']) || !empty($config['driver_options'])) {
            $params['driverOptions'] = $config['driverOptions'] ?? $config['driver_options'];
        }

        // Create connection using Doctrine DBAL.
        return \Doctrine\DBAL\DriverManager::getConnection($params);
    }

    /**
     * Get the database params, or the Omeka database params (deprecated).
     *
     * @deprecated No longer used. Kept for backwards compatibility.
     * To disable the database, set `"db" => false` in the module config.
     *
     * For performance, flexibility and stability reasons, the write process
     * now uses Doctrine DBAL instead of a specific Laminas Db adapter.
     * The read/delete process in api or ui uses the default doctrine entity manager.
     *
     * @param ContainerInterface $services
     * @return \Doctrine\DBAL\Connection|null
     */
    protected function getDbAdapter(ContainerInterface $services)
    {
        // Return Doctrine connection for backwards compatibility.
        return $this->getDoctrineConnection($services);
    }

    /**
     * Add the log processor to add the current user id.
     *
     * @todo Load the user id log processor via log_processors.
     * @param ContainerInterface $services
     * @return UserId
     */
    protected function addUserIdProcessor(ContainerInterface $services)
    {
        $userIdFactory = new UserIdFactory();
        return $userIdFactory($services, '');
    }
}
