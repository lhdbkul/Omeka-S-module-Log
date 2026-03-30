<?php declare(strict_types=1);

namespace Log\Service\ViewHelper;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Log\View\Helper\JobState;
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
