<?php declare(strict_types=1);

namespace Log\Service\ControllerPlugin;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Log\Mvc\Controller\Plugin\JobState;
use Psr\Container\ContainerInterface;

class JobStateFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new JobState(
            $services->get('Log\JobState')
        );
    }
}
