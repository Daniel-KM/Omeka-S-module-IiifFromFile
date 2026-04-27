<?php declare(strict_types=1);

namespace IiifFromFileTest\Repository;

use IiifFromFile\Repository\NakalaConnector;
use Laminas\Http\Client as HttpClient;
use Laminas\Log\Logger;
use Laminas\Log\Writer\Noop;
use PHPUnit\Framework\TestCase;

class NakalaConnectorUnitTest extends TestCase
{
    protected NakalaConnector $connector;

    public function setUp(): void
    {
        $logger = new Logger();
        $logger->addWriter(new Noop());
        $this->connector = new NakalaConnector(new HttpClient(), $logger);
    }

    protected function invoke(string $method, array $args)
    {
        $r = new \ReflectionMethod($this->connector, $method);
        $r->setAccessible(true);
        return $r->invokeArgs($this->connector, $args);
    }

    public function testExtractValueLangScalar(): void
    {
        [$value, $lang] = $this->invoke('extractValueLang', ['hello']);
        $this->assertSame('hello', $value);
        $this->assertSame('fr', $lang);
    }

    public function testExtractValueLangArrayWithLang(): void
    {
        [$value, $lang] = $this->invoke(
            'extractValueLang',
            [['value' => 'hello', 'lang' => 'en']]
        );
        $this->assertSame('hello', $value);
        $this->assertSame('en', $lang);
    }

    public function testExtractValueLangArrayWithoutLangFallsBackToDefault(): void
    {
        $this->connector->setParams(['default_lang' => 'br']);
        [$value, $lang] = $this->invoke(
            'extractValueLang',
            [['value' => 'demat', 'lang' => null]]
        );
        $this->assertSame('demat', $value);
        $this->assertSame('br', $lang);
    }

    public function testDefaultLangParam(): void
    {
        $this->connector->setParams(['default_lang' => 'en']);
        $metas = $this->invoke('buildNakalaMetasFlat', [[
            'dcterms:description' => ['value' => 'a desc', 'lang' => null],
        ]]);
        $this->assertSame('en', $metas[0]['lang']);
    }

    public function testBuildMetasFlatPerValueLang(): void
    {
        $metas = $this->invoke('buildNakalaMetasFlat', [[
            'dcterms:title' => ['value' => 'Hello', 'lang' => 'en'],
            'dcterms:description' => ['value' => 'Bonjour', 'lang' => 'fr'],
        ]]);
        $this->assertCount(2, $metas);
        $byLang = array_column($metas, 'lang', 'value');
        $this->assertSame('en', $byLang['Hello']);
        $this->assertSame('fr', $byLang['Bonjour']);
    }

    public function testBuildMetasFlatMultiValueExpandsToMultipleMetas(): void
    {
        $metas = $this->invoke('buildNakalaMetasFlat', [[
            'http://purl.org/dc/terms/subject' => [
                ['value' => 'cats', 'lang' => 'en'],
                ['value' => 'chats', 'lang' => 'fr'],
            ],
        ]]);
        $this->assertCount(2, $metas);
        $values = array_column($metas, 'value');
        $this->assertContains('cats', $values);
        $this->assertContains('chats', $values);
    }

    public function testBuildMetasFlatLegacyScalarStillSupported(): void
    {
        $metas = $this->invoke('buildNakalaMetasFlat', [[
            'dcterms:title' => 'Plain string',
        ]]);
        $this->assertSame('Plain string', $metas[0]['value']);
        $this->assertSame('fr', $metas[0]['lang']);
    }

    public function testBuildMetasFlatTypeCreatedLicenseHaveNoLang(): void
    {
        $metas = $this->invoke('buildNakalaMetasFlat', [[
            'http://nakala.fr/terms#type' => 'http://purl.org/coar/resource_type/c_c513',
            'http://nakala.fr/terms#created' => '2026',
            'http://nakala.fr/terms#license' => 'CC-BY-4.0',
        ]]);
        foreach ($metas as $m) {
            $this->assertArrayNotHasKey('lang', $m, $m['propertyUri']);
        }
    }

    public function testBuildMetasFlatCreatorObject(): void
    {
        $metas = $this->invoke('buildNakalaMetasFlat', [[
            'http://nakala.fr/terms#creator' => 'Berthereau, Daniel',
        ]]);
        $this->assertCount(1, $metas);
        $this->assertIsArray($metas[0]['value']);
        $this->assertArrayHasKey('surname', $metas[0]['value']);
    }

    public function testMergeMetasOverwrite(): void
    {
        $existing = [
            ['propertyUri' => 'http://nakala.fr/terms#title', 'value' => 'Old', 'lang' => 'fr'],
            ['propertyUri' => 'http://purl.org/dc/terms/subject', 'value' => 'Subject', 'lang' => 'fr'],
        ];
        $new = [
            ['propertyUri' => 'http://nakala.fr/terms#title', 'value' => 'New', 'lang' => 'en'],
        ];
        $merged = $this->invoke('mergeMetas', [$existing, $new, 'overwrite']);
        $this->assertCount(2, $merged);
        $byUri = array_column($merged, 'value', 'propertyUri');
        $this->assertSame('New', $byUri['http://nakala.fr/terms#title']);
        $this->assertSame('Subject', $byUri['http://purl.org/dc/terms/subject']);
    }

    public function testMergeMetasComplete(): void
    {
        $existing = [
            ['propertyUri' => 'http://nakala.fr/terms#title', 'value' => 'Old', 'lang' => 'fr'],
        ];
        $new = [
            ['propertyUri' => 'http://nakala.fr/terms#title', 'value' => 'Ignored', 'lang' => 'fr'],
            ['propertyUri' => 'http://purl.org/dc/terms/subject', 'value' => 'Added', 'lang' => 'fr'],
        ];
        $merged = $this->invoke('mergeMetas', [$existing, $new, 'complete']);
        $byUri = array_column($merged, 'value', 'propertyUri');
        $this->assertSame('Old', $byUri['http://nakala.fr/terms#title']);
        $this->assertSame('Added', $byUri['http://purl.org/dc/terms/subject']);
    }

    public function testCheckMandatoryMetasMissing(): void
    {
        $missing = $this->invoke('checkMandatoryMetas', [[
            ['propertyUri' => 'http://nakala.fr/terms#title', 'value' => 'x'],
        ]]);
        $this->assertContains('http://nakala.fr/terms#creator', $missing);
        $this->assertContains('http://nakala.fr/terms#type', $missing);
        $this->assertContains('http://nakala.fr/terms#created', $missing);
        $this->assertContains('http://nakala.fr/terms#license', $missing);
        $this->assertNotContains('http://nakala.fr/terms#title', $missing);
    }

    public function testIsValidIdentifierAcceptsCommonShapes(): void
    {
        $this->assertTrue($this->connector->isValidIdentifier('10.34847/nkl.abc123'));
        $this->assertTrue($this->connector->isValidIdentifier('doi:10.34847/nkl.abc123'));
        $this->assertTrue($this->connector->isValidIdentifier('https://nakala.fr/10.34847/nkl.abc123'));
        $this->assertTrue($this->connector->isValidIdentifier('https://doi.org/10.34847/nkl.abc123'));
        $this->assertTrue($this->connector->isValidIdentifier('  10.34847/nkl.abc123  '));
    }

    public function testIsValidIdentifierRejectsMalformed(): void
    {
        $this->assertFalse($this->connector->isValidIdentifier(''));
        $this->assertFalse($this->connector->isValidIdentifier('garbage'));
        $this->assertFalse($this->connector->isValidIdentifier('10.34847/abc123'));
    }

    public function testCheckMandatoryMetasComplete(): void
    {
        $missing = $this->invoke('checkMandatoryMetas', [[
            ['propertyUri' => 'http://nakala.fr/terms#title'],
            ['propertyUri' => 'http://nakala.fr/terms#creator'],
            ['propertyUri' => 'http://nakala.fr/terms#type'],
            ['propertyUri' => 'http://nakala.fr/terms#created'],
            ['propertyUri' => 'http://nakala.fr/terms#license'],
        ]]);
        $this->assertSame([], $missing);
    }
}
