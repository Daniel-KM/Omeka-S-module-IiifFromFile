<?php declare(strict_types=1);

namespace IiifFromFileTest;

use CommonTest\AbstractHttpControllerTestCase;

class ConfigTest extends AbstractHttpControllerTestCase
{
    use IiifFromFileTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    public function testModuleIsActive(): void
    {
        $services = $this->getServiceLocator();
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('IiifFromFile');
        $this->assertNotNull($module);
        $this->assertEquals('active', $module->getState());
    }

    public function testConfigHasEndpoints(): void
    {
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $this->assertArrayHasKey('iiiffromfile', $config);
        $this->assertArrayHasKey('endpoints', $config['iiiffromfile']);
        $this->assertNotEmpty($config['iiiffromfile']['endpoints']);
    }

    public function testEndpointsHaveRequiredKeys(): void
    {
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        foreach ($config['iiiffromfile']['endpoints'] as $key => $endpoint) {
            $this->assertArrayHasKey('label', $endpoint, "Endpoint $key missing label");
            $this->assertArrayHasKey('api_url', $endpoint, "Endpoint $key missing api_url");
        }
    }

    public function testAdminRouteExists(): void
    {
        $this->dispatch('/admin/iiif-from-file');
        $this->assertResponseStatusCode(200);
    }

    public function testControllerIsRegistered(): void
    {
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $controllers = $config['controllers']['invokables'] ?? [];
        $this->assertArrayHasKey(
            \IiifFromFile\Controller\AdminController::class,
            $controllers
        );
    }

    public function testFormIsRendered(): void
    {
        $this->dispatch('/admin/iiif-from-file');
        $this->assertQuery('form');
        $this->assertQuery('#endpoint');
        $this->assertQuery('#api_key');
        $this->assertQuery('#query');
    }
}
