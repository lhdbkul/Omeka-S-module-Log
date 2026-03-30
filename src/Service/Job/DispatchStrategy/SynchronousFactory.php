<?php declare(strict_types=1);
namespace Log\Service\Job\DispatchStrategy;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Log\Job\DispatchStrategy\Synchronous;
use Psr\Container\ContainerInterface;

class SynchronousFactory implements FactoryInterface
{
    /**
     * Create the PhpCli strategy service.
     *
     * @return Synchronous
     */
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new Synchronous($services);
    }
}
