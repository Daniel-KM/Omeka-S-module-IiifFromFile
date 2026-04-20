<?php declare(strict_types=1);

namespace IiifFromFileTest\Job;

use CommonTest\AbstractHttpControllerTestCase;
use IiifFromFile\Job\ExportToRepository;
use IiifFromFileTest\IiifFromFileTestTrait;

class ExportToRepositoryTest extends AbstractHttpControllerTestCase
{
    use IiifFromFileTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    public function testJobClassExists(): void
    {
        $this->assertTrue(class_exists(ExportToRepository::class));
    }

    public function testPropertyTermToUri(): void
    {
        $job = new \ReflectionClass(ExportToRepository::class);
        $method = $job->getMethod('propertyTermToUri');
        $method->setAccessible(true);

        $instance = $job->newInstanceWithoutConstructor();

        $this->assertEquals(
            'http://purl.org/dc/terms/title',
            $method->invoke($instance, 'dcterms:title')
        );
        $this->assertEquals(
            'http://purl.org/dc/terms/creator',
            $method->invoke($instance, 'dcterms:creator')
        );
        $this->assertEquals(
            'http://purl.org/dc/elements/1.1/title',
            $method->invoke($instance, 'dc:title')
        );
        $this->assertEquals(
            'http://custom.uri/term',
            $method->invoke($instance, 'http://custom.uri/term')
        );
    }

    public function testBuildIiifUrl(): void
    {
        $job = new \ReflectionClass(ExportToRepository::class);
        $method = $job->getMethod('buildIiifUrl');
        $method->setAccessible(true);

        $instance = $job->newInstanceWithoutConstructor();

        $url = $method->invoke(
            $instance,
            'https://apitest.nakala.fr',
            '10.34847/nkl.abc123',
            'abcdef1234567890'
        );

        $this->assertStringContainsString('iiif', $url);
        $this->assertStringContainsString('nkl.abc123', $url);
        $this->assertStringContainsString('abcdef1234567890', $url);
    }
}
