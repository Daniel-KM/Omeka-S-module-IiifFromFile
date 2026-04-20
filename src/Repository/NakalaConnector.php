<?php declare(strict_types=1);

namespace IiifFromFile\Repository;

use Laminas\Http\Client as HttpClient;
use Laminas\Http\Request;
use Laminas\Log\LoggerInterface;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;

/**
 * Connector for the Nakala repository (Huma-Num).
 *
 * @link https://api.nakala.fr/doc
 * @link https://documentation.huma-num.fr/nakala/
 */
class NakalaConnector implements RepositoryConnectorInterface
{
    /**
     * @var HttpClient
     */
    protected $httpClient;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var string
     */
    protected $apiUrl = '';

    /**
     * @var string
     */
    protected $apiKey = '';

    /**
     * @var string
     */
    protected $lastError = '';

    public function __construct(HttpClient $httpClient, LoggerInterface $logger)
    {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
    }

    public function getLabel(): string
    {
        return 'Nakala';
    }

    public function setParams(array $params): RepositoryConnectorInterface
    {
        $this->apiUrl = rtrim($params['api_url'] ?? '', '/');
        $this->apiKey = $params['api_key'] ?? '';
        return $this;
    }

    public function testConnection(): array
    {
        if (!$this->apiUrl || !$this->apiKey) {
            return ['ok' => false, 'message' => 'Missing API URL or API key.'];
        }

        $this->httpClient->reset();
        $this->httpClient->setUri($this->apiUrl . '/users/me');
        $this->httpClient->setMethod(Request::METHOD_GET);
        $this->httpClient->setHeaders([
            'X-API-KEY' => $this->apiKey,
            'Accept' => 'application/json',
        ]);

        try {
            $response = $this->httpClient->send();
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
            ];
        }

        if (!$response->isSuccess()) {
            return [
                'ok' => false,
                'message' => 'Authentication failed: HTTP '
                    . $response->getStatusCode() . ' '
                    . substr($response->getBody(), 0, 200),
            ];
        }

        $user = json_decode($response->getBody(), true);
        $name = $user['name'] ?? $user['username'] ?? 'unknown';
        return [
            'ok' => true,
            'message' => 'Connected as ' . $name,
        ];
    }

    public function uploadFile(
        string $filePath,
        MediaRepresentation $media
    ): ?array {
        $url = $this->apiUrl . '/uploads';
        $this->lastError = '';

        $this->logger->info(sprintf(
            'Nakala: uploading file for media #%d (%s, %s bytes).',
            $media->id(),
            basename($filePath),
            filesize($filePath)
        ));

        $this->httpClient->reset();
        $this->httpClient->setUri($url);
        $this->httpClient->setMethod(Request::METHOD_POST);
        $this->httpClient->setHeaders([
            'X-API-KEY' => $this->apiKey,
            'Accept' => 'application/json',
        ]);
        $this->httpClient->setFileUpload(
            $filePath,
            'file',
            null,
            $media->mediaType()
        );

        try {
            $response = $this->httpClient->send();
        } catch (\Throwable $e) {
            $this->lastError = 'Upload failed: ' . $e->getMessage();
            $this->logger->err($this->lastError);
            return null;
        }

        $code = $response->getStatusCode();
        $body = $response->getBody();

        if (!$response->isSuccess()) {
            $this->lastError = sprintf(
                'Upload rejected (HTTP %d): %s',
                $code,
                $this->extractErrorMessage($body)
            );
            $this->logger->err(sprintf(
                'Nakala: media #%d: %s',
                $media->id(),
                $this->lastError
            ));
            return null;
        }

        $result = json_decode($body, true);
        if (empty($result['sha1'])) {
            $this->lastError = 'Upload response missing sha1: '
                . substr($body, 0, 300);
            $this->logger->err(sprintf(
                'Nakala: media #%d: %s',
                $media->id(),
                $this->lastError
            ));
            return null;
        }

        $this->logger->info(sprintf(
            'Nakala: media #%d: file uploaded, sha1=%s.',
            $media->id(),
            $result['sha1']
        ));

        return [
            'file_id' => $result['sha1'],
            'sha1' => $result['sha1'],
        ];
    }

    public function createData(
        array $uploadResult,
        array $metadata,
        array $collectionParams,
        MediaRepresentation $media,
        ItemRepresentation $item
    ): ?array {
        $url = $this->apiUrl . '/datas';
        $this->lastError = '';

        $sha1 = $uploadResult['sha1'] ?? '';
        if (!$sha1) {
            $this->lastError = 'No sha1 from upload.';
            $this->logger->err($this->lastError);
            return null;
        }

        // Build Nakala metas from flat metadata.
        $metas = $this->buildNakalaMetas($metadata, $media, $item);

        $body = [
            'files' => [
                [
                    'sha1' => $sha1,
                    'name' => $media->filename()
                        ?: ($media->source() ?: 'file.jpg'),
                ],
            ],
            'metas' => $metas,
            'status' => $collectionParams['status'] ?? 'published',
        ];

        $collectionId = $collectionParams['collection_id'] ?? '';
        if ($collectionId) {
            $body['collectionsIds'] = [$collectionId];
        }

        $this->logger->info(sprintf(
            'Nakala: creating data for media #%d with %d metas, '
            . 'collection=%s, status=%s.',
            $media->id(),
            count($metas),
            $collectionId ?: '(none)',
            $body['status']
        ));

        $this->httpClient->reset();
        $this->httpClient->setUri($url);
        $this->httpClient->setMethod(Request::METHOD_POST);
        $this->httpClient->setHeaders([
            'X-API-KEY' => $this->apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ]);
        $this->httpClient->setRawBody(json_encode(
            $body,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        try {
            $response = $this->httpClient->send();
        } catch (\Throwable $e) {
            $this->lastError = 'Create data failed: ' . $e->getMessage();
            $this->logger->err(sprintf(
                'Nakala: media #%d: %s',
                $media->id(),
                $this->lastError
            ));
            return null;
        }

        $code = $response->getStatusCode();
        $responseBody = $response->getBody();

        if ($code !== 201 && !$response->isSuccess()) {
            $this->lastError = sprintf(
                'Create data rejected (HTTP %d): %s',
                $code,
                $this->extractErrorMessage($responseBody)
            );
            $this->logger->err(sprintf(
                'Nakala: media #%d: %s. Request body: %s',
                $media->id(),
                $this->lastError,
                substr(json_encode($body), 0, 500)
            ));
            return null;
        }

        $result = json_decode($responseBody, true);
        $identifier = $result['payload']['id']
            ?? $result['id'] ?? '';
        $doi = $result['payload']['doi']
            ?? $result['doi'] ?? '';

        if (!$identifier) {
            $this->lastError = 'Response has no identifier: '
                . substr($responseBody, 0, 300);
            $this->logger->err(sprintf(
                'Nakala: media #%d: %s',
                $media->id(),
                $this->lastError
            ));
            return null;
        }

        $this->logger->info(sprintf(
            'Nakala: media #%d: data created, identifier=%s, doi=%s.',
            $media->id(),
            $identifier,
            $doi
        ));

        return [
            'identifier' => $identifier,
            'doi' => $doi,
            'sha1' => $sha1,
        ];
    }

    public function buildIiifInfoUrl(array $dataResult): string
    {
        $identifier = $dataResult['identifier'] ?? '';
        $sha1 = $dataResult['sha1'] ?? '';
        if (!$identifier || !$sha1) {
            return '';
        }
        return $this->apiUrl . '/iiif/' . $identifier
            . '/' . $sha1 . '/info.json';
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    /**
     * Build Nakala-format metas from flat key-value metadata.
     */
    protected function buildNakalaMetas(
        array $metadata,
        MediaRepresentation $media,
        ItemRepresentation $item
    ): array {
        $metas = [];
        foreach ($metadata as $remoteProp => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $metas[] = [
                'propertyUri' => $this->termToUri($remoteProp),
                'value' => $value,
                'lang' => 'fr',
                'typeUri' => 'http://www.w3.org/2001/XMLSchema#string',
            ];
        }

        // Ensure mandatory fields.
        $this->ensureMeta($metas, 'http://purl.org/dc/terms/title',
            (string) ($item->displayTitle() ?: $media->displayTitle()
                ?: $media->source() ?: 'Untitled'));
        $this->ensureMeta($metas, 'http://purl.org/dc/terms/type',
            'http://purl.org/coar/resource_type/c_c513',
            'http://www.w3.org/2001/XMLSchema#anyURI');
        $this->ensureMeta($metas, 'http://purl.org/dc/terms/created',
            date('Y'));
        $this->ensureMeta($metas, 'http://purl.org/dc/terms/license',
            'CC BY 4.0');

        return $metas;
    }

    /**
     * Add a meta only if not already present.
     */
    protected function ensureMeta(
        array &$metas,
        string $uri,
        string $value,
        string $typeUri = 'http://www.w3.org/2001/XMLSchema#string'
    ): void {
        foreach ($metas as $meta) {
            if (($meta['propertyUri'] ?? '') === $uri) {
                return;
            }
        }
        $meta = [
            'propertyUri' => $uri,
            'value' => $value,
            'typeUri' => $typeUri,
        ];
        if ($typeUri === 'http://www.w3.org/2001/XMLSchema#string') {
            $meta['lang'] = 'fr';
        }
        $metas[] = $meta;
    }

    /**
     * Convert a property term to a full URI.
     */
    protected function termToUri(string $term): string
    {
        static $prefixes = [
            'dcterms' => 'http://purl.org/dc/terms/',
            'dc' => 'http://purl.org/dc/elements/1.1/',
            'foaf' => 'http://xmlns.com/foaf/0.1/',
            'bibo' => 'http://purl.org/ontology/bibo/',
        ];
        $parts = explode(':', $term, 2);
        if (count($parts) === 2 && isset($prefixes[$parts[0]])) {
            return $prefixes[$parts[0]] . $parts[1];
        }
        return $term;
    }

    /**
     * Extract error message from API response body.
     */
    protected function extractErrorMessage(string $body): string
    {
        $json = json_decode($body, true);
        if ($json) {
            return $json['message']
                ?? $json['error']
                ?? $json['detail']
                ?? json_encode($json['errors'] ?? $json, JSON_UNESCAPED_UNICODE);
        }
        return substr($body, 0, 300);
    }
}
