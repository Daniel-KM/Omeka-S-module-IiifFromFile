<?php declare(strict_types=1);

namespace IiifFromFile;

return [
    'controllers' => [
        'invokables' => [
            Controller\AdminController::class => Controller\AdminController::class,
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'iiif-from-file' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/iiif-from-file',
                            'defaults' => [
                                '__NAMESPACE__' => 'IiifFromFile\Controller',
                                'controller' => Controller\AdminController::class,
                                'action' => 'index',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            [
                'label' => 'IIIF from File', // @translate
                'route' => 'admin/iiif-from-file',
                'resource' => Controller\AdminController::class,
                'privilege' => 'index',
            ],
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\ExportForm::class => Form\ExportForm::class,
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => \Laminas\I18n\Translator\Loader\Gettext::class,
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'service_manager' => [
        'factories' => [
            Repository\NakalaConnector::class => Service\Repository\NakalaConnectorFactory::class,
            Repository\DataverseConnector::class => Service\Repository\DataverseConnectorFactory::class,
        ],
    ],
    'iiiffromfile' => [
        // Each endpoint references a connector service.
        'endpoints' => [
            'dataverse_demo' => [
                'label' => 'Dataverse (demo, test)',
                'connector' => Repository\DataverseConnector::class,
                'api_url' => 'https://demo.dataverse.org',
                'base_url' => 'https://demo.dataverse.org',
            ],
            'dataverse_recherche' => [
                'label' => 'Recherche Data Gouv (CNRS)',
                'connector' => Repository\DataverseConnector::class,
                'api_url' => 'https://entrepot.recherche.data.gouv.fr',
                'base_url' => 'https://entrepot.recherche.data.gouv.fr',
            ],
            'dataverse_indores' => [
                'label' => 'Data IndoRES',
                'connector' => Repository\DataverseConnector::class,
                'api_url' => 'https://data.indores.fr',
                'base_url' => 'https://data.indores.fr',
            ],
            'nakala' => [
                'label' => 'Nakala (production)',
                'connector' => Repository\NakalaConnector::class,
                'api_url' => 'https://api.nakala.fr/',
                'base_url' => 'https://nakala.fr/',
            ],
            'nakala_test' => [
                'label' => 'Nakala (test)',
                'connector' => Repository\NakalaConnector::class,
                'api_url' => 'https://apitest.nakala.fr/',
                'base_url' => 'https://test.nakala.fr/',
            ],
        ],
    ],
];
