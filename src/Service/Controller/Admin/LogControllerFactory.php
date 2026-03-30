<?php declare(strict_types=1);

namespace Log\Service\Controller\Admin;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Log\Controller\Admin\LogController;
use Psr\Container\ContainerInterface;

class LogControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new LogController(
            (bool) $services->get('Config')['logger']['writers']['doctrine']
        );
    }
}
