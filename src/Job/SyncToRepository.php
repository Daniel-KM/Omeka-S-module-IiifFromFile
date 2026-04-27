<?php declare(strict_types=1);

namespace IiifFromFile\Job;

use IiifFromFile\Repository\RepositoryConnectorInterface;
use Omeka\Job\AbstractJob;

/**
 * Synchronize metadata and status from Omeka S to the remote
 * repository for media that have already been exported.
 */
class SyncToRepository extends AbstractJob
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
    protected $totalSynced = 0;
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
            'default_lang' => $args['default_lang'] ?? '',
        ]);

        $this->redactApiKey();

        $test = $connector->testConnection();
        if (!$test['ok']) {
            $this->logger->err(
                'Connection test failed: {message}', // @translate
                ['message' => $test['message']]
            );
            return;
        }

        $metadataMapping = $args['metadata_mapping'] ?? [];
        $propertyIdentifier = $args['property_identifier'] ?? '';
        $syncStatus = $args['sync_status'] ?? '';
        $syncMode = $args['sync_mode'] ?? 'overwrite';
        if (!in_array($syncMode, ['replace', 'overwrite', 'complete'], true)) {
            $syncMode = 'overwrite';
        }
        $query = $args['query'] ?? [];

        if (!$propertyIdentifier) {
            $this->logger->err(
                'No identifier property configured. Cannot find exported media.' // @translate
            );
            return;
        }

        $this->logger->info(
            'Starting sync to {label}.', // @translate
            ['label' => $connector->getLabel()]
        );

        // Use IiifServer plugin if installed, else fallback.
        $plugins = $services->get('ControllerPluginManager');
        $isIiifMedia = $plugins->has('isIiifMedia')
            ? $plugins->get('isIiifMedia')
            : fn ($media, $type = null) => $media->ingester() === 'iiif';

        // Fetch items.
        $query['limit'] = 0;
        $items = $this->api->search('items', $query)->getContent();

        foreach ($items as $item) {
            if ($this->shouldStop()) {
                $this->logger->warn('Job stopped by user.'); // @translate
                break;
            }

            foreach ($item->media() as $media) {
                // Only sync media that were exported (IIIF ingester with a
                // remote identifier stored).
                if (!$isIiifMedia($media, 'image')) {
                    continue;
                }

                $remoteId = (string) $media->value($propertyIdentifier);
                if (!$remoteId) {
                    ++$this->totalSkipped;
                    continue;
                }

                ++$this->totalProcessed;

                // Resolve current metadata from Omeka.
                $metadata = $this->resolveMetadata(
                    $metadataMapping, $media, $item
                );

                if (!$metadata) {
                    ++$this->totalSkipped;
                    continue;
                }

                // Update metadata on remote.
                $ok = $connector->updateData($remoteId, $metadata, $media, $item, $syncMode);
                if (!$ok) {
                    $this->logger->err(
                        'Media #{media_id}: sync failed for {id}: {error}', // @translate
                        [
                            'media_id' => $media->id(),
                            'id' => $remoteId,
                            'error' => $connector->getLastError(),
                        ]
                    );
                    ++$this->totalFailed;
                    continue;
                }

                // Optionally sync status.
                if ($syncStatus) {
                    $connector->updateStatus($remoteId, $syncStatus);
                }

                $this->logger->info(
                    'Media #{media_id}: synced to {id}.', // @translate
                    ['media_id' => $media->id(), 'id' => $remoteId]
                );
                ++$this->totalSynced;
            }
        }

        $this->logger->notice(
            'Sync complete: {synced} synced, {failed} errors, {skipped} skipped, {total} processed.', // @translate
            [
                'synced' => $this->totalSynced,
                'failed' => $this->totalFailed,
                'skipped' => $this->totalSkipped,
                'total' => $this->totalProcessed,
            ]
        );
    }

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
