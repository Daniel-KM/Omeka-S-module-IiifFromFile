<?php declare(strict_types=1);

namespace IiifFromFile\Repository;

use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;

/**
 * Interface for remote IIIF-compatible repository connectors.
 *
 * Each implementation handles a specific repository API (Nakala,
 * Dataverse, etc.).
 */
interface RepositoryConnectorInterface
{
    /**
     * Get the human-readable label for this connector.
     */
    public function getLabel(): string;

    /**
     * Set connection parameters (api_url, api_key, api_user, etc.).
     */
    public function setParams(array $params): self;

    /**
     * Test the connection and credentials.
     *
     * @return array ['ok' => bool, 'message' => string]
     */
    public function testConnection(): array;

    /**
     * Upload a file to the repository.
     *
     * @param string $filePath Local path to the file.
     * @param MediaRepresentation $media The source media.
     * @return array|null Result with at least 'file_id' key, or null
     * on failure.
     */
    public function uploadFile(
        string $filePath,
        MediaRepresentation $media
    ): ?array;

    /**
     * Create a data object on the repository with metadata and the
     * uploaded file.
     *
     * @param array $uploadResult Result from uploadFile().
     * @param array $metadata Mapped metadata (key-value pairs).
     * @param array $otherParams Collection/deposit parameters.
     * @param MediaRepresentation $media The source media.
     * @param ItemRepresentation $item The parent item.
     * @return array|null Result with 'identifier' and optionally 'doi',
     * 'iiif_url', or null on failure.
     */
    public function createData(
        array $uploadResult,
        array $metadata,
        array $otherParams,
        MediaRepresentation $media,
        ItemRepresentation $item
    ): ?array;

    /**
     * Update metadata of an existing data object.
     *
     * @param string $identifier Remote identifier.
     * @param array $metadata Updated metadata (key-value pairs).
     * @return bool Success.
     */
    /**
     * @param string $mode 'replace' (discard unmapped remote metadata),
     * 'overwrite' (keep unmapped remote metadata) or 'complete' (only add
     * metadata missing on remote).
     */
    public function updateData(
        string $identifier,
        array $metadata,
        ?MediaRepresentation $media = null,
        ?ItemRepresentation $item = null,
        string $mode = 'replace'
    ): bool;

    /**
     * Update the status of an existing data object.
     *
     * @param string $identifier Remote identifier.
     * @param string $status New status (e.g. 'published', 'pending').
     * @return bool Success.
     */
    public function updateStatus(
        string $identifier,
        string $status
    ): bool;

    /**
     * Fetch current metadata from the remote repository.
     *
     * @param string $identifier Remote identifier.
     * @return array|null Metadata as key-value pairs, or null on
     * failure.
     */
    public function fetchData(string $identifier): ?array;

    /**
     * Build the IIIF image info.json URL from the creation result.
     */
    public function buildIiifInfoUrl(array $dataResult): string;

    /**
     * Preferred Omeka ingester for media converted on this repository. Returns
     * "iiif" when the repository serves a IIIF Image API, else "url" to keep a
     * direct file URL as source.
     */
    public function getPreferredIngester(): string;

    /**
     * Direct access URL to the deposited file (used when the preferred ingester
     * is "url"). Empty when not applicable.
     */
    public function buildAccessUrl(array $dataResult): string;

    /**
     * Get the last error message (for logging).
     */
    public function getLastError(): string;

    /**
     * Check whether the supplied string is a syntactically valid remote
     * identifier for this connector. Used by sync jobs to skip media whose
     * stored identifier is missing or malformed before issuing useless API
     * calls.
     */
    public function isValidIdentifier(string $identifier): bool;
}
