<?php declare(strict_types=1);

namespace IiifFromFile\Repository;

use Laminas\Http\Client as HttpClient;
use Laminas\Http\Request;
use Laminas\Log\LoggerInterface;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;

/**
 * Connector for Dataverse repositories (Harvard demo, Recherche Data Gouv, Data
 * IndoRES, etc.).
 *
 * Strategy: one Dataverse dataset per media (parallel to Nakala "data"). The
 * collection_id parameter is the alias of the target parent dataverse.
 *
 * Dataverse does not natively expose a IIIF Image API. An external IIIF server
 * (Cantaloupe, etc.) may proxy the file API; its base URL can be configured via
 * the iiif_base_url parameter. When absent, the IIIF info URL is empty and the
 * calling job keeps the original media unchanged for ingester conversion (it
 * still records DOI and direct file URL).
 *
 * @link https://guides.dataverse.org/en/latest/api/native-api.html
 */
class DataverseConnector implements RepositoryConnectorInterface
{
    /**
     * Citation block fields Dataverse considers mandatory; cannot be deleted by
     * sync mode "replace".
     */
    protected const DATAVERSE_MANDATORY_FIELDS = [
        'title',
        'author',
        'datasetContact',
        'dsDescription',
        'subject',
    ];

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
    protected $iiifBaseUrl = '';

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
        return 'Dataverse';
    }

    public function setParams(array $params): RepositoryConnectorInterface
    {
        $this->apiUrl = rtrim($params['api_url'] ?? '', '/');
        $this->apiKey = $params['api_key'] ?? '';
        $this->iiifBaseUrl = rtrim($params['iiif_base_url'] ?? '', '/');
        return $this;
    }

    public function testConnection(): array
    {
        if (!$this->apiUrl || !$this->apiKey) {
            return ['ok' => false, 'message' => 'Missing API URL or API key.'];
        }
        $this->httpClient->reset();
        $this->httpClient->setUri($this->apiUrl . '/api/users/:me');
        $this->httpClient->setMethod(Request::METHOD_GET);
        $this->httpClient->setHeaders([
            'X-Dataverse-key' => $this->apiKey,
            'Accept' => 'application/json',
        ]);
        try {
            $response = $this->httpClient->send();
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Connection failed: ' . $e->getMessage()];
        }
        if (!$response->isSuccess()) {
            return [
                'ok' => false,
                'message' => 'Authentication failed: HTTP '
                    . $response->getStatusCode() . ' '
                    . substr($response->getBody(), 0, 200),
            ];
        }
        $data = json_decode($response->getBody(), true);
        $name = $data['data']['displayName'] ?? $data['data']['identifier'] ?? 'unknown';
        return ['ok' => true, 'message' => 'Connected as ' . $name];
    }

    public function uploadFile(
        string $filePath,
        MediaRepresentation $media
    ): ?array {
        // Defer upload until createData() since Dataverse requires the dataset
        // persistentId before adding files. Just keep the local file path for
        // the next step.
        $this->lastError = '';
        if (!is_readable($filePath)) {
            $this->lastError = 'File not readable: ' . $filePath;
            $this->logger->err($this->lastError);
            return null;
        }
        return [
            'file_id' => '',
            'file_path' => $filePath,
        ];
    }

    public function createData(
        array $uploadResult,
        array $metadata,
        array $otherParams,
        MediaRepresentation $media,
        ItemRepresentation $item
    ): ?array {
        $this->lastError = '';
        $alias = (string) ($otherParams['collection_id'] ?? '');
        if (!$alias) {
            $this->lastError = 'Missing parent dataverse alias (collection_id).';
            $this->logger->err($this->lastError);
            return null;
        }
        $filePath = (string) ($uploadResult['file_path'] ?? '');
        if (!$filePath || !is_readable($filePath)) {
            $this->lastError = 'Source file unavailable.';
            $this->logger->err($this->lastError);
            return null;
        }

        // Step 1: create dataset.
        $datasetBody = [
            'datasetVersion' => [
                'license' => $this->buildLicense($metadata),
                'metadataBlocks' => [
                    'citation' => [
                        'displayName' => 'Citation Metadata',
                        'fields' => $this->buildCitationFields($metadata, $media, $item),
                    ],
                ],
            ],
        ];

        $url = $this->apiUrl . '/api/dataverses/' . rawurlencode($alias) . '/datasets';
        $this->httpClient->reset();
        $this->httpClient->setUri($url);
        $this->httpClient->setMethod(Request::METHOD_POST);
        $this->httpClient->setHeaders([
            'X-Dataverse-key' => $this->apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ]);
        $this->httpClient->setRawBody(json_encode(
            $datasetBody,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
        try {
            $response = $this->httpClient->send();
        } catch (\Throwable $e) {
            $this->lastError = 'Create dataset failed: ' . $e->getMessage();
            $this->logger->err($this->lastError);
            return null;
        }
        $code = $response->getStatusCode();
        $body = $response->getBody();
        if (!$response->isSuccess()) {
            $this->lastError = sprintf(
                'Create dataset rejected (HTTP %d): %s',
                $code,
                $this->extractErrorMessage($body)
            );
            $this->logger->err(
                'Dataverse: media #{media_id}: {error}', // @translate
                ['media_id' => $media->id(), 'error' => $this->lastError]
            );
            return null;
        }
        $result = json_decode($body, true);
        $persistentId = $result['data']['persistentId'] ?? '';
        $datasetId = $result['data']['id'] ?? null;
        if (!$persistentId) {
            $this->lastError = 'Dataset response missing persistentId: '
                . substr($body, 0, 300);
            $this->logger->err($this->lastError);
            return null;
        }

        // Step 2: add file.
        $fileResult = $this->addFile(
            $persistentId,
            $filePath,
            $media,
            (string) ($metadata['description'] ?? '')
        );
        if (!$fileResult) {
            return null;
        }

        // Step 3: optional publish.
        $status = $otherParams['status'] ?? '';
        if ($status === 'published') {
            $this->publishDataset($persistentId);
        }

        $this->logger->info(
            'Dataverse: media #{media_id}: dataset {pid} created with file id={file_id}.', // @translate
            [
                'media_id' => $media->id(),
                'pid' => $persistentId,
                'file_id' => $fileResult['file_id'],
            ]
        );

        $doiUrl = $this->persistentIdToUri($persistentId);
        return [
            'identifier' => $persistentId,
            'doi' => $doiUrl,
            'data_uri' => $doiUrl,
            'dataset_id' => $datasetId,
            'file_id' => $fileResult['file_id'],
            'file_persistent_id' => $fileResult['file_persistent_id'],
        ];
    }

    public function buildIiifInfoUrl(array $dataResult): string
    {
        if (!$this->iiifBaseUrl) {
            return '';
        }
        $fileId = (string) ($dataResult['file_id'] ?? '');
        if (!$fileId) {
            return '';
        }
        return $this->iiifBaseUrl . '/' . rawurlencode($fileId) . '/info.json';
    }

    public function getPreferredIngester(): string
    {
        return $this->iiifBaseUrl ? 'iiif' : 'url';
    }

    public function buildAccessUrl(array $dataResult): string
    {
        $fileId = (string) ($dataResult['file_id'] ?? '');
        if (!$fileId) {
            return '';
        }
        return $this->apiUrl . '/api/access/datafile/' . rawurlencode($fileId);
    }

    public function updateData(
        string $identifier,
        array $metadata,
        ?MediaRepresentation $media = null,
        ?ItemRepresentation $item = null,
        string $mode = 'replace'
    ): bool {
        $this->lastError = '';
        if (!in_array($mode, ['replace', 'overwrite', 'complete'], true)) {
            $mode = 'replace';
        }

        // Refuse to mutate a dataset that is currently in DRAFT: Dataverse
        // keeps a single working draft and editing it would silently overwrite
        // ongoing local edits performed via the web UI.
        $remote = $this->fetchData($identifier);
        if ($remote === null) {
            $this->lastError = 'Dataverse: cannot fetch dataset ' . $identifier
                . ': ' . $this->getLastError();
            $this->logger->err($this->lastError);
            return false;
        }
        $state = $remote['latestVersion']['versionState']
            ?? $remote['versionState']
            ?? null;
        if ($state === 'DRAFT') {
            $this->lastError = 'Dataverse: dataset ' . $identifier
                . ' is in DRAFT state; refusing to update metadata.';
            $this->logger->err($this->lastError);
            return false;
        }

        $fields = $this->buildCitationFields($metadata, $media, $item);

        if ($mode === 'complete') {
            $existing = $this->extractExistingTypeNames($remote);
            $fields = array_values(array_filter(
                $fields,
                fn ($f) => !in_array($f['typeName'] ?? '', $existing, true)
            ));
            if (!$fields) {
                $this->logger->info(
                    'Dataverse: nothing to add for {id} (mode=complete).', // @translate
                    ['id' => $identifier]
                );
                return true;
            }
        }

        if ($mode === 'replace') {
            $newTypeNames = array_filter(array_column($fields, 'typeName'));
            $remoteFields = $remote['latestVersion']['metadataBlocks']['citation']['fields']
                ?? [];
            $toDelete = array_values(array_filter(
                $remoteFields,
                fn ($f) => isset($f['typeName'])
                    && !in_array($f['typeName'], $newTypeNames, true)
                    && !in_array($f['typeName'], self::DATAVERSE_MANDATORY_FIELDS, true)
            ));
            foreach ($toDelete as $field) {
                if (!$this->deleteCitationField($identifier, $field)) {
                    $this->logger->warn(
                        'Dataverse: could not delete field {field} on {id}: {error}', // @translate
                        [
                            'field' => $field['typeName'],
                            'id' => $identifier,
                            'error' => $this->lastError,
                        ]
                    );
                }
            }
        }

        $url = $this->apiUrl . '/api/datasets/:persistentId/editMetadata'
            . '?persistentId=' . rawurlencode($identifier) . '&replace=true';
        $body = ['fields' => $fields];
        $this->httpClient->reset();
        $this->httpClient->setUri($url);
        $this->httpClient->setMethod(Request::METHOD_PUT);
        $this->httpClient->setHeaders([
            'X-Dataverse-key' => $this->apiKey,
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
            $this->lastError = 'Update failed: ' . $e->getMessage();
            $this->logger->err($this->lastError);
            return false;
        }
        if (!$response->isSuccess()) {
            $this->lastError = sprintf(
                'Update rejected (HTTP %d): %s',
                $response->getStatusCode(),
                $this->extractErrorMessage($response->getBody())
            );
            $this->logger->err($this->lastError);
            return false;
        }
        return true;
    }

    public function updateStatus(string $identifier, string $status): bool
    {
        if ($status === 'published') {
            return $this->publishDataset($identifier);
        }
        if ($status === 'draft' || $status === 'pending') {
            // Revert to draft only works on unpublished dataset; otherwise
            // Dataverse keeps the published version.
            $this->lastError = 'Dataverse does not support reverting to '
                . 'draft after publication.';
            return false;
        }
        $this->lastError = 'Unknown status: ' . $status;
        return false;
    }

    public function fetchData(string $identifier): ?array
    {
        $this->lastError = '';
        $url = $this->apiUrl . '/api/datasets/:persistentId/?persistentId='
            . rawurlencode($identifier);
        $this->httpClient->reset();
        $this->httpClient->setUri($url);
        $this->httpClient->setMethod(Request::METHOD_GET);
        $this->httpClient->setHeaders([
            'X-Dataverse-key' => $this->apiKey,
            'Accept' => 'application/json',
        ]);
        try {
            $response = $this->httpClient->send();
        } catch (\Throwable $e) {
            $this->lastError = 'Fetch failed: ' . $e->getMessage();
            return null;
        }
        if (!$response->isSuccess()) {
            $this->lastError = sprintf(
                'Fetch failed (HTTP %d): %s',
                $response->getStatusCode(),
                $this->extractErrorMessage($response->getBody())
            );
            return null;
        }
        $decoded = json_decode($response->getBody(), true);
        return is_array($decoded) ? ($decoded['data'] ?? $decoded) : null;
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function isValidIdentifier(string $identifier): bool
    {
        // Dataverse persistentId: DOI ("doi:10.x/...") or handle ("hdl:...").
        $id = trim($identifier);
        return (bool) preg_match('~^(doi|hdl):.+~', $id)
            || (bool) preg_match('~^10\.\d{4,9}/.+~', $id);
    }

    /**
     * Add a file to an existing dataset.
     */
    protected function addFile(
        string $persistentId,
        string $filePath,
        MediaRepresentation $media,
        string $description
    ): ?array {
        $url = $this->apiUrl . '/api/datasets/:persistentId/add'
            . '?persistentId=' . rawurlencode($persistentId);

        $jsonData = json_encode([
            'description' => $description ?: ($media->source() ?: ''),
            'restrict' => false,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->httpClient->reset();
        $this->httpClient->setUri($url);
        $this->httpClient->setMethod(Request::METHOD_POST);
        $this->httpClient->setHeaders([
            'X-Dataverse-key' => $this->apiKey,
            'Accept' => 'application/json',
        ]);
        $this->httpClient->setFileUpload(
            $filePath,
            'file',
            null,
            $media->mediaType()
        );
        $this->httpClient->setParameterPost(['jsonData' => $jsonData]);

        try {
            $response = $this->httpClient->send();
        } catch (\Throwable $e) {
            $this->lastError = 'File upload failed: ' . $e->getMessage();
            $this->logger->err($this->lastError);
            return null;
        }
        $body = $response->getBody();
        if (!$response->isSuccess()) {
            $this->lastError = sprintf(
                'File upload rejected (HTTP %d): %s',
                $response->getStatusCode(),
                $this->extractErrorMessage($body)
            );
            $this->logger->err(
                'Dataverse: media #{media_id}: {error}', // @translate
                ['media_id' => $media->id(), 'error' => $this->lastError]
            );
            return null;
        }
        $result = json_decode($body, true);
        $files = $result['data']['files'] ?? [];
        $first = $files[0]['dataFile'] ?? null;
        if (!$first || empty($first['id'])) {
            $this->lastError = 'Upload response missing dataFile id: '
                . substr($body, 0, 300);
            $this->logger->err($this->lastError);
            return null;
        }
        return [
            'file_id' => (string) $first['id'],
            'file_persistent_id' => (string) ($first['persistentId'] ?? ''),
        ];
    }

    /**
     * Delete a citation field (by typeName + value) on the latest draft of the
     * dataset. Used by sync mode "replace" to drop unmapped non-mandatory
     * fields before re-pushing the new ones.
     */
    protected function deleteCitationField(
        string $identifier,
        array $field
    ): bool {
        $this->lastError = '';
        $url = $this->apiUrl . '/api/datasets/:persistentId/deleteMetadata'
            . '?persistentId=' . rawurlencode($identifier);
        $body = ['fields' => [$field]];
        $this->httpClient->reset();
        $this->httpClient->setUri($url);
        $this->httpClient->setMethod(Request::METHOD_PUT);
        $this->httpClient->setHeaders([
            'X-Dataverse-key' => $this->apiKey,
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
            $this->lastError = 'Delete failed: ' . $e->getMessage();
            return false;
        }
        if (!$response->isSuccess()) {
            $this->lastError = sprintf(
                'Delete rejected (HTTP %d): %s',
                $response->getStatusCode(),
                $this->extractErrorMessage($response->getBody())
            );
            return false;
        }
        return true;
    }

    /**
     * Publish a dataset (major version).
     */
    protected function publishDataset(string $persistentId): bool
    {
        $url = $this->apiUrl . '/api/datasets/:persistentId/actions/:publish'
            . '?persistentId=' . rawurlencode($persistentId)
            . '&type=major';
        $this->httpClient->reset();
        $this->httpClient->setUri($url);
        $this->httpClient->setMethod(Request::METHOD_POST);
        $this->httpClient->setHeaders([
            'X-Dataverse-key' => $this->apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ]);
        $this->httpClient->setRawBody('{}');
        try {
            $response = $this->httpClient->send();
        } catch (\Throwable $e) {
            $this->lastError = 'Publish failed: ' . $e->getMessage();
            $this->logger->err($this->lastError);
            return false;
        }
        if (!$response->isSuccess()) {
            $this->lastError = sprintf(
                'Publish rejected (HTTP %d): %s',
                $response->getStatusCode(),
                $this->extractErrorMessage($response->getBody())
            );
            $this->logger->err($this->lastError);
            return false;
        }
        return true;
    }

    /**
     * Build Dataverse citation metadata block fields from flat key-value
     * metadata. Mandatory fields (title, author, datasetContact, dsDescription,
     * subject) are filled with sensible defaults.
     */
    /**
     * Fetch typeNames present in the citation block of the remote dataset
     * (latest version).
     */
    protected function fetchExistingTypeNames(string $identifier): array
    {
        return $this->extractExistingTypeNames(
            $this->fetchData($identifier) ?? []
        );
    }

    protected function extractExistingTypeNames(array $data): array
    {
        $blocks = $data['latestVersion']['metadataBlocks']
            ?? $data['metadataBlocks']
            ?? [];
        $citation = $blocks['citation']['fields'] ?? [];
        return array_values(array_unique(array_filter(
            array_column($citation, 'typeName')
        )));
    }

    /**
     * Extract the first scalar from any supported value shape: a list of
     * value-objects [['value' => ..., 'lang' => ...], ...], a single
     * value-object ['value' => ..., 'lang' => ...], or a legacy scalar.
     */
    protected function valueOf($v): string
    {
        if (is_array($v)) {
            if (array_key_exists('value', $v)) {
                return (string) $v['value'];
            }
            // List of value-objects: take the first.
            $first = reset($v);
            return $first ? $this->valueOf($first) : '';
        }
        return (string) $v;
    }

    /**
     * Extract every string value from a list-of-value-objects, single
     * value-object, or scalar shape.
     */
    protected function allValuesOf($v): array
    {
        if ($v === null || $v === '') {
            return [];
        }
        if (is_array($v)) {
            if (array_key_exists('value', $v)) {
                $s = (string) $v['value'];
                return $s === '' ? [] : [$s];
            }
            $list = [];
            foreach ($v as $item) {
                foreach ($this->allValuesOf($item) as $s) {
                    $list[] = $s;
                }
            }
            return $list;
        }
        return [(string) $v];
    }

    protected function buildCitationFields(
        array $metadata,
        ?MediaRepresentation $media,
        ?ItemRepresentation $item
    ): array {
        // Capture multi-valued cells (keywords) before flattening.
        $rawKeywords = $metadata['keywords']
            ?? $metadata['keyword']
            ?? null;
        $metadata = array_map(fn ($v) => $this->valueOf($v), $metadata);
        $title = (string) ($metadata['title']
            ?? ($item ? ($item->displayTitle() ?: '') : ''));
        if ($title === '' && $media) {
            $title = $media->displayTitle() ?: $media->source() ?: 'Untitled';
        }
        if ($title === '') {
            $title = 'Untitled';
        }

        $authorName = (string) ($metadata['author'] ?? $metadata['creator'] ?? '');
        if ($authorName === '' && $item) {
            $v = $item->value('dcterms:creator');
            $authorName = $v ? (string) $v : '';
        }
        if ($authorName === '') {
            $authorName = 'Unknown';
        }

        $contactName = (string) ($metadata['contact_name'] ?? $authorName);
        $contactEmail = (string) ($metadata['contact_email'] ?? '');
        if ($contactEmail === '') {
            $contactEmail = 'noreply@example.org';
        }

        $description = (string) ($metadata['description'] ?? '');
        if ($description === '' && $item) {
            $v = $item->value('dcterms:description');
            $description = $v ? (string) $v : '';
        }
        if ($description === '') {
            $description = $title;
        }

        $subject = (string) ($metadata['subject'] ?? 'Other');

        $fields = [
            $this->primitive('title', $title),
            $this->compoundList('author', [[
                'authorName' => $this->primitive('authorName', $authorName),
            ]]),
            $this->compoundList('datasetContact', [[
                'datasetContactName' => $this->primitive('datasetContactName', $contactName),
                'datasetContactEmail' => $this->primitive('datasetContactEmail', $contactEmail),
            ]]),
            $this->compoundList('dsDescription', [[
                'dsDescriptionValue' => $this->primitive('dsDescriptionValue', $description),
            ]]),
            $this->controlledVocabList('subject', [$subject]),
        ];

        // Optional: production date.
        $date = (string) ($metadata['production_date']
            ?? $metadata['date'] ?? '');
        if ($date !== '') {
            $fields[] = $this->primitive('productionDate', $date);
        }
        // Optional: keywords.
        if ($rawKeywords !== null && $rawKeywords !== '') {
            $allKeywords = $this->allValuesOf($rawKeywords);
            $list = [];
            foreach ($allKeywords as $kw) {
                foreach (explode(';', $kw) as $part) {
                    $list[] = $part;
                }
            }
            $children = [];
            foreach ($list as $kw) {
                $kw = trim((string) $kw);
                if ($kw === '') {
                    continue;
                }
                $children[] = [
                    'keywordValue' => $this->primitive('keywordValue', $kw),
                ];
            }
            if ($children) {
                $fields[] = $this->compoundList('keyword', $children);
            }
        }

        return $fields;
    }

    /**
     * Build Dataverse license object. Defaults to CC-BY 4.0.
     */
    protected function buildLicense(array $metadata): array
    {
        $name = (string) ($metadata['license'] ?? 'CC BY 4.0');
        return [
            'name' => $name,
            'uri' => $this->licenseNameToUri($name),
        ];
    }

    protected function licenseNameToUri(string $name): string
    {
        $map = [
            'CC0 1.0' => 'http://creativecommons.org/publicdomain/zero/1.0',
            'CC BY 4.0' => 'http://creativecommons.org/licenses/by/4.0',
            'CC-BY-4.0' => 'http://creativecommons.org/licenses/by/4.0',
            'CC BY-SA 4.0' => 'http://creativecommons.org/licenses/by-sa/4.0',
            'CC BY-NC 4.0' => 'http://creativecommons.org/licenses/by-nc/4.0',
        ];
        return $map[$name] ?? 'http://creativecommons.org/licenses/by/4.0';
    }

    protected function primitive(string $name, string $value): array
    {
        return [
            'typeName' => $name,
            'multiple' => false,
            'typeClass' => 'primitive',
            'value' => $value,
        ];
    }

    protected function compoundList(string $name, array $children): array
    {
        return [
            'typeName' => $name,
            'multiple' => true,
            'typeClass' => 'compound',
            'value' => $children,
        ];
    }

    protected function controlledVocabList(string $name, array $values): array
    {
        return [
            'typeName' => $name,
            'multiple' => true,
            'typeClass' => 'controlledVocabulary',
            'value' => $values,
        ];
    }

    protected function persistentIdToUri(string $persistentId): string
    {
        if (stripos($persistentId, 'doi:') === 0) {
            return 'https://doi.org/' . substr($persistentId, 4);
        }
        if (stripos($persistentId, 'hdl:') === 0) {
            return 'https://hdl.handle.net/' . substr($persistentId, 4);
        }
        return $persistentId;
    }

    protected function extractErrorMessage(string $body): string
    {
        $json = json_decode($body, true);
        if (!$json) {
            return substr($body, 0, 500);
        }
        $parts = [];
        if (!empty($json['message'])) {
            $parts[] = $json['message'];
        }
        if (!empty($json['status']) && $json['status'] === 'ERROR'
            && !empty($json['data'])
        ) {
            $parts[] = is_string($json['data'])
                ? $json['data']
                : json_encode($json['data'], JSON_UNESCAPED_UNICODE);
        }
        if (!$parts) {
            return substr(json_encode(
                $json,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ), 0, 500);
        }
        return implode(' | ', $parts);
    }
}
