<?php declare(strict_types=1);

namespace IiifFromFileTest\Repository;

use CommonTest\AbstractHttpControllerTestCase;
use IiifFromFile\Repository\NakalaConnector;
use Laminas\Http\Client as HttpClient;
use Laminas\Log\Logger;
use Laminas\Log\Writer\Noop;

/**
 * @group integration
 * @group network
 *
 * Hits the public Nakala test API (apitest.nakala.fr). Skipped if the
 * environment is offline or NAKALA_TEST_API_KEY is set to an empty string.
 *
 * Default token is the public Huma-Num test token "Unesco" documented at
 * https://documentation.huma-num.fr/nakala-preprod/.
 */
class NakalaConnectorIntegrationTest extends AbstractHttpControllerTestCase
{
    protected const API_URL = 'https://apitest.nakala.fr';
    protected const DEFAULT_TOKEN = 'aae99aba-476e-4ff2-2886-0aaf1bfa6fd2';

    protected NakalaConnector $connector;

    public function setUp(): void
    {
        parent::setUp();
        $token = getenv('NAKALA_TEST_API_KEY');
        if ($token === false) {
            $token = self::DEFAULT_TOKEN;
        }
        if ($token === '') {
            $this->markTestSkipped('No Nakala test API key.');
        }
        if (!$this->isReachable(self::API_URL . '/users/me', $token)) {
            $this->markTestSkipped('Nakala test API unreachable.');
        }
        $logger = new Logger();
        $logger->addWriter(new Noop());
        $this->connector = new NakalaConnector(new HttpClient(), $logger);
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
        $this->assertNotEmpty($result['message']);
    }

    public function testFetchUnknownIdentifier(): void
    {
        $data = $this->connector->fetchData('10.34847/nkl.does-not-exist-xyz');
        $this->assertNull($data);
        $this->assertNotSame('', $this->connector->getLastError());
    }

    public function testUploadAndCreateData(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'nkl-probe-') . '.txt';
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
        $this->assertIsArray($upload, 'uploadFile failed: '
            . $this->connector->getLastError());
        $this->assertNotEmpty($upload['sha1']);

        $collection = (string) getenv('NAKALA_TEST_COLLECTION');
        $result = $this->connector->createData(
            $upload,
            [
                'dcterms:title' => 'IiifFromFile integration probe',
                'dcterms:creator' => 'Berthereau, Daniel',
            ],
            ['collection_id' => $collection, 'status' => 'pending'],
            $media,
            $item
        );
        unlink($tmp);

        $this->assertIsArray($result, 'createData failed: '
            . $this->connector->getLastError());
        $this->assertNotEmpty($result['identifier']);

        // Cleanup: delete the unpublished data.
        $token = getenv('NAKALA_TEST_API_KEY');
        if ($token === false) {
            $token = self::DEFAULT_TOKEN;
        }
        $client = new HttpClient();
        $client->setUri(self::API_URL . '/datas/' . $result['identifier']);
        $client->setMethod('DELETE');
        $client->setHeaders(['X-API-KEY' => $token]);
        $client->send();
    }

    protected function isReachable(string $url, string $token): bool
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "X-API-KEY: {$token}\r\nAccept: application/json\r\n",
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return $body !== false;
    }
}
