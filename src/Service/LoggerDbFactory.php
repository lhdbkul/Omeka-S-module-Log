<?php declare(strict_types=1);

namespace Log\Service;

use Psr\Container\ContainerInterface;
use Laminas\Log\Logger;
use Laminas\Log\Writer\Noop;

/**
 * Logger Db factory.
 *
 * Creates a logger with only the database writer (no stream writer).
 */
class LoggerDbFactory extends LoggerFactory
{
    /**
     * Create the logger Db service.
     *
     * @return Logger
     */
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $config = $services->get('Config');

        // Only use the doctrine writer.
        $writers = ['doctrine' => $config['logger']['options']['writers']['doctrine']];

        // Get Doctrine connection.
        $connection = $this->getDoctrineConnection($services);
        if (!$connection) {
            error_log('[Omeka S] Database logging disabled: connection unavailable.'); // @translate
            return (new Logger)->addWriter(new Noop);
        }

        // Create Doctrine writer.
        $doctrineWriter = $this->createDoctrineWriter($connection, $writers['doctrine']);

        // Create logger and add Doctrine writer.
        $logger = new Logger();
        $logger->addWriter($doctrineWriter);

        // Add user id processor if configured.
        if (!empty($config['logger']['options']['processors']['userid']['name'])) {
            $processor = $this->addUserIdProcessor($services);
            $logger->addProcessor($processor);
        }

        return $logger;
    }
}
