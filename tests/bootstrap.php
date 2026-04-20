<?php declare(strict_types=1);

require dirname(__DIR__, 3) . '/modules/Common/tests/Bootstrap.php';

\CommonTest\Bootstrap::bootstrap(
    [
        'Common',
        'IiifFromFile',
    ],
    'IiifFromFileTest',
    __DIR__ . '/IiifFromFileTest'
);
