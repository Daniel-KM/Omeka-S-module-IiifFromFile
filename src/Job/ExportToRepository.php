<?php declare(strict_types=1);

namespace IiifFromFile\Job;

use Laminas\Http\Client as HttpClient;
use Laminas\Http\Request;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Job\AbstractJob;

class ExportToRepository extends AbstractJob
{
    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var HttpClient
     */
    protected $httpClient;

    /**
     * @var array
     */
    protected $endpointConfig;

    /**
     * @var string
     */
    protected $apiKey;

    /**
     * @var string
     */
    protected $apiUser;

    protected int $totalProcessed = 0;
    protected int $totalSucceed = 0;
    protected int $totalFailed = 0;
    protected int $totalSkipped = 0;

    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $this->logger = $services->get('Omeka\Logger');
        $this->api = $services->get('Omeka\ApiManager');
        $this->httpClient = $services->get('Omeka\HttpClient');

        $args = $this->job->getArgs() ?? [];
        $this->endpointConfig = $args['endpoint_config'] ?? [];
        $this->apiKey = $args['api_key'] ?? '';
        $this->apiUser = $args['api_user'] ?? '';

        $collectionParams = $args['collection_params'] ?? [];
        $metadataMapping = $args['metadata_mapping'] ?? [];
        $propertyIdentifier = $args['property_identifier'] ?? '';
        $propertyUrl = $args['property_url'] ?? '';
        $query = $args['query'] ?? [];

        $apiUrl = rtrim($this->endpointConfig['api_url'] ?? '', '/');
        if (!$apiUrl || !$this->apiKey) {
            $this->logger->err(
                'Missing API URL or API key.' // @translate
            );
            return;
        }

        $this->logger->info(
            'Starting export to {endpoint}.', // @translate
            ['endpoint' => $this->endpointConfig['label'] ?? $apiUrl]
        );

        // Fetch items.
        $query['limit'] = 0;
        $items = $this->api->search('items', $query)->getContent();

        $this->logger->info(
            '{count} items to process.', // @translate
            ['count' => count($items)]
        );

        foreach ($items as $item) {
            if ($this->shouldStop()) {
                $this->logger->warn(
                    'Job stopped by user.' // @translate
                );
                break;
            }

            foreach ($item->media() as $media) {
                $mainType = strtok((string) $media->mediaType(), '/');
                if ($mainType !== 'image') {
                    continue;
                }
                // Skip media already using IIIF ingester.
                if ($media->ingester() === 'iiif') {
                    ++$this->totalSkipped;
                    continue;
                }

                ++$this->totalProcessed;

                $result = $this->exportMedia(
                    $media,
                    $item,
                    $apiUrl,
                    $collectionParams,
                    $metadataMapping,
                    $propertyIdentifier,
                    $propertyUrl
                );

                if ($result) {
                    ++$this->totalSucceed;
                } else {
                    ++$this->totalFailed;
                }
            }
        }

        $this->logger->notice(
            'Export complete: {succeed} exported, {failed} errors, {skipped} skipped (already IIIF), {total} processed.', // @translate
            [
                'succeed' => $this->totalSucceed,
                'failed' => $this->totalFailed,
                'skipped' => $this->totalSkipped,
                'total' => $this->totalProcessed,
            ]
        );
    }

    /**
     * Export a single media to the remote repository.
     */
    protected function exportMedia(
        MediaRepresentation $media,
        $item,
        string $apiUrl,
        array $collectionParams,
        array $metadataMapping,
        string $propertyIdentifier,
        string $propertyUrl
    ): bool {
        // Step 1: Upload the file.
        $filePath = $this->getLocalFilePath($media);
        if (!$filePath || !file_exists($filePath)) {
            $this->logger->err(
                'Media #{media_id}: local file not found.', // @translate
                ['media_id' => $media->id()]
            );
            return false;
        }

        $uploadResult = $this->uploadFile($apiUrl, $filePath, $media);
        if (!$uploadResult) {
            return false;
        }

        $sha1 = $uploadResult['sha1'] ?? '';

        // Step 2: Create the data object with metadata.
        $metadata = $this->buildMetadata($media, $item, $metadataMapping);
        $dataResult = $this->createData(
            $apiUrl,
            $sha1,
            $media->filename(),
            $metadata,
            $collectionParams
        );

        if (!$dataResult) {
            return false;
        }

        $remoteId = $dataResult['identifier'] ?? '';
        $iiifUrl = $this->buildIiifUrl($apiUrl, $remoteId, $sha1);

        $this->logger->info(
            'Media #{media_id}: exported as {identifier}, IIIF: {url}', // @translate
            [
                'media_id' => $media->id(),
                'identifier' => $remoteId,
                'url' => $iiifUrl,
            ]
        );

        // Step 3: Update the media in Omeka: replace file with IIIF
        // reference and add identifier/url properties.
        $this->updateMedia(
            $media,
            $iiifUrl,
            $remoteId,
            $propertyIdentifier,
            $propertyUrl
        );

        return true;
    }

    /**
     * Get the local filesystem path for a media.
     */
    protected function getLocalFilePath(MediaRepresentation $media): ?string
    {
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path']
            ?: (OMEKA_PATH . '/files');
        $filename = $media->filename();
        if (!$filename) {
            return null;
        }
        return $basePath . '/original/' . $filename;
    }

    /**
     * Upload a file to the remote repository.
     *
     * @return array|null Upload result with sha1, or null on failure.
     */
    protected function uploadFile(
        string $apiUrl,
        string $filePath,
        MediaRepresentation $media
    ): ?array {
        $url = $apiUrl . '/uploads';
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
            $this->logger->err(
                'Media #{media_id}: upload failed: {error}', // @translate
                ['media_id' => $media->id(), 'error' => $e->getMessage()]
            );
            return null;
        }

        if (!$response->isSuccess()) {
            $this->logger->err(
                'Media #{media_id}: upload returned HTTP {code}: {body}', // @translate
                [
                    'media_id' => $media->id(),
                    'code' => $response->getStatusCode(),
                    'body' => substr($response->getBody(), 0, 500),
                ]
            );
            return null;
        }

        $result = json_decode($response->getBody(), true);
        if (empty($result['sha1'])) {
            $this->logger->err(
                'Media #{media_id}: upload response has no sha1.', // @translate
                ['media_id' => $media->id()]
            );
            return null;
        }

        return $result;
    }

    /**
     * Create a data object on the remote repository.
     *
     * @return array|null Data result with identifier, or null on failure.
     */
    protected function createData(
        string $apiUrl,
        string $sha1,
        string $filename,
        array $metadata,
        array $collectionParams
    ): ?array {
        $url = $apiUrl . '/datas';

        $body = [
            'files' => [
                [
                    'sha1' => $sha1,
                    'name' => $filename,
                ],
            ],
            'metas' => $metadata,
            'status' => $collectionParams['status'] ?? 'published',
        ];

        $collectionId = $collectionParams['collection_id'] ?? '';
        if ($collectionId) {
            $body['collectionsIds'] = [$collectionId];
        }

        $this->httpClient->reset();
        $this->httpClient->setUri($url);
        $this->httpClient->setMethod(Request::METHOD_POST);
        $this->httpClient->setHeaders([
            'X-API-KEY' => $this->apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ]);
        $this->httpClient->setRawBody(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        try {
            $response = $this->httpClient->send();
        } catch (\Throwable $e) {
            $this->logger->err(
                'Create data failed: {error}', // @translate
                ['error' => $e->getMessage()]
            );
            return null;
        }

        if ($response->getStatusCode() !== 201 && !$response->isSuccess()) {
            $this->logger->err(
                'Create data returned HTTP {code}: {body}', // @translate
                [
                    'code' => $response->getStatusCode(),
                    'body' => substr($response->getBody(), 0, 500),
                ]
            );
            return null;
        }

        $result = json_decode($response->getBody(), true);
        if (empty($result['payload']['id'])) {
            $this->logger->err(
                'Create data response has no identifier: {body}', // @translate
                ['body' => substr($response->getBody(), 0, 500)]
            );
            return null;
        }

        return [
            'identifier' => $result['payload']['id'],
            'doi' => $result['payload']['doi'] ?? '',
        ];
    }

    /**
     * Build metadata array for the remote API from mapping.
     */
    protected function buildMetadata(
        MediaRepresentation $media,
        $item,
        array $mapping
    ): array {
        $metas = [];
        foreach ($mapping as $line) {
            if (!is_string($line)) {
                continue;
            }
            $parts = array_map('trim', explode('=', $line, 2));
            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                continue;
            }
            [$remoteProp, $localSource] = $parts;

            $value = $this->resolveValue($localSource, $media, $item);
            if ($value === null || $value === '') {
                continue;
            }

            // Nakala metadata format.
            $metas[] = [
                'propertyUri' => $this->propertyTermToUri($remoteProp),
                'value' => $value,
                'lang' => 'fr',
                'typeUri' => 'http://www.w3.org/2001/XMLSchema#string',
            ];
        }

        // Ensure at least a title.
        $hasTitle = false;
        foreach ($metas as $meta) {
            if (strpos($meta['propertyUri'] ?? '', 'title') !== false) {
                $hasTitle = true;
                break;
            }
        }
        if (!$hasTitle) {
            $title = (string) $media->displayTitle();
            if (!$title) {
                $title = $media->source() ?: $media->filename() ?: 'Untitled';
            }
            $metas[] = [
                'propertyUri' => 'http://purl.org/dc/terms/title',
                'value' => $title,
                'lang' => 'fr',
                'typeUri' => 'http://www.w3.org/2001/XMLSchema#string',
            ];
        }

        // Ensure a type (required by Nakala).
        $hasType = false;
        foreach ($metas as $meta) {
            if (strpos($meta['propertyUri'] ?? '', '/type') !== false) {
                $hasType = true;
                break;
            }
        }
        if (!$hasType) {
            $metas[] = [
                'propertyUri' => 'http://purl.org/dc/terms/type',
                'value' => 'http://purl.org/coar/resource_type/c_c513',
                'typeUri' => 'http://www.w3.org/2001/XMLSchema#anyURI',
            ];
        }

        return $metas;
    }

    /**
     * Resolve a local source value from the mapping.
     */
    protected function resolveValue(
        string $source,
        MediaRepresentation $media,
        $item
    ): ?string {
        // Quoted fixed value: "some text".
        if (preg_match('/^"(.*)"$/', $source, $m)) {
            return $m[1];
        }

        // Prefixed item property: o:item/dcterms:title.
        if (strpos($source, 'o:item/') === 0) {
            $term = substr($source, 7);
            $value = $item->value($term);
            return $value ? (string) $value : null;
        }

        // Media property: dcterms:title.
        $value = $media->value($source);
        if ($value) {
            return (string) $value;
        }

        // Fallback: try on item.
        $value = $item->value($source);
        return $value ? (string) $value : null;
    }

    /**
     * Convert a property term (dcterms:title) to a full URI.
     */
    protected function propertyTermToUri(string $term): string
    {
        $prefixes = [
            'dcterms' => 'http://purl.org/dc/terms/',
            'dc' => 'http://purl.org/dc/elements/1.1/',
            'foaf' => 'http://xmlns.com/foaf/0.1/',
            'bibo' => 'http://purl.org/ontology/bibo/',
        ];
        $parts = explode(':', $term, 2);
        if (count($parts) === 2 && isset($prefixes[$parts[0]])) {
            return $prefixes[$parts[0]] . $parts[1];
        }
        // Already a URI or unknown prefix.
        return $term;
    }

    /**
     * Build IIIF image URL from Nakala identifiers.
     */
    protected function buildIiifUrl(
        string $apiUrl,
        string $identifier,
        string $sha1
    ): string {
        return rtrim($apiUrl, '/') . '/iiif/' . $identifier
            . '/' . $sha1 . '/full/max/0/default.jpg';
    }

    /**
     * Update the Omeka media: replace local file with IIIF reference
     * and optionally store the remote identifier and URL.
     */
    protected function updateMedia(
        MediaRepresentation $media,
        string $iiifUrl,
        string $remoteId,
        string $propertyIdentifier,
        string $propertyUrl
    ): void {
        $data = [];

        // Add identifier property if configured.
        if ($propertyIdentifier) {
            $data[$propertyIdentifier][] = [
                'type' => 'literal',
                'property_id' => $this->getPropertyId($propertyIdentifier),
                '@value' => $remoteId,
            ];
        }

        // Add URL property if configured.
        if ($propertyUrl) {
            $data[$propertyUrl][] = [
                'type' => 'uri',
                'property_id' => $this->getPropertyId($propertyUrl),
                '@id' => $iiifUrl,
                'o:label' => 'IIIF',
            ];
        }

        // Replace the media ingester/source with IIIF.
        // The info.json URL for the IIIF image.
        $infoUrl = preg_replace(
            '#/full/max/0/default\.jpg$#',
            '/info.json',
            $iiifUrl
        );
        $data['o:ingester'] = 'iiif';
        $data['o:source'] = $infoUrl;
        $data['ingest_url'] = $infoUrl;

        try {
            $this->api->update('media', $media->id(), $data, [], [
                'isPartial' => true,
            ]);
        } catch (\Throwable $e) {
            $this->logger->err(
                'Media #{media_id}: failed to update: {error}', // @translate
                ['media_id' => $media->id(), 'error' => $e->getMessage()]
            );
        }
    }

    /**
     * Get the property id from a term.
     */
    protected function getPropertyId(string $term): ?int
    {
        static $cache = [];
        if (isset($cache[$term])) {
            return $cache[$term];
        }
        try {
            $result = $this->api->search('properties', [
                'term' => $term,
                'limit' => 1,
            ])->getContent();
            $cache[$term] = $result ? $result[0]->id() : null;
        } catch (\Throwable $e) {
            $cache[$term] = null;
        }
        return $cache[$term];
    }
}
