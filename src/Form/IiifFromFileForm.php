<?php declare(strict_types=1);

namespace IiifFromFile\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

class IiifFromFileForm extends Form
{
    public function init(): void
    {
        $this
            ->setAttribute('id', 'iiif-from-file-form')

            ->add([
                'name' => 'query',
                'type' => OmekaElement\Query::class,
                'options' => [
                    'label' => 'Items to export', // @translate
                    'query_resource_type' => 'items',
                    'query_partial_excludelist' => [
                        'common/advanced-search/sort',
                    ],
                ],
                'attributes' => [
                    'id' => 'query',
                ],
            ])

            ->add([
                'name' => 'endpoint',
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Destination', // @translate
                    'value_options' => $this->getEndpointOptions(),
                ],
                'attributes' => [
                    'id' => 'endpoint',
                    'required' => true,
                ],
            ])
            ->add([
                'name' => 'api_user',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Endpoint user', // @translate
                ],
                'attributes' => [
                    'id' => 'api_user',
                ],
            ])
            ->add([
                'name' => 'api_key',
                'type' => Element\Password::class,
                'options' => [
                    'label' => 'Endpoint authentication key', // @translate
                ],
                'attributes' => [
                    'id' => 'api_key',
                    'required' => true,
                ],
            ])

            ->add([
                'name' => 'collection',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Collection', // @translate
                    'info' => 'Parent container on the remote: a Nakala collection identifier (e.g. 10.34847/nkl.xxxx) for Nakala, or a parent dataverse alias for Dataverse.', // @translate
                ],
                'attributes' => [
                    'id' => 'collection',
                ],
            ])
            ->add([
                'name' => 'status',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Status', // @translate
                    'info' => 'Deposit status: "pending" or "published".', // @translate
                ],
                'attributes' => [
                    'id' => 'status',
                    'placeholder' => 'pending',
                ],
            ])
            ->add([
                'name' => 'default_lang',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Default language code for values when supported', // @translate
                ],
                'attributes' => [
                    'id' => 'default_lang',
                    'placeholder' => 'fr',
                ],
            ])
            ->add([
                'name' => 'file_name_mode',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'File name sent to repository', // @translate
                    'label_attributes' => [
                        'style' => 'display: inline;',
                    ],
                    'value_options' => [
                        'source' => 'Original source name', // @translate
                        'hash' => 'Omeka hashed storage name', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'file_name_mode',
                    'value' => 'source',
                ],
            ])
            ->add([
                'name' => 'other_params',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'Other deposit parameters', // @translate
                    'info' => 'Additional parameters for the remote deposit, one key=value per line.', // @translate
                    'as_key_value' => true,
                ],
                'attributes' => [
                    'id' => 'other_params',
                    'rows' => 4,
                ],
            ])

            ->add([
                'name' => 'metadata_mapping',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'Metadata mapping', // @translate
                    'info' => <<<'TXT'
                        Map local properties to remote metadata. One mapping per line.
                        Format: remote_property = local_source
                        The local source can be a property (dcterms:title), a prefixed item property (o:item/dcterms:title), or a fixed value in quotes. See readme for more info about mandatory metadata.
                        TXT, // @translate
                  'documentation' => 'https://gitlab.com/Daniel-KM/Omeka-S-module-IiifFromFile',
                ],
                'attributes' => [
                    'id' => 'metadata_mapping',
                    'rows' => 8,
                    'placeholder' => <<<'TXT'
                        dcterms:title = o:item/dcterms:title
                        dcterms:creator = "Institution Name"
                        dcterms:licence = "CC-BY-SA-4.0"
                        TXT,
                ],
            ])

            ->add([
                'name' => 'property_identifier',
                'type' => CommonElement\OptionalPropertySelect::class,
                'options' => [
                    'label' => 'Store remote identifier (doi, ark…) in media property', // @translate
                    'empty_option' => 'Do not store', // @translate
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'property_identifier',
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select a property…', // @translate
                ],
            ])
            ->add([
                'name' => 'property_url',
                'type' => CommonElement\OptionalPropertySelect::class,
                'options' => [
                    'label' => 'Store remote IIIF URL in media property', // @translate
                    'empty_option' => 'Do not store', // @translate
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'property_url',
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select a property…', // @translate
                ],
            ])

            ->add([
                'name' => 'media_mode',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Media (iiif or url) handling after deposit', // @translate
                    'label_attributes' => [
                        'style' => 'display: inline-block;',
                    ],
                    'value_options' => [
                        'add' => 'Add a new media to the item and keep original one', // @translate
                        'convert_delete_original' => 'Convert media and delete the original file', // @translate
                        'convert_delete' => 'Convert media and delete original and derivative files', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'media_mode',
                    'value' => 'add',
                ],
            ])
            ->add([
                'name' => 'ingester',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Ingester for converted or new media', // @translate
                    'label_attributes' => [
                        'style' => 'display: inline-block;',
                    ],
                    'value_options' => [
                        'auto' => 'Auto (IIIF if supported, else URL without local storage)', // @translate
                        'iiif' => 'IIIF', // @translate
                        'url' => 'URL without local storage (the original file is served from remote repository)', // @translate
                        'url_local' => 'URL with local storage', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'ingester',
                    'value' => 'auto',
                ],
            ])

            ->add([
                'name' => 'action',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Action', // @translate
                    'value_options' => [
                        'export' => 'Export new files to repository', // @translate
                        'sync' => 'Sync metadata of already exported files', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'action',
                    'value' => 'export',
                ],
            ])

            ->add([
                'name' => 'sync_mode',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Sync mode', // @translate
                    'label_attributes' => [
                        'style' => 'display: inline-block;',
                    ],
                    'value_options' => [
                        'complete' => 'Complete record (only add metadata missing on remote)', // @translate
                        'overwrite' => 'Overwrite mapped metadata (keep unmapped remote metadata)', // @translate
                        'replace' => 'Replace record entirely (discard unmapped remote metadata)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'sync_mode',
                    'value' => 'overwrite',
                ],
            ])
            ->add([
                'name' => 'sync_status',
                'type' => CommonElement\OptionalSelect::class,
                'options' => [
                    'label' => 'Status to set on sync', // @translate
                    'info' => 'When syncing, optionally update the remote status. Nakala: pending/published; Dataverse: published.', // @translate
                    'empty_option' => 'Do not change status', // @translate
                    'value_options' => [
                        'pending' => 'Pending', // @translate
                        'published' => 'Published', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'sync_status',
                ],
            ])

            ->add([
                'name' => 'submit',
                'type' => Element\Submit::class,
                'attributes' => [
                    'id' => 'submit',
                    'value' => 'Run', // @translate
                ],
            ])
        ;
    }

    protected function getEndpointOptions(): array
    {
        $options = [];
        $config = $this->getOption('endpoints') ?? [];
        foreach ($config as $key => $endpoint) {
            $options[$key] = $endpoint['label'] ?? $key;
        }
        return $options;
    }
}
