<?php declare(strict_types=1);

namespace IiifFromFile\Service\Repository;

use IiifFromFile\Repository\DataverseConnector;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class DataverseConnectorFactory implements FactoryInterface
{
    public function __invoke(
        ContainerInterface $services,
        $requestedName,
        ?array $options = null
    ) {
        return new DataverseConnector(
            $services->get('Omeka\HttpClient'),
            $services->get('Omeka\Logger')
        );
    }
}
