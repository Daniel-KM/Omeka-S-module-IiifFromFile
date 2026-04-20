<?php declare(strict_types=1);

namespace IiifFromFile\Service\Repository;

use IiifFromFile\Repository\NakalaConnector;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class NakalaConnectorFactory implements FactoryInterface
{
    public function __invoke(
        ContainerInterface $services,
        $requestedName,
        ?array $options = null
    ) {
        return new NakalaConnector(
            $services->get('Omeka\HttpClient'),
            $services->get('Omeka\Logger')
        );
    }
}
