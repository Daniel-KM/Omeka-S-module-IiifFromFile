<?php declare(strict_types=1);

namespace IiifFromFile\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

class IiifFromFileForm extends Form
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'endpoint',
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Destination', // @translate
                    'info' => 'Select the remote repository where files will be deposited.', // @translate
                    'value_options' => $this->getEndpointOptions(),
                ],
                'attributes' => [
                    'id' => 'endpoint',
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
                'name' => 'query',
                'type' => OmekaElement\Query::class,
                'options' => [
                    'label' => 'Items to export', // @translate
                    'info' => 'Select items whose media files will be exported. Leave empty for all items.', // @translate
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
                'type' => OmekaElement\PropertySelect::class,
                'options' => [
                    'label' => 'Store remote identifier in media property', // @translate
                    'info' => 'If set, the remote identifier (e.g. Nakala DOI) will be added to this property on the media.', // @translate
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
                'type' => OmekaElement\PropertySelect::class,
                'options' => [
                    'label' => 'Store remote IIIF URL in media property', // @translate
                    'info' => 'If set, the IIIF image URL will be added to this property on the media.', // @translate
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
                'name' => 'api_user',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'API user', // @translate
                    'info' => 'Username or email for the remote API (if required).', // @translate
                ],
                'attributes' => [
                    'id' => 'api_user',
                ],
            ])
            ->add([
                'name' => 'api_key',
                'type' => Element\Password::class,
                'options' => [
                    'label' => 'API key', // @translate
                    'info' => 'Authentication key for the remote API.', // @translate
                ],
                'attributes' => [
                    'id' => 'api_key',
                    'required' => true,
                ],
            ])
            ->add([
                'name' => 'media_mode',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Media (iiif or url) handling after deposit', // @translate
                    'label_attributes' => [
                        'style' => 'display: inline-block;',
                    ],
                    'value_options' => [
                        'add' => 'Add a new media to the item and keep original one', // @translate
                        'convert_delete_original' => 'Convert media and delete the original file', // @translate
                        'convert_delete' => 'Convert media and delete original and derivative files', // @translate
                        // This option is too much complicated and has no real usage.
                        // 'convert' => 'Convert media and keep original and derivatives files (use Easy Admin to delete or restore them)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'media_mode',
                    'value' => 'add',
                ],
            ])
            ->add([
                'name' => 'ingester',
                'type' => Element\Radio::class,
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
                'type' => Element\Radio::class,
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
