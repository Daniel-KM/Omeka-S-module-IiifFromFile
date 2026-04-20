<?php declare(strict_types=1);

namespace IiifFromFileTest;

use Laminas\ServiceManager\ServiceLocatorInterface;

trait IiifFromFileTestTrait
{
    protected bool $isLoggedIn = false;

    protected function getServiceLocator(): ServiceLocatorInterface
    {
        if (isset($this->application) && $this->application !== null) {
            return $this->application->getServiceManager();
        }
        return $this->getApplication()->getServiceManager();
    }

    protected function loginAdmin(): void
    {
        $this->isLoggedIn = true;
        $services = $this->getServiceLocator();
        $auth = $services->get('Omeka\AuthenticationService');
        if ($auth->hasIdentity()) {
            return;
        }
        $adapter = $auth->getAdapter();
        $adapter->setIdentity('admin@example.com');
        $adapter->setCredential('root');
        $auth->authenticate();
    }

    protected function logout(): void
    {
        $this->isLoggedIn = false;
        $auth = $this->getServiceLocator()
            ->get('Omeka\AuthenticationService');
        $auth->clearIdentity();
    }
}
