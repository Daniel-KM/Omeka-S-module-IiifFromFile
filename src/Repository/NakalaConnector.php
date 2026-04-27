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
 * @link https://documentation.huma-num.fr/nakala-guide-de-description/
 * @link https://gitlab.huma-num.fr/huma-num-public/notebook-api-nakala/-/blob/master/tp-depot-par-lot.ipynb
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

    /**
     * @var string
     */
    protected $defaultLang = 'fr';

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
        $lang = trim((string) ($params['default_lang'] ?? ''));
        if ($lang !== '') {
            $this->defaultLang = $lang;
        }
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
        $url = $this->apiUrl . '/datas/uploads';
        $this->lastError = '';

        $this->logger->info(
            'Nakala: uploading file for media #{media_id} ({filename}, {size} bytes).', // @translate
            [
                'media_id' => $media->id(),
                'filename' => basename($filePath),
                'size' => filesize($filePath),
            ]
        );

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
            $this->logger->err(
                'Nakala: media #{media_id}: {error}', // @translate
                ['media_id' => $media->id(), 'error' => $this->lastError]
            );
            return null;
        }

        $result = json_decode($body, true);
        if (empty($result['sha1'])) {
            $this->lastError = 'Upload response missing sha1: '
                . substr($body, 0, 300);
            $this->logger->err(
                'Nakala: media #{media_id}: {error}', // @translate
                ['media_id' => $media->id(), 'error' => $this->lastError]
            );
            return null;
        }

        $this->logger->info(
            'Nakala: media #{media_id}: file uploaded, sha1={sha1}.', // @translate
            ['media_id' => $media->id(), 'sha1' => $result['sha1']]
        );

        return [
            'file_id' => $result['sha1'],
            'sha1' => $result['sha1'],
        ];
    }

    public function createData(
        array $uploadResult,
        array $metadata,
        array $otherParams,
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
                    'name' => $media->filename() ?: ($media->source() ?: 'file.jpg'),
                ],
            ],
            'metas' => $metas,
            'status' => $otherParams['status'] ?? 'published',
        ];

        $collectionId = $otherParams['collection_id'] ?? '';
        if ($collectionId) {
            $body['collectionsIds'] = [$collectionId];
        }

        $missingMetas = $this->checkMandatoryMetas($metas);
        if ($missingMetas) {
            $this->logger->warn(
                'Nakala: media #{media_id}: missing mandatory metadata: {metas}.', // @translate
                [
                    'media_id' => $media->id(),
                    'metas' => implode(', ', $missingMetas),
                ]
            );
        }

        $this->logger->info(
            'Nakala: creating data for media #{media_id} with {count} metas, collection={collection}, status={status}.', // @translate
            [
                'media_id' => $media->id(),
                'count' => count($metas),
                'collection' => $collectionId ?: '(none)',
                'status' => $body['status'],
            ]
        );

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
            $this->logger->err(
                'Nakala: media #{media_id}: {error}', // @translate
                ['media_id' => $media->id(), 'error' => $this->lastError]
            );
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
            $this->logger->err(
                'Nakala: media #{media_id}: {error}. Response: {response}. Request body: {request}', // @translate
                [
                    'media_id' => $media->id(),
                    'error' => $this->lastError,
                    'response' => substr($responseBody, 0, 2000),
                    'request' => substr(json_encode($body,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    ), 0, 2000),
                ]
            );
            return null;
        }

        $result = json_decode($responseBody, true);
        $identifier = $result['payload']['id']
            ?? $result['id'] ?? '';

        if (!$identifier) {
            $this->lastError = 'Response has no identifier: '
                . substr($responseBody, 0, 300);
            $this->logger->err(
                'Nakala: media #{media_id}: {error}', // @translate
                ['media_id' => $media->id(), 'error' => $this->lastError]
            );
            return null;
        }

        // Fetch the created data to retrieve the canonical DOI
        // URI assigned by Nakala (only available after creation).
        $fetched = $this->fetchData($identifier);
        $doi = $identifier;
        $dataUri = '';
        if (is_array($fetched)) {
            $dataUri = (string) ($fetched['uri'] ?? '');
            if ($dataUri !== '') {
                $doi = $dataUri;
            }
        }

        $this->logger->info(
            'Nakala: media #{media_id}: data created, identifier={identifier}, doi={doi}.', // @translate
            [
                'media_id' => $media->id(),
                'identifier' => $identifier,
                'doi' => $doi,
            ]
        );

        return [
            'identifier' => $identifier,
            'doi' => $doi,
            'data_uri' => $dataUri,
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

    public function getPreferredIngester(): string
    {
        return 'iiif';
    }

    public function buildAccessUrl(array $dataResult): string
    {
        $identifier = $dataResult['identifier'] ?? '';
        $sha1 = $dataResult['sha1'] ?? '';
        if (!$identifier || !$sha1) {
            return '';
        }
        return $this->apiUrl . '/data/' . $identifier . '/' . $sha1;
    }

    public function updateData(
        string $identifier,
        array $metadata,
        ?MediaRepresentation $media = null,
        ?ItemRepresentation $item = null,
        string $mode = 'replace'
    ): bool {
        $this->lastError = '';
        $url = $this->apiUrl . '/datas/' . $identifier;

        if (!in_array($mode, ['replace', 'overwrite', 'complete'], true)) {
            $mode = 'replace';
        }

        // Build new metas without injecting Untitled/year/CC-BY-4.0 fallbacks:
        // for "replace", missing mandatory must come from the remote rather
        // than from default placeholders, so the operator does not silently
        // overwrite a legitimate remote value with a default.
        $newMetas = $this->buildNakalaMetasFlat($metadata);

        if ($mode === 'replace') {
            $metas = $this->fillMandatoryFromRemote($identifier, $newMetas);
            if ($metas === null) {
                return false;
            }
        } else {
            $remote = $this->fetchData($identifier);
            if ($remote === null) {
                $this->lastError = 'Cannot fetch remote record ' . $identifier
                    . ': ' . $this->lastError;
                $this->logger->err(
                    'Nakala: aborting sync (mode={mode}) for {id}: {error}', // @translate
                    [
                        'mode' => $mode,
                        'id' => $identifier,
                        'error' => $this->lastError,
                    ]
                );
                return false;
            }
            $existingMetas = is_array($remote['metas'] ?? null)
                ? $remote['metas']
                : [];
            $metas = $this->mergeMetas($existingMetas, $newMetas, $mode);
        }

        $missingMetas = $this->checkMandatoryMetas($metas);
        if ($missingMetas) {
            $this->lastError = 'Missing mandatory metadata: '
                . implode(', ', $missingMetas);
            $this->logger->err(
                'Nakala: update {id}: {error}', // @translate
                ['id' => $identifier, 'error' => $this->lastError]
            );
            return false;
        }

        $this->httpClient->reset();
        $this->httpClient->setUri($url);
        $this->httpClient->setMethod(Request::METHOD_PUT);
        $this->httpClient->setHeaders([
            'X-API-KEY' => $this->apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ]);
        $this->httpClient->setRawBody(json_encode(
            ['metas' => $metas],
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
        $this->lastError = '';
        $url = $this->apiUrl . '/datas/' . $identifier . '/status';

        $this->httpClient->reset();
        $this->httpClient->setUri($url);
        $this->httpClient->setMethod(Request::METHOD_PUT);
        $this->httpClient->setHeaders([
            'X-API-KEY' => $this->apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ]);
        $this->httpClient->setRawBody(json_encode(
            ['status' => $status],
            JSON_UNESCAPED_SLASHES
        ));

        try {
            $response = $this->httpClient->send();
        } catch (\Throwable $e) {
            $this->lastError = 'Status update failed: ' . $e->getMessage();
            $this->logger->err($this->lastError);
            return false;
        }

        if (!$response->isSuccess()) {
            $this->lastError = sprintf(
                'Status update rejected (HTTP %d): %s',
                $response->getStatusCode(),
                $this->extractErrorMessage($response->getBody())
            );
            $this->logger->err($this->lastError);
            return false;
        }

        return true;
    }

    public function fetchData(string $identifier): ?array
    {
        $this->lastError = '';
        $url = $this->apiUrl . '/datas/' . $identifier;

        $this->httpClient->reset();
        $this->httpClient->setUri($url);
        $this->httpClient->setMethod(Request::METHOD_GET);
        $this->httpClient->setHeaders([
            'X-API-KEY' => $this->apiKey,
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

        return json_decode($response->getBody(), true);
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function isValidIdentifier(string $identifier): bool
    {
        // Accept any Nakala persistent identifier shape: bare DOI
        // (10.NNNN/...nkl....), DOI prefixed with "doi:", or full
        // resolver URL (https://nakala.fr/..., https://doi.org/...).
        // Strip schemes/prefixes/host before checking the DOI body.
        $id = trim($identifier);
        if ($id === '') {
            return false;
        }
        $id = preg_replace(
            '~^(https?://(?:doi\.org|(?:apitest\.|test\.)?nakala\.fr)/|doi:|hdl:)~i',
            '',
            $id
        );
        return (bool) preg_match(
            '~^10\.\d{4,9}/[A-Za-z0-9._-]*nkl\.[A-Za-z0-9._-]+$~',
            $id
        );
    }

    /**
     * Extract value/lang from either the new array shape
     * ['value' => ..., 'lang' => ...] or a legacy scalar string. Falls back to
     * the connector's default language when no per-value language is set.
     *
     * @return array{0:string,1:?string}
     */
    protected function extractValueLang($v): array
    {
        if (is_array($v)) {
            $val = (string) ($v['value'] ?? '');
            $type = (string) ($v['type'] ?? 'literal');
            if ($type === 'uri') {
                return [$val, null, 'uri'];
            }
            $lang = $v['lang'] ?? null;
            return [$val, $lang ?: $this->defaultLang, 'literal'];
        }
        return [(string) $v, $this->defaultLang, 'literal'];
    }

    /**
     * Build Nakala metas from a flat per-property metadata array. Each value
     * may be a string or ['value' => ..., 'lang' => ...].
     */
    protected function buildNakalaMetasFlat(array $metadata): array
    {
        $metas = [];
        $creatorUri = 'http://nakala.fr/terms#creator';
        $typeUri = 'http://nakala.fr/terms#type';
        $createdUri = 'http://nakala.fr/terms#created';
        $licenseUri = 'http://nakala.fr/terms#license';
        foreach ($metadata as $remoteProp => $raw) {
            foreach ($this->normalizeValueList($raw) as [$value, $lang, $type]) {
                if ($value === '') {
                    continue;
                }
                $uri = $this->termToUri($remoteProp);
                if ($uri === $creatorUri) {
                    $metas[] = [
                        'propertyUri' => $creatorUri,
                        'value' => $this->buildCreatorValue($value),
                    ];
                    continue;
                }
                if ($uri === $typeUri || $type === 'uri') {
                    $metas[] = [
                        'propertyUri' => $uri,
                        'value' => $value,
                        'typeUri' => 'http://www.w3.org/2001/XMLSchema#anyURI',
                    ];
                    continue;
                }
                if ($uri === $createdUri || $uri === $licenseUri) {
                    $metas[] = [
                        'propertyUri' => $uri,
                        'value' => $value,
                        'typeUri' => 'http://www.w3.org/2001/XMLSchema#string',
                    ];
                    continue;
                }
                $metas[] = [
                    'propertyUri' => $uri,
                    'value' => $value,
                    'lang' => $lang ?: $this->defaultLang,
                    'typeUri' => 'http://www.w3.org/2001/XMLSchema#string',
                ];
            }
        }
        return $metas;
    }

    /**
     * Normalize a metadata cell to a list of [value, lang] pairs. Accepts: - a
     * list of value-objects: [['value' => ..., 'lang' => ...], ...] - a single
     * value-object: ['value' => ..., 'lang' => ...] - a legacy scalar string.
     */
    protected function normalizeValueList($raw): array
    {
        if ($raw === '' || $raw === null) {
            return [];
        }
        if (is_array($raw)) {
            // Single value-object.
            if (array_key_exists('value', $raw)) {
                return [$this->extractValueLang($raw)];
            }
            // List of value-objects.
            $list = [];
            foreach ($raw as $v) {
                $list[] = $this->extractValueLang($v);
            }
            return $list;
        }
        return [$this->extractValueLang($raw)];
    }

    /**
     * Build metas without media/item context (no fallbacks for mandatory
     * fields). Used when caller cannot provide context.
     */
    protected function buildNakalaMetasMinimal(array $metadata): array
    {
        return $this->buildNakalaMetasFlat($metadata);
    }

    protected function buildNakalaMetas(
        array $metadata,
        MediaRepresentation $media,
        ItemRepresentation $item
    ): array {
        $metas = $this->buildNakalaMetasFlat($metadata);

        // Ensure Nakala mandatory fields (nakala.fr/terms#).
        $this->ensureMeta($metas, 'http://nakala.fr/terms#title',
            (string) ($item->displayTitle() ?: $media->displayTitle()
                ?: $media->source() ?: 'Untitled'));
        $this->ensureMetaCreator($metas, $item);
        $this->ensureMeta($metas, 'http://nakala.fr/terms#type',
            'http://purl.org/coar/resource_type/c_c513',
            'http://www.w3.org/2001/XMLSchema#anyURI');
        $this->ensureMeta($metas, 'http://nakala.fr/terms#created',
            date('Y'));
        $this->ensureMeta($metas, 'http://nakala.fr/terms#license',
            'CC-BY-4.0');

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
        // Nakala forbids the lang attribute on created/license fields.
        $noLang = [
            'http://nakala.fr/terms#created',
            'http://nakala.fr/terms#license',
        ];
        if ($typeUri === 'http://www.w3.org/2001/XMLSchema#string'
            && !in_array($uri, $noLang, true)
        ) {
            $meta['lang'] = $this->defaultLang;
        }
        $metas[] = $meta;
    }

    /**
     * Ensure the Nakala creator meta is present. The creator
     * must be an object with givenname/surname/orcid, not a
     * string.
     */
    protected function ensureMetaCreator(
        array &$metas,
        ItemRepresentation $item
    ): void {
        $uri = 'http://nakala.fr/terms#creator';
        foreach ($metas as $meta) {
            if (($meta['propertyUri'] ?? '') === $uri) {
                return;
            }
        }
        $creator = $item->value('dcterms:creator');
        $creatorValue = $creator ? (string) $creator : '';
        $metas[] = [
            'propertyUri' => $uri,
            'value' => $this->buildCreatorValue($creatorValue),
        ];
    }

    /**
     * Parse "Surname, Givenname" or "Givenname Surname" into
     * the Nakala creator object format.
     */
    protected function buildCreatorValue(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [
                'givenname' => '',
                'surname' => null,
                'orcid' => null,
            ];
        }
        if (strpos($value, ',') !== false) {
            [$surname, $givenname] = array_map(
                'trim',
                explode(',', $value, 2)
            );
        } else {
            $parts = preg_split('/\s+/', $value, 2);
            $givenname = $parts[0] ?? '';
            $surname = $parts[1] ?? '';
        }
        return [
            'givenname' => $givenname,
            'surname' => $surname,
            'orcid' => null,
        ];
    }

    /**
     * Convert a property term to a full URI.
     */
    protected function termToUri(string $term): string
    {
        static $prefixes = [
            'nakala' => 'http://nakala.fr/terms#',
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
     * For sync mode "replace", complete the new metas with mandatory Nakala
     * metas borrowed from the remote record rather than from hard-coded
     * placeholders.
     */
    protected function fillMandatoryFromRemote(
        string $identifier,
        array $newMetas
    ): ?array {
        $missing = $this->checkMandatoryMetas($newMetas);
        if (!$missing) {
            return $newMetas;
        }
        $remote = $this->fetchData($identifier);
        if ($remote === null) {
            $this->lastError = 'Cannot fetch remote record ' . $identifier
                . ' to recover mandatory metas: ' . $this->lastError;
            $this->logger->err(
                'Nakala: aborting replace for {id}: {error}', // @translate
                ['id' => $identifier, 'error' => $this->lastError]
            );
            return null;
        }
        $existing = is_array($remote['metas'] ?? null) ? $remote['metas'] : [];
        foreach ($existing as $meta) {
            if (in_array($meta['propertyUri'] ?? '', $missing, true)) {
                $newMetas[] = $meta;
            }
        }
        return $newMetas;
    }

    /**
     * Merge new metas with existing remote metas. Mode "overwrite": for each
     * propertyUri present in $new, drop existing entries with the same
     * propertyUri and replace them with $new entries; other existing entries
     * are kept. Mode "complete": keep all existing entries; only add entries
     * whose propertyUri is absent from existing.
     */
    protected function mergeMetas(
        array $existing,
        array $new,
        string $mode
    ): array {
        $newUris = array_unique(array_filter(
            array_column($new, 'propertyUri')
        ));
        if ($mode === 'overwrite') {
            $kept = array_values(array_filter(
                $existing,
                fn ($m) => !in_array($m['propertyUri'] ?? '', $newUris, true)
            ));
            return array_merge($kept, $new);
        }
        // complete
        $existingUris = array_unique(array_filter(
            array_column($existing, 'propertyUri')
        ));
        $added = array_values(array_filter(
            $new,
            fn ($m) => !in_array($m['propertyUri'] ?? '', $existingUris, true)
        ));
        return array_merge($existing, $added);
    }

    /**
     * Check that all Nakala mandatory metadata are present and return the list
     * of missing ones.
     */
    protected function checkMandatoryMetas(array $metas): array
    {
        $mandatory = [
            'http://nakala.fr/terms#title',
            'http://nakala.fr/terms#creator',
            'http://nakala.fr/terms#type',
            'http://nakala.fr/terms#created',
            'http://nakala.fr/terms#license',
        ];
        $present = array_column($metas, 'propertyUri');
        return array_values(array_diff($mandatory, $present));
    }

    /**
     * Extract error message from API response body.
     */
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
        if (!empty($json['error'])) {
            $parts[] = is_string($json['error'])
                ? $json['error']
                : json_encode($json['error'], JSON_UNESCAPED_UNICODE);
        }
        if (!empty($json['detail'])) {
            $parts[] = is_string($json['detail'])
                ? $json['detail']
                : json_encode($json['detail'], JSON_UNESCAPED_UNICODE);
        }
        if (!empty($json['errors'])) {
            $parts[] = 'errors: ' . json_encode(
                $json['errors'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }
        if (!empty($json['payload'])) {
            $parts[] = 'payload: ' . json_encode(
                $json['payload'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
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
