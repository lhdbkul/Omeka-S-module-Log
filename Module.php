<?php declare(strict_types=1);

/*
 * Copyright Daniel Berthereau, 2017-2024
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software.  You can use, modify and/ or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software's author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user's attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software's suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace Log;

if (!class_exists('Common\TraitModule', false)) {
    require_once dirname(__DIR__) . '/Common/TraitModule.php';
}

// Required during migration from Generic to Common.
if (!class_exists(\Common\Log\Formatter\PsrLogAwareTrait::class)) {
    require_once dirname(__DIR__) . '/Common/src/Stdlib/PsrInterpolateInterface.php';
    require_once dirname(__DIR__) . '/Common/src/Stdlib/PsrInterpolateTrait.php';
    require_once dirname(__DIR__) . '/Common/src/Log/Formatter/PsrLogAwareTrait.php';
    require_once dirname(__DIR__) . '/Common/src/Log/Formatter/PsrLogSimple.php';
}

use Common\Stdlib\PsrMessage;
use Common\TraitModule;
use Laminas\ModuleManager\ModuleEvent;
use Laminas\ModuleManager\ModuleManager;
use Laminas\Mvc\MvcEvent;
use Omeka\Module\AbstractModule;
use Omeka\Permissions\Assertion\OwnsEntityAssertion;

class Module extends AbstractModule
{
    use TraitModule;

    public const NAMESPACE = __NAMESPACE__;

    protected $dependencies = [
        'Common',
    ];

    public function init(ModuleManager $moduleManager): void
    {
        require_once __DIR__ . '/vendor/autoload.php';

        $moduleManager->getEventManager()->attach(ModuleEvent::EVENT_MERGE_CONFIG, [$this, 'onEventMergeConfig']);
    }

    /**
     * Force logger log = true in config, else this module is useless.
     */
    public function onEventMergeConfig(ModuleEvent $event): void
    {
        // At this point, the config is read only, so it is copied and replaced.
        /** @var \Laminas\ModuleManager\Listener\ConfigListener $configListener */
        $configListener = $event->getParam('configListener');
        $config = $configListener->getMergedConfig(false);
        $config['logger']['log'] = empty($config['logger']['disable_log']);
        $configListener->setMergedConfig($config);
    }

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);
        $this->addAclRules();
    }

    protected function preInstall(): void
    {
        /** @var \Laminas\Mvc\I18n\Translator $translator */
        $services = $this->getServiceLocator();
        $translator = $services->get('MvcTranslator');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.70')) {
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.70'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }

        if (!file_exists(__DIR__ . '/vendor/autoload.php')
            || !file_exists(__DIR__ . '/vendor/laminas/laminas-db/composer.json')
        ) {
            $message = new PsrMessage(
                'The libraries are not installed. Run "composer install" on the command line or load a version with libraries included.' // @translate
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message->setTranslator($translator));
        }

        if (PHP_VERSION_ID >= 80200) {
            $content = file_get_contents(__DIR__ . '/vendor/laminas/laminas-db/composer.json');
            if (strpos($content, '"php": "^7.3 ||') ) {
                $message = new PsrMessage(
                    'The library is not compatible with the version of php on the server. Run "composer upgrade" on the command line or load a version for php ≥ 8.2.' // @translate
                );
                throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message->setTranslator($translator));
            }
        }
    }

    protected function postInstall(): void
    {
        $services = $this->getServiceLocator();
        $translate = $services->get('ControllerPluginManager')->get('translate');
        $messenger = $services->get('ControllerPluginManager')->get('messenger');

        $messenger->addWarning($translate('Logging is not active by default in Omeka. This module overrides option [logger][log].')); // @translate
        $messenger->addWarning($translate('You may need to update the file config/local.config.php, in particular to set the default level of severity.')); // @translate
        $message = new PsrMessage(
            $translate('See examples of config in the {link}readme{link_end}.'), // @translate
            ['link' => '<a href="https://gitlab.com/Daniel-KM/Omeka-S-module-Log/#config" target="_blank" rel="noopener">', 'link_end' => '</a>']
        );
        $message->setEscapeHtml(false);
        $messenger->addNotice($message);
    }

    /**
     * Add ACL role and rules for this module.
     *
     * @todo Keep rights for Annotation only (body and  target are internal classes).
     */
    protected function addAclRules(): void
    {
        /** @var \Omeka\Permissions\Acl $acl */
        $services = $this->getServiceLocator();
        $acl = $services->get('Omeka\Acl');

        // The resources are added automatically by Omeka (@see \Omeka\Service\AclFactory::addResources).

        // Nevertheless, acl should be specified, because log is not a resource.
        $entityManagerFilters = $services->get('Omeka\EntityManager')->getFilters();
        $entityManagerFilters->enable('log_visibility');
        $entityManagerFilters->getFilter('log_visibility')->setAcl($acl);

        // Public users cannot see own logs.
        // TODO How to make a distinction between public and admin roles? Which new rule?
        $roles = [
            \Omeka\Permissions\Acl::ROLE_RESEARCHER,
            \Omeka\Permissions\Acl::ROLE_AUTHOR,
            \Omeka\Permissions\Acl::ROLE_REVIEWER,
            \Omeka\Permissions\Acl::ROLE_EDITOR,
            \Omeka\Permissions\Acl::ROLE_SITE_ADMIN,
            \Omeka\Permissions\Acl::ROLE_GLOBAL_ADMIN,
        ];
        $baseRoles = [
            \Omeka\Permissions\Acl::ROLE_RESEARCHER,
            \Omeka\Permissions\Acl::ROLE_AUTHOR,
        ];
        $editorRoles = [
            \Omeka\Permissions\Acl::ROLE_REVIEWER,
            \Omeka\Permissions\Acl::ROLE_EDITOR,
        ];
        $adminRoles = [
            \Omeka\Permissions\Acl::ROLE_SITE_ADMIN,
            \Omeka\Permissions\Acl::ROLE_GLOBAL_ADMIN,
        ];

        // TODO Check if not allowed to create log by api?
        // Everybody can create log.
        $acl
            ->allow(
                null,
                [
                    \Log\Api\Adapter\LogAdapter::class,
                    \Log\Entity\Log::class,
                ],
                ['create']
            )

            ->allow(
                $roles,
                [\Log\Controller\Admin\JobController::class],
                ['system-state']
            )

            // Everybody can see own logs, except guest user or visitors.
            ->allow(
                $baseRoles,
                [\Log\Entity\Log::class],
                ['read'],
                new OwnsEntityAssertion
            )
            ->allow(
                $baseRoles,
                [\Log\Api\Adapter\LogAdapter::class],
                ['read', 'search']
            )
            ->allow(
                $baseRoles,
                [\Log\Controller\Admin\LogController::class],
                ['browse', 'search', 'show-details']
            )

            ->allow(
                $editorRoles,
                [\Log\Entity\Log::class],
                ['read', 'view-all']
            )
            ->allow(
                $editorRoles,
                [\Log\Api\Adapter\LogAdapter::class],
                ['read', 'search']
            )
            ->allow(
                $editorRoles,
                [\Log\Controller\Admin\LogController::class],
                ['browse', 'search', 'show-details']
            )

            ->allow(
                $adminRoles,
                [
                    \Log\Entity\Log::class,
                    \Log\Api\Adapter\LogAdapter::class,
                    \Log\Controller\Admin\LogController::class,
                ]
            )
        ;
    }
}
