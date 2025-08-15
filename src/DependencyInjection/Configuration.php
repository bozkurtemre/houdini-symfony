<?php

declare(strict_types=1);

namespace Houdini\HoudiniBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('houdini');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('dsn')
                    ->info('Backend DSN for posting telemetry data (e.g., https://api.example.com/telemetry)')
                    ->defaultValue('%env(HOUDINI_DSN)%')
                ->end()
                ->scalarNode('project_id')
                    ->info('Project ID for telemetry identification')
                    ->defaultValue('%env(default::HOUDINI_PROJECT_ID)%')
                ->end()
                ->scalarNode('api_key')
                    ->info('API key for backend authentication')
                    ->defaultValue('%env(default::HOUDINI_API_KEY)%')
                ->end()
                ->booleanNode('enabled')
                    ->info('Enable/disable telemetry collection')
                    ->defaultTrue()
                ->end()
                ->scalarNode('service_name')
                    ->info('Service name for telemetry identification')
                    ->defaultValue('%env(default::HOUDINI_SERVICE_NAME)%')
                ->end()
                ->scalarNode('service_version')
                    ->info('Service version for telemetry identification')
                    ->defaultValue('%env(default::HOUDINI_SERVICE_VERSION)%')
                ->end()
                ->arrayNode('traces')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Enable trace collection')
                            ->defaultTrue()
                        ->end()
                        ->floatNode('sample_rate')
                            ->info('Trace sampling rate (0.0 to 1.0)')
                            ->min(0.0)
                            ->max(1.0)
                            ->defaultValue(1.0)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('metrics')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Enable metrics collection')
                            ->defaultTrue()
                        ->end()
                        ->integerNode('export_interval')
                            ->info('Metrics export interval in seconds')
                            ->min(1)
                            ->defaultValue(60)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('logs')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Enable log collection')
                            ->defaultTrue()
                        ->end()
                        ->arrayNode('levels')
                            ->info('Log levels to collect')
                            ->scalarPrototype()->end()
                            ->defaultValue(['error', 'warning', 'info'])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('http_client')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('timeout')
                            ->info('HTTP client timeout in seconds')
                            ->min(1)
                            ->defaultValue(30)
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
