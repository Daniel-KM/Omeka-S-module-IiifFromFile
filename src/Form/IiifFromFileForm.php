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
                'name' => 'collection_params',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'Collection / deposit parameters', // @translate
                    'info' => 'Parameters for the remote collection or deposit, one key=value per line. "collection_id" is the parent container on the remote: a Nakala collection identifier (e.g. 10.34847/nkl.xxxx) for Nakala, or a parent dataverse alias for Dataverse. "status" is "pending" or "published".', // @translate
                    'as_key_value' => true,
                ],
                'attributes' => [
                    'id' => 'collection_params',
                    'rows' => 4,
                    'placeholder' => <<<'TXT'
                        collection_id = xxxxxxxx
                        status = pending
                        TXT,
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
                'name' => 'media_mode',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Media (iiif or url) handling after deposit', // @translate
                    'label_attributes' => [
                        'style' => 'display: inline-block;',
                    ],
                    'info' => 'How to update the Omeka media once the file is deposited. The connector picks the ingester IIIF when the repository supports it, otherwise url.', // @translate
                    'value_options' => [
                        'add' => 'Add a new media to the item as iiif or url and keep original media', // @translate
                        'convert_delete_original' => 'Convert media to iiif or url and delete the original file', // @translate
                        'convert_delete' => 'Convert media and delete original and derivative files', // @translate
                        'convert' => 'Convert media and keep original and derivatives files (use Easy Admin to delete or restore them)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'media_mode',
                    'value' => 'add',
                ],
            ])
            ->add([
                'name' => 'store_original',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Original file when the repository does not support iiif', // @translate
                    'value_options' => [
                        '0' => 'Use url from the remote endpoint (warning: it may be protected)', // @translate
                        '1' => 'Keep a local copy', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'store_original',
                    'value' => '0',
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
