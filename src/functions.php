<?php

namespace PMSIpilot\DockerComposeViz;

use Fhaculty\Graph\Edge;
use Fhaculty\Graph\Graph;
use Fhaculty\Graph\Vertex;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * @public
 *
 * @param string $path Path to a YAML file
 *
 * @return array
 */
function readConfiguration(string $path) : array
{
    if (file_exists($path) === false) {
        throw new \InvalidArgumentException(sprintf('File "%s" does not exist', $path));
    }

    try {
        return Yaml::parse(file_get_contents($path));
    } catch (ParseException $exception) {
        throw new \InvalidArgumentException(sprintf('File "%s" does not contain valid YAML', $path));
    }
}

/**
 * @public
 *
 * @param array $configuration Docker compose (version 1 or 2) configuration
 *
 * @return array List of service definitions exctracted from the configuration
 */
function fetchServices(array $configuration) : array
{
    if (isset($configuration['version']) === false || (int) $configuration['version'] === 1) {
        return $configuration;
    }

    return $configuration['services'] ?? [];
}

/**
 * @public
 *
 * @param array $configuration Docker compose (version 1 or 2) configuration
 *
 * @return array List of service definitions exctracted from the configuration
 */
function fetchVolumes(array $configuration) : array
{
    if (isset($configuration['version']) === false || (int) $configuration['version'] === 1) {
        return [];
    }

    return $configuration['volumes'] ?? [];
}

/**
 * @public
 *
 * @param array $configuration Docker compose (version 1 or 2) configuration
 *
 * @return array List of service definitions exctracted from the configuration
 */
function fetchNetworks(array $configuration) : array
{
    if (isset($configuration['version']) === false || (int) $configuration['version'] === 1) {
        return [];
    }

    return $configuration['networks'] ?? [];
}

/**
 * @public
 *
 * @param array  $services    Docker compose service definitions
 * @param array  $volumes     Docker compose volume definitions
 * @param array  $networks    Docker compose network definitions
 * @param bool   $withVolumes Create vertices and edges for volumes
 * @param string $path        Path of the current docker-compose configuration file
 *
 * @return Graph The complete graph for the given list of services
 */
function createGraph(array $services, array $volumes, array $networks, bool $withVolumes, string $path) : Graph
{
    return makeVerticesAndEdges(new Graph(), $services, $volumes, $networks, $withVolumes, $path);
}

/**
 * @public
 *
 * @param Graph $graph      Input graph
 * @param bool  $horizontal Display a horizontal graph
 *
 * @return Graph A copy of the input graph with style attributes
 */
function applyGraphvizStyle(Graph $graph, bool $horizontal) : Graph
{
    $graph = $graph->createGraphClone();
    $graph->setAttribute('graphviz.graph.pad', '0.5');
    $graph->setAttribute('graphviz.graph.ratio', 'fill');

    if ($horizontal === true) {
        $graph->setAttribute('graphviz.graph.rankdir', 'LR');
    }

    foreach ($graph->getVertices() as $vertex) {
        switch ($vertex->getAttribute('docker_compose.type')) {
            case 'service':
                $vertex->setAttribute('graphviz.shape', 'component');
                break;

            case 'external_service':
                $vertex->setAttribute('graphviz.shape', 'component');
                $vertex->setAttribute('graphviz.color', 'gray');
                break;

            case 'volume':
                $vertex->setAttribute('graphviz.shape', 'folder');
                break;

            case 'network':
                $vertex->setAttribute('graphviz.shape', 'pentagon');
                break;

            case 'external_network':
                $vertex->setAttribute('graphviz.shape', 'pentagon');
                $vertex->setAttribute('graphviz.color', 'gray');
                break;

            case 'port':
                $vertex->setAttribute('graphviz.shape', 'circle');

                if (($proto = $vertex->getAttribute('docker_compose.proto')) === 'udp') {
                    $vertex->setAttribute('graphviz.style', 'dashed');
                }
                break;
        }
    }

    foreach ($graph->getEdges() as $edge) {
        switch ($edge->getAttribute('docker_compose.type')) {
            case 'ports':
            case 'links':
                $edge->setAttribute('graphviz.style', 'solid');
                break;

            case 'external_links':
                $edge->setAttribute('graphviz.style', 'solid');
                $edge->setAttribute('graphviz.color', 'gray');
                break;

            case 'volumes_from':
            case 'volumes':
                $edge->setAttribute('graphviz.style', 'dashed');
                break;

            case 'depends_on':
                $edge->setAttribute('graphviz.style', 'dotted');
                break;

            case 'extends':
                $edge->setAttribute('graphviz.dir', 'both');
                $edge->setAttribute('graphviz.arrowhead', 'inv');
                $edge->setAttribute('graphviz.arrowtail', 'dot');
                break;
        }

        if (($alias = $edge->getAttribute('docker_compose.alias')) !== null) {
            $edge->setAttribute('graphviz.label', $alias);
        }

        if ($edge->getAttribute('docker_compose.bidir')) {
            $edge->setAttribute('graphviz.dir', 'both');
        }
    }

    return $graph;
}

/**
 * @internal
 *
 * @param Graph $graph       Input graph
 * @param array $services    Docker compose service definitions
 * @param array $volumes     Docker compose volume definitions
 * @param array $networks    Docker compose network definitions
 * @param bool  $withVolumes Create vertices and edges for volumes
 *
 * @return Graph A copy of the input graph with vertices and edges for services
 */
function makeVerticesAndEdges(Graph $graph, array $services, array $volumes, array $networks, bool $withVolumes, $path) : Graph
{
    if ($withVolumes === true) {
        foreach (array_keys($volumes) as $volume) {
            addVolume($graph, 'named: '.$volume);
        }
    }

    foreach ($networks as $network => $definition) {
        addNetwork(
            $graph, 'net: '.$network,
            isset($definition['external']) && $definition['external'] === true ? 'external_network' : 'network'
        );
    }

    foreach ($services as $service => $definition) {
        addService($graph, $service);

        if (isset($definition['extends'])) {
            $configuration = readConfiguration(dirname($path).DIRECTORY_SEPARATOR.$definition['extends']['file']);
            $extendedServices = fetchServices($configuration);
            $extendedVolumes = fetchVolumes($configuration);
            $extendedNetworks = fetchVolumes($configuration);

            $graph = makeVerticesAndEdges($graph, $extendedServices, $extendedVolumes, $extendedNetworks, $withVolumes, dirname($path).DIRECTORY_SEPARATOR.$definition['extends']['file']);

            addRelation(
                 addService($graph, $definition['extends']['service']),
                $graph->getVertex($service),
                'extends'
            );
        }

        foreach ($definition['links'] ?? [] as $link) {
            list($target, $alias) = explodeMapping($link);

            addRelation(
                addService($graph, $target),
                $graph->getVertex($service),
                'links',
                $alias !== $target ? $alias : null
            );
        }

        foreach ($definition['external_links'] ?? [] as $link) {
            list($target, $alias) = explodeMapping($link);

            addRelation(
                addService($graph, $target, 'external_service'),
                $graph->getVertex($service),
                'external_links',
                $alias !== $target ? $alias : null
            );
        }

        foreach ($definition['depends_on'] ?? [] as $dependency) {
            addRelation(
                $graph->getVertex($service),
                addService($graph, $dependency),
                'depends_on'
            );
        }

        foreach ($definition['volumes_from'] ?? [] as $source) {
            addRelation(
                addService($graph, $source),
                $graph->getVertex($service),
                'volumes_from'
            );
        }

        if ($withVolumes === true) {
            foreach ($definition['volumes'] ?? [] as $volume) {
                list($host, $container, $attr) = explodeMapping($volume);

                if ($host[0] !== '.' && $host[0] !== DIRECTORY_SEPARATOR) {
                    $host = 'named: '.$host;
                }

                addRelation(
                    addVolume($graph, $host),
                    $graph->getVertex($service),
                    'volumes',
                    $host !== $container ? $container : null,
                    $attr !== 'ro'
                );
            }
        }

        foreach ($definition['ports'] ?? [] as $port) {
            list($host, $container, $proto) = explodeMapping($port);

            addRelation(
                addPort($graph, (int) $host, $proto),
                $graph->getVertex($service),
                'ports',
                $host !== $container ? $container : null
            );
        }

        foreach ($definition['networks'] ?? [] as $network => $config) {
            $network = is_int($network) ? $config : $network;
            $config = is_int($network) ? [] : $config;
            $aliases = $config['aliases'] ?? [];

            addRelation(
                $graph->getVertex($service),
                addNetwork($graph, 'net: '.$network),
                'networks',
                count($aliases) > 0 ? implode(', ', $aliases) : null
            );
        }
    }

    return $graph;
}

/**
 * @internal
 *
 * @param Graph  $graph   Input graph
 * @param string $service Service name
 * @param string $type    Service type
 *
 * @return Vertex
 */
function addService(Graph $graph, string $service, string $type = null)
{
    if ($graph->hasVertex($service) === true) {
        return $graph->getVertex($service);
    }

    $vertex = $graph->createVertex($service);
    $vertex->setAttribute('docker_compose.type', $type ?: 'service');

    return $vertex;
}

/**
 * @internal
 *
 * @param Graph       $graph Input graph
 * @param int         $port  Port number
 * @param string|null $proto Protocol
 *
 * @return Vertex
 */
function addPort(Graph $graph, int $port, string $proto = null)
{
    if ($graph->hasVertex($port) === true) {
        return $graph->getVertex($port);
    }

    $vertex = $graph->createVertex($port);
    $vertex->setAttribute('docker_compose.type', 'port');
    $vertex->setAttribute('docker_compose.proto', $proto ?: 'tcp');

    return $vertex;
}

/**
 * @internal
 *
 * @param Graph  $graph Input graph
 * @param string $path  Path
 *
 * @return Vertex
 */
function addVolume(Graph $graph, string $path)
{
    if ($graph->hasVertex($path) === true) {
        return $graph->getVertex($path);
    }

    $vertex = $graph->createVertex($path);
    $vertex->setAttribute('docker_compose.type', 'volume');

    return $vertex;
}

/**
 * @internal
 *
 * @param Graph  $graph Input graph
 * @param string $name  Name of the network
 * @param string $type  Network type
 *
 * @return Vertex
 */
function addNetwork(Graph $graph, string $name, string $type = null)
{
    if ($graph->hasVertex($name) === true) {
        return $graph->getVertex($name);
    }

    $vertex = $graph->createVertex($name);
    $vertex->setAttribute('docker_compose.type', $type ?: 'network');

    return $vertex;
}

/**
 * @internal
 *
 * @param Vertex      $from          Source vertex
 * @param Vertex      $to            Destination vertex
 * @param string      $type          Type of the relation (one of "links", "volumes_from", "depends_on", "ports");
 * @param string|null $alias         Alias associated to the linked element
 * @param bool|null   $bidirectional Biderectional or not
 *
 * @return Edge\Directed
 */
function addRelation(Vertex $from, Vertex $to, string $type, string $alias = null, bool $bidirectional = false) : Edge\Directed
{
    $edge = null;

    if ($from->hasEdgeTo($to)) {
        $edges = $from->getEdgesTo($to);

        foreach ($edges as $edge) {
            if ($edge->getAttribute('docker_compose.type') === $type) {
                break;
            }
        }
    }

    if (null === $edge) {
        $edge = $from->createEdgeTo($to);
    }

    $edge->setAttribute('docker_compose.type', $type);

    if ($alias !== null) {
        $edge->setAttribute('docker_compose.alias', $alias);
    }

    $edge->setAttribute('docker_compose.bidir', $bidirectional);

    return $edge;
}

/**
 * @internal
 *
 * @param string $mapping A docker mapping (<from>[:<to>])
 *
 * @return array An 2 items array containing the parts of the mapping.
 *               If the mapping does not specify a second part, the first one will be repeated
 */
function explodeMapping($mapping) : array
{
    $parts = explode(':', $mapping);
    $parts[1] = $parts[1] ?? $parts[0];

    $subparts = array_values(array_filter(explode('/', $parts[1])));

    if (count($subparts) > 2) {
        $subparts = [$parts[1], $parts[2] ?? null];
    }

    return [$parts[0], $subparts[0], $subparts[1] ?? null];
}
