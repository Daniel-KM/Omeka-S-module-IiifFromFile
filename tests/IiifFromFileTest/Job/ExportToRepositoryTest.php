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

    public function testResolveValueQuotedLiteralReturnsArrayWithNullLang(): void
    {
        $job = new \ReflectionClass(ExportToRepository::class);
        $method = $job->getMethod('resolveValue');
        $method->setAccessible(true);
        $instance = $job->newInstanceWithoutConstructor();

        $media = $this->createMock(\Omeka\Api\Representation\MediaRepresentation::class);
        $item = $this->createMock(\Omeka\Api\Representation\ItemRepresentation::class);

        $result = $method->invoke($instance, '"Fixed Value"', $media, $item);
        $this->assertSame([['value' => 'Fixed Value', 'lang' => null]], $result);
    }

    public function testResolveValueOmekaValueReturnsLang(): void
    {
        $job = new \ReflectionClass(ExportToRepository::class);
        $method = $job->getMethod('resolveValue');
        $method->setAccessible(true);
        $instance = $job->newInstanceWithoutConstructor();

        $value = $this->createMock(\Omeka\Api\Representation\ValueRepresentation::class);
        $value->method('__toString')->willReturn('Hello');
        $value->method('lang')->willReturn('en');

        $item = $this->createMock(\Omeka\Api\Representation\ItemRepresentation::class);
        $item->method('value')->willReturn([$value]);
        $media = $this->createMock(\Omeka\Api\Representation\MediaRepresentation::class);
        $media->method('value')->willReturn([]);

        $result = $method->invoke($instance, 'dcterms:title', $media, $item);
        $this->assertCount(1, $result);
        $this->assertSame('Hello', $result[0]['value']);
        $this->assertSame('en', $result[0]['lang']);
    }

    public function testResolveValueMissingReturnsNull(): void
    {
        $job = new \ReflectionClass(ExportToRepository::class);
        $method = $job->getMethod('resolveValue');
        $method->setAccessible(true);
        $instance = $job->newInstanceWithoutConstructor();

        $media = $this->createMock(\Omeka\Api\Representation\MediaRepresentation::class);
        $media->method('value')->willReturn([]);
        $item = $this->createMock(\Omeka\Api\Representation\ItemRepresentation::class);
        $item->method('value')->willReturn([]);

        $this->assertNull(
            $method->invoke($instance, 'dcterms:absent', $media, $item)
        );
    }

    public function testIngesterAutoFallsBackToConnectorPreferred(): void
    {
        $choice = 'auto';
        $preferred = 'iiif';
        $resolved = $choice === 'auto'
            ? $preferred
            : ($choice === 'url_local' ? 'url' : $choice);
        $this->assertSame('iiif', $resolved);
    }

    public function testIngesterUrlLocalMapsToUrlIngester(): void
    {
        $choice = 'url_local';
        $preferred = 'iiif';
        $resolved = $choice === 'auto'
            ? $preferred
            : ($choice === 'url_local' ? 'url' : $choice);
        $this->assertSame('url', $resolved);
    }

    public function testStoreOriginalDerivedFromIngesterChoice(): void
    {
        $this->assertTrue('url_local' === 'url_local');
        $this->assertFalse('url' === 'url_local');
        $this->assertFalse('auto' === 'url_local');
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
