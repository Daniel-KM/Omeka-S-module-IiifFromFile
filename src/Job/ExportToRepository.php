<?php declare(strict_types=1);

namespace IiifFromFile\Job;

use IiifFromFile\Repository\RepositoryConnectorInterface;
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

    protected $totalProcessed = 0;
    protected $totalSucceed = 0;
    protected $totalFailed = 0;
    protected $totalSkipped = 0;

    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $this->logger = $services->get('Omeka\Logger');
        $this->api = $services->get('Omeka\ApiManager');

        $args = $this->job->getArgs() ?? [];
        $endpointConfig = $args['endpoint_config'] ?? [];
        $connectorClass = $endpointConfig['connector'] ?? '';

        if (!$connectorClass || !$services->has($connectorClass)) {
            $this->logger->err(
                'Unknown or missing connector: {connector}.', // @translate
                ['connector' => $connectorClass]
            );
            return;
        }

        /** @var RepositoryConnectorInterface $connector */
        $connector = $services->get($connectorClass);
        $connector->setParams([
            'api_url' => $endpointConfig['api_url'] ?? '',
            'api_key' => $args['api_key'] ?? '',
            'api_user' => $args['api_user'] ?? '',
        ]);

        // Redact the api_key from the persisted job arguments so the secret is
        // not retained in the job table or surfaced in logs.
        $this->redactApiKey();

        // Test connection first.
        $test = $connector->testConnection();
        if (!$test['ok']) {
            $this->logger->err(
                'Connection test failed: {message}', // @translate
                ['message' => $test['message']]
            );
            return;
        }

        $this->logger->info(
            'Connected to {label}: {message}', // @translate
            [
                'label' => $connector->getLabel(),
                'message' => $test['message'],
            ]
        );

        $collectionParams = $args['collection_params'] ?? [];
        $metadataMapping = $args['metadata_mapping'] ?? [];
        $propertyIdentifier = $args['property_identifier'] ?? '';
        $propertyUrl = $args['property_url'] ?? '';
        $mediaMode = $args['media_mode'] ?? 'convert_delete_original';
        if (!in_array($mediaMode, ['convert', 'convert_delete_original', 'convert_delete', 'add'], true)) {
            $mediaMode = 'convert_delete_original';
        }
        $storeOriginal = !empty($args['store_original']);
        $query = $args['query'] ?? [];

        // Use IiifServer plugin if installed, else fallback.
        $plugins = $services->get('ControllerPluginManager');
        $isIiifMedia = $plugins->has('isIiifMedia')
            ? $plugins->get('isIiifMedia')
            : fn ($media, $type = null) => $media->ingester() === 'iiif';

        // Fetch items.
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
                if ($isIiifMedia($media, 'image')) {
                    ++$this->totalSkipped;
                    continue;
                }

                ++$this->totalProcessed;

                $success = $this->exportMedia(
                    $connector, $media, $item,
                    $collectionParams, $metadataMapping,
                    $propertyIdentifier, $propertyUrl, $mediaMode,
                    $ingesterChoice, $storeOriginal
                );

                if ($success) {
                    ++$this->totalSucceed;
                } else {
                    ++$this->totalFailed;
                }
            }
        }

        $this->logger->notice(
            'Export complete: {succeed} exported, {failed} errors, {skipped} skipped, {total} processed.', // @translate
            [
                'succeed' => $this->totalSucceed,
                'failed' => $this->totalFailed,
                'skipped' => $this->totalSkipped,
                'total' => $this->totalProcessed,
            ]
        );
    }

    protected function exportMedia(
        RepositoryConnectorInterface $connector,
        MediaRepresentation $media,
        $item,
        array $collectionParams,
        array $metadataMapping,
        string $propertyIdentifier,
        string $propertyUrl,
        string $mediaMode,
        string $ingesterChoice = 'auto',
        bool $storeOriginal = true
    ): bool {
        $filePath = $this->getLocalFilePath($media);
        if (!$filePath || !file_exists($filePath)) {
            $this->logger->err(
                'Media #{media_id}: file not found ({path}).', // @translate
                ['media_id' => $media->id(), 'path' => $filePath ?? '']
            );
            return false;
        }

        // Step 1: Upload.
        $uploadResult = $connector->uploadFile($filePath, $media);
        if (!$uploadResult) {
            $this->logger->err(
                'Media #{media_id}: upload failed: {error}', // @translate
                ['media_id' => $media->id(), 'error' => $connector->getLastError()]
            );
            return false;
        }

        // Step 2: Resolve metadata.
        $metadata = $this->resolveMetadata($metadataMapping, $media, $item);

        // Step 3: Create data object.
        $dataResult = $connector->createData(
            $uploadResult, $metadata, $collectionParams, $media, $item
        );
        if (!$dataResult) {
            $this->logger->err(
                'Media #{media_id}: deposit rejected: {error}', // @translate
                ['media_id' => $media->id(), 'error' => $connector->getLastError()]
            );
            return false;
        }

        // Step 4: Build IIIF URL.
        $iiifInfoUrl = $connector->buildIiifInfoUrl($dataResult);
        $remoteId = $dataResult['identifier'] ?? '';
        $doi = $dataResult['doi'] ?? '';
        $dataUri = $dataResult['data_uri'] ?? '';

        $this->logger->info(
            'Media #{media_id}: exported as {identifier} (DOI: {doi}, URL: {url}).', // @translate
            [
                'media_id' => $media->id(),
                'identifier' => $remoteId,
                'doi' => $doi ?: '(none)',
                'url' => $dataUri ?: '(none)',
            ]
        );

        // Step 5: Update Omeka media using the connector preferred ingester
        // ("iiif" when a IIIF Image API is available, else "url"). The
        // store_original flag is forwarded to the Url ingester for new media
        // (see Omeka\Media\Ingester\Url).
        $ingester = $connector->getPreferredIngester();
        $accessUrl = $connector->buildAccessUrl($dataResult);
        $this->updateMedia(
            $media, $item, $iiifInfoUrl, $remoteId, $doi, $dataUri,
            $propertyIdentifier, $propertyUrl, $mediaMode,
            $ingester, $accessUrl, $storeOriginal
        );

        return true;
    }

    protected function getLocalFilePath(MediaRepresentation $media): ?string
    {
        $config = $this->getServiceLocator()->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $filename = $media->filename();
        return $filename
            ? $basePath . '/original/' . $filename
            : null;
    }

    /**
     * Resolve metadata mapping to flat key-value pairs.
     */
    protected function resolveMetadata(array $mapping, $media, $item): array
    {
        $result = [];
        foreach ($mapping as $line) {
            if (!is_string($line)) {
                continue;
            }
            $parts = array_map('trim', explode('=', $line, 2));
            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                continue;
            }
            $value = $this->resolveValue($parts[1], $media, $item);
            if ($value !== null && $value !== '') {
                $result[$parts[0]] = $value;
            }
        }
        return $result;
    }

    protected function resolveValue(string $source, $media, $item): ?string
    {
        if (preg_match('/^"(.*)"$/', $source, $m)) {
            return $m[1];
        }
        if (strpos($source, 'o:item/') === 0) {
            $v = $item->value(substr($source, 7));
            return $v ? (string) $v : null;
        }
        $v = $media->value($source);
        if ($v) {
            return (string) $v;
        }
        $v = $item->value($source);
        return $v ? (string) $v : null;
    }

    protected function updateMedia(
        MediaRepresentation $media,
        $item,
        string $iiifInfoUrl,
        string $remoteId,
        string $doi,
        string $dataUri,
        string $propertyIdentifier,
        string $propertyUrl,
        string $mediaMode,
        string $ingester = 'iiif',
        string $accessUrl = '',
        bool $storeOriginal = true
    ): void {
        // Mode "add": create a brand new media attached to the same item,
        // keeping the original upload media intact.
        if ($mediaMode === 'add') {
            $this->addRemoteMedia(
                $media, $item, $iiifInfoUrl, $remoteId, $doi, $dataUri,
                $propertyIdentifier, $propertyUrl, $ingester, $accessUrl,
                $storeOriginal
            );
            return;
        }

        // Step 1: Update properties on the existing media via the API
        // (safe partial update).
        $this->appendMediaProperties(
            $media->id(), $iiifInfoUrl, $remoteId, $doi, $dataUri,
            $propertyIdentifier, $propertyUrl
        );

        // Step 2: Convert media via direct entity manipulation.
        // MediaAdapter::hydrate() only sets ingester/renderer/source/ data on
        // CREATE, so an API update cannot change them — use the EntityManager
        // instead.
        if ($ingester === 'iiif') {
            if (!$iiifInfoUrl) {
                return;
            }
            $infoData = $this->fetchIiifInfo($iiifInfoUrl);
            if ($infoData === null) {
                $this->logger->warn(new PsrMessage(
                    'Media #{media_id}: cannot fetch IIIF info.json at {url}, skipping ingester conversion.', // @translate
                    ['media_id' => $media->id(), 'url' => $iiifInfoUrl]
                ));
                return;
            }
            $sourceUrl = $iiifInfoUrl;
            $renderer = 'iiif';
            $data = $infoData;
        } else {
            // Ingester "url": keep a direct file URL as source. Useful when the
            // repository has no IIIF Image API (Dataverse).
            if (!$accessUrl) {
                return;
            }
            $sourceUrl = $accessUrl;
            $renderer = 'file';
            $data = null;
        }

        try {
            $services = $this->getServiceLocator();
            $entityManager = $services->get('Omeka\EntityManager');
            /** @var \Omeka\Entity\Media $mediaEntity */
            $mediaEntity = $entityManager->find(
                \Omeka\Entity\Media::class, $media->id()
            );
            if (!$mediaEntity) {
                return;
            }
            $storageId = $mediaEntity->getStorageId();
            $extension = $mediaEntity->getExtension();
            $hasThumbnails = $mediaEntity->hasThumbnails();
            $mediaEntity->setIngester($ingester);
            $mediaEntity->setRenderer($renderer);
            $mediaEntity->setSource($sourceUrl);
            $mediaEntity->setData($data);
            if ($mediaMode === 'convert_delete') {
                $mediaEntity->setStorageId(null);
                $mediaEntity->setExtension(null);
                $mediaEntity->setHasThumbnails(false);
                $mediaEntity->setHasOriginal(false);
            } elseif ($mediaMode === 'convert_delete_original') {
                $mediaEntity->setExtension(null);
                $mediaEntity->setHasOriginal(false);
            }
            $entityManager->flush();
            $this->logger->info(new PsrMessage(
                'Media #{media_id}: converted to {ingester} ingester (source={url}).', // @translate
                [
                    'media_id' => $media->id(),
                    'ingester' => $ingester,
                    'url' => $sourceUrl,
                ]
            ));
            if ($mediaMode === 'convert_delete' && $storageId) {
                $this->deleteLocalFiles(
                    $media->id(), $storageId, $extension, $hasThumbnails
                );
            } elseif ($mediaMode === 'convert_delete_original' && $storageId) {
                $this->deleteLocalFiles(
                    $media->id(), $storageId, $extension, false
                );
            }
        } catch (\Throwable $e) {
            $this->logger->err(new PsrMessage(
                'Media #{media_id}: ingester conversion failed: {error}', // @translate
                ['media_id' => $media->id(), 'error' => $e->getMessage()]
            ));
        }
    }

    /**
     * Append the identifier/URL properties to an existing media.
     */
    protected function appendMediaProperties(
        int $mediaId,
        string $iiifInfoUrl,
        string $remoteId,
        string $doi,
        string $dataUri,
        string $propertyIdentifier,
        string $propertyUrl
    ): void {
        $data = [];
        // Prefer the short identifier form (10.34847/nkl.xxx)
        // for the identifier property, and the full URI form
        // (https://doi.org/...) for the URL property.
        $identifierValue = $remoteId ?: $doi;
        if ($propertyIdentifier && $identifierValue) {
            $propId = $this->getPropertyId($propertyIdentifier);
            if ($propId) {
                $data[$propertyIdentifier][] = [
                    'type' => 'literal',
                    'property_id' => $propId,
                    '@value' => $identifierValue,
                ];
            }
        }
        $urlValue = $dataUri ?: $iiifInfoUrl;
        if ($propertyUrl && $urlValue) {
            $propId = $this->getPropertyId($propertyUrl);
            if ($propId) {
                $data[$propertyUrl][] = [
                    'type' => 'uri',
                    'property_id' => $propId,
                    '@id' => $urlValue,
                    'o:label' => $dataUri ? 'Nakala' : 'IIIF',
                ];
            }
        }
        if (!$data) {
            return;
        }
        try {
            $this->api->update('media', $mediaId, $data, [],
                ['isPartial' => true, 'collectionAction' => 'append']);
        } catch (\Throwable $e) {
            $this->logger->err(
                'Media #{media_id}: property update failed: {error}', // @translate
                ['media_id' => $mediaId, 'error' => $e->getMessage()]
            );
        }
    }

    /**
     * Create a new remote-backed media (iiif or url ingester) attached to the
     * given item.
     */
    protected function addRemoteMedia(
        MediaRepresentation $media,
        $item,
        string $iiifInfoUrl,
        string $remoteId,
        string $doi,
        string $dataUri,
        string $propertyIdentifier,
        string $propertyUrl,
        string $ingester = 'iiif',
        string $accessUrl = '',
        bool $storeOriginal = true
    ): void {
        $sourceUrl = $ingester === 'iiif' ? $iiifInfoUrl : $accessUrl;
        if (!$sourceUrl) {
            return;
        }
        $data = [
            'o:ingester' => $ingester,
            'o:source' => $sourceUrl,
            'ingest_url' => $sourceUrl,
            'o:item' => ['o:id' => $item->id()],
        ];
        if ($ingester === 'url') {
            $data['store_original'] = $storeOriginal;
        }
        $identifierValue = $remoteId ?: $doi;
        if ($propertyIdentifier && $identifierValue) {
            $propId = $this->getPropertyId($propertyIdentifier);
            if ($propId) {
                $data[$propertyIdentifier][] = [
                    'type' => 'literal',
                    'property_id' => $propId,
                    '@value' => $identifierValue,
                ];
            }
        }
        $urlValue = $dataUri ?: $sourceUrl;
        if ($propertyUrl && $urlValue) {
            $propId = $this->getPropertyId($propertyUrl);
            if ($propId) {
                $data[$propertyUrl][] = [
                    'type' => 'uri',
                    'property_id' => $propId,
                    '@id' => $urlValue,
                    'o:label' => $dataUri ? 'Repository' : ($ingester === 'iiif' ? 'IIIF' : 'File'),
                ];
            }
        }
        try {
            $response = $this->api->create('media', $data);
            $newMedia = $response->getContent();
            $this->setMediaPositionAtEnd($newMedia->id(), $item->id());
            $this->logger->info(
                'Media #{media_id}: new {ingester} media #{new_id} added to item #{item_id}.', // @translate
                [
                    'media_id' => $media->id(),
                    'ingester' => $ingester,
                    'new_id' => $newMedia->id(),
                    'item_id' => $item->id(),
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->err(
                'Media #{media_id}: add remote media failed: {error}', // @translate
                ['media_id' => $media->id(), 'error' => $e->getMessage()]
            );
        }
    }

    /**
     * Position the new media after the last existing media of the item so the
     * media collection ordering stays predictable (api->create on Media leaves
     * position null when not provided).
     */
    protected function setMediaPositionAtEnd(int $newMediaId, int $itemId): void
    {
        try {
            $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
            $maxPosition = (int) $entityManager->createQuery(
                'SELECT MAX(m.position) FROM Omeka\Entity\Media m'
                . ' WHERE m.item = :item AND m.id <> :id'
            )
                ->setParameters(['item' => $itemId, 'id' => $newMediaId])
                ->getSingleScalarResult();
            $newMedia = $entityManager->find(\Omeka\Entity\Media::class, $newMediaId);
            if ($newMedia) {
                $newMedia->setPosition($maxPosition + 1);
                $entityManager->flush();
            }
        } catch (\Throwable $e) {
            $this->logger->warn(
                'Media #{media_id}: cannot set position: {error}', // @translate
                ['media_id' => $newMediaId, 'error' => $e->getMessage()]
            );
        }
    }

    /**
     * Delete the original file and its thumbnails from local storage.
     */
    protected function deleteLocalFiles(
        int $mediaId,
        string $storageId,
        ?string $extension,
        bool $hasThumbnails
    ): void {
        $services = $this->getServiceLocator();
        /** @var \Omeka\File\Store\StoreInterface $store */
        $store = $services->get('Omeka\File\Store');
        $deleted = [];
        $errors = [];
        $originalPath = 'original/' . $storageId
            . ($extension ? '.' . $extension : '');
        try {
            $store->delete($originalPath);
            $deleted[] = 'original';
        } catch (\Throwable $e) {
            $errors[] = 'original: ' . $e->getMessage();
        }
        if ($hasThumbnails) {
            foreach (['large', 'medium', 'square'] as $type) {
                $path = $type . '/' . $storageId . '.jpg';
                try {
                    $store->delete($path);
                    $deleted[] = $type;
                } catch (\Throwable $e) {
                    $errors[] = $type . ': ' . $e->getMessage();
                }
            }
        }
        if ($deleted) {
            $this->logger->info(new PsrMessage(
                'Media #{media_id}: local files deleted ({types}).', // @translate
                ['media_id' => $mediaId, 'types' => implode(', ', $deleted)]
            ));
        }
        if ($errors) {
            $this->logger->warn(new PsrMessage(
                'Media #{media_id}: some local files could not be deleted: {errors}.', // @translate
                ['media_id' => $mediaId, 'errors' => implode(' | ', $errors)]
            ));
        }
    }

    /**
     * Fetch a IIIF info.json resource.
     */
    protected function fetchIiifInfo(string $url): ?array
    {
        try {
            $services = $this->getServiceLocator();
            $client = $services->get('Omeka\HttpClient');
            $client->reset();
            $client->setUri($url);
            $client->setMethod('GET');
            $client->setHeaders(['Accept' => 'application/json']);
            $response = $client->send();
            if (!$response->isSuccess()) {
                return null;
            }
            $data = json_decode($response->getBody(), true);
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function getPropertyId(string $term): ?int
    {
        static $cache = [];
        if (isset($cache[$term])) {
            return $cache[$term];
        }
        try {
            $result = $this->api->search('properties', [
                'term' => $term, 'limit' => 1,
            ])->getContent();
            $cache[$term] = $result ? $result[0]->id() : null;
        } catch (\Throwable $e) {
            $cache[$term] = null;
        }
        return $cache[$term];
    }

    /**
     * Convert a property term to a full URI.
     */
    public function propertyTermToUri(string $term): string
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
     * Build IIIF info.json URL (kept for tests).
     */
    public function buildIiifUrl(string $apiUrl, string $id, string $sha1): string
    {
        return rtrim($apiUrl, '/') . '/iiif/' . $id . '/' . $sha1 . '/full/max/0/default.jpg';
    }

    /**
     * Replace the api_key in the persisted job arguments with a placeholder so
     * the secret is not retained in the job table or exposed via logs and admin
     * pages.
     */
    protected function redactApiKey(): void
    {
        if (!$this->job) {
            return;
        }
        $args = $this->job->getArgs() ?? [];
        if (!isset($args['api_key']) || $args['api_key'] === ''
            || $args['api_key'] === '***'
        ) {
            return;
        }
        $args['api_key'] = '***';
        $this->job->setArgs($args);
        $this->getServiceLocator()
            ->get('Omeka\EntityManager')
            ->flush();
    }
}
