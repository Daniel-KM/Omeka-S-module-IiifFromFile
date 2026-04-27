<?php declare(strict_types=1);

namespace IiifFromFileTest\Repository;

use CommonTest\AbstractHttpControllerTestCase;
use IiifFromFile\Repository\DataverseConnector;
use Laminas\Http\Client as HttpClient;
use Laminas\Log\Logger;
use Laminas\Log\Writer\Noop;

/**
 * @group integration
 * @group network
 *
 * Hits demo.dataverse.org. demo.dataverse.org has no public automatic token:
 * register a free account and export DATAVERSE_DEMO_API_KEY before running.
 * Skipped otherwise.
 */
class DataverseConnectorIntegrationTest extends AbstractHttpControllerTestCase
{
    protected const API_URL = 'https://demo.dataverse.org';

    protected DataverseConnector $connector;

    public function setUp(): void
    {
        parent::setUp();
        $token = (string) getenv('DATAVERSE_DEMO_API_KEY');
        if ($token === '') {
            $this->markTestSkipped(
                'Set DATAVERSE_DEMO_API_KEY to run Dataverse integration '
                . 'tests. Create a free account on https://demo.dataverse.org '
                . 'and copy the token from Account → API Token.'
            );
        }
        if (!$this->isReachable(self::API_URL . '/api/info/version')) {
            $this->markTestSkipped('demo.dataverse.org unreachable.');
        }
        $logger = new Logger();
        $logger->addWriter(new Noop());
        $this->connector = new DataverseConnector(new HttpClient(), $logger);
        $this->connector->setParams([
            'api_url' => self::API_URL,
            'api_key' => $token,
        ]);
    }

    public function testConnection(): void
    {
        $result = $this->connector->testConnection();
        $this->assertTrue(
            $result['ok'],
            'Connection failed: ' . ($result['message'] ?? '')
        );
    }

    public function testFetchUnknownIdentifier(): void
    {
        $data = $this->connector->fetchData('doi:10.5072/FK2/UNKNOWNXYZ');
        $this->assertNull($data);
        $this->assertNotSame('', $this->connector->getLastError());
    }

    public function testFetchPublicDataset(): void
    {
        // Public sample dataset on demo.dataverse.org.
        $data = $this->connector->fetchData('doi:10.5072/FK2/PPPORT');
        $this->assertIsArray($data);
        $this->assertArrayHasKey('latestVersion', $data);
    }

    public function testCreateDatasetRequiresParentPermission(): void
    {
        $parent = (string) getenv('DATAVERSE_DEMO_PARENT');
        if ($parent === '') {
            $this->markTestSkipped(
                'Set DATAVERSE_DEMO_PARENT to a dataverse alias where the '
                . 'user can create datasets. On demo.dataverse.org, the '
                . 'root collection (alias "root", "Demo Dataverse") does '
                . 'not grant AddDataset to self-registered users: first '
                . 'create your own sub-dataverse via the web UI ("Add '
                . 'Data → New Dataverse" on the home page), then export '
                . 'its alias as DATAVERSE_DEMO_PARENT.'
            );
        }
        $tmp = tempnam(sys_get_temp_dir(), 'dv-probe-');
        file_put_contents($tmp, 'IiifFromFile integration probe.');

        $media = $this->createMock(\Omeka\Api\Representation\MediaRepresentation::class);
        $media->method('id')->willReturn(0);
        $media->method('mediaType')->willReturn('text/plain');
        $media->method('source')->willReturn(basename($tmp));
        $media->method('filename')->willReturn(basename($tmp));
        $media->method('displayTitle')->willReturn(basename($tmp));
        $item = $this->createMock(\Omeka\Api\Representation\ItemRepresentation::class);
        $item->method('id')->willReturn(0);
        $item->method('displayTitle')->willReturn('Probe item');

        $upload = $this->connector->uploadFile($tmp, $media);
        $this->assertIsArray($upload);

        $result = $this->connector->createData(
            $upload,
            [
                'title' => 'IiifFromFile integration probe',
                'author' => 'Berthereau, Daniel',
                'description' => 'Automated test, safe to delete.',
                'subject' => 'Other',
            ],
            ['collection_id' => $parent, 'status' => 'draft'],
            $media,
            $item
        );
        unlink($tmp);

        $this->assertIsArray($result, 'createData failed: '
            . $this->connector->getLastError());
        $this->assertNotEmpty($result['identifier']);
        $this->assertNotEmpty($result['file_id']);

        // Cleanup: delete the draft dataset.
        $client = new HttpClient();
        $client->setUri(self::API_URL
            . '/api/datasets/:persistentId/versions/:draft?persistentId='
            . rawurlencode($result['identifier']));
        $client->setMethod('DELETE');
        $client->setHeaders([
            'X-Dataverse-key' => (string) getenv('DATAVERSE_DEMO_API_KEY'),
        ]);
        $client->send();
    }


    protected function isReachable(string $url): bool
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);
        return @file_get_contents($url, false, $ctx) !== false;
    }
}
