<?php declare(strict_types=1);

namespace IiifFromFile\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

class ExportForm extends Form
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
                    'info' => 'Parameters for the remote collection or deposit. For Nakala, set the collection identifier. One key=value per line.', // @translate
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
                        The local source can be a property (dcterms:title), a prefixed item property (o:item/dcterms:title), or a fixed value in quotes.
                        Examples:
                        dcterms:title = o:item/dcterms:title
                        dcterms:creator = "Institution Name"
                        dcterms:type = o:item/dcterms:type
                        dcterms:licence = "CC-BY-SA-4.0"
                        TXT, // @translate
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
                'name' => 'submit',
                'type' => Element\Submit::class,
                'attributes' => [
                    'id' => 'submit',
                    'value' => 'Export', // @translate
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
