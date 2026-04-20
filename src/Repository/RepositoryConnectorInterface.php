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
     * @param array $collectionParams Collection/deposit parameters.
     * @param MediaRepresentation $media The source media.
     * @param ItemRepresentation $item The parent item.
     * @return array|null Result with 'identifier' and optionally 'doi',
     * 'iiif_url', or null on failure.
     */
    public function createData(
        array $uploadResult,
        array $metadata,
        array $collectionParams,
        MediaRepresentation $media,
        ItemRepresentation $item
    ): ?array;

    /**
     * Build the IIIF image info.json URL from the creation result.
     */
    public function buildIiifInfoUrl(array $dataResult): string;

    /**
     * Get the last error message (for logging).
     */
    public function getLastError(): string;
}
