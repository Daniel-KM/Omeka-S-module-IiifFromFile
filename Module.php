<?php declare(strict_types=1);

namespace IiifFromFile;

if (!class_exists('Common\TraitModule', false)) {
    require_once file_exists(dirname(__DIR__) . '/Common/src/TraitModule.php')
        ? dirname(__DIR__) . '/Common/src/TraitModule.php'
        : dirname(__DIR__) . '/Common/TraitModule.php';
}

use Common\TraitModule;
use Laminas\Mvc\MvcEvent;
use Omeka\Module\AbstractModule;

class Module extends AbstractModule
{
    use TraitModule;

    const NAMESPACE = __NAMESPACE__;

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        // Allow roles that can batch-edit items.
        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        $acl->allow(
            ['author', 'editor'],
            [Controller\AdminController::class],
            ['index']
        );
    }
}
