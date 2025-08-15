<?php

declare(strict_types=1);

namespace Houdini\HoudiniBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class HoudiniExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // Load services
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.yaml');

        // Set configuration parameters
        $container->setParameter('houdini.dsn', $config['dsn']);
        $container->setParameter('houdini.api_key', $config['api_key']);
        $container->setParameter('houdini.enabled', $config['enabled']);
        $container->setParameter('houdini.service_name', $config['service_name']);
        $container->setParameter('houdini.service_version', $config['service_version']);
        $container->setParameter('houdini.traces', $config['traces']);
        $container->setParameter('houdini.metrics', $config['metrics']);
        $container->setParameter('houdini.logs', $config['logs']);
        $container->setParameter('houdini.http_client', $config['http_client']);

        // Set individual http_client parameters for service definitions
        $container->setParameter('houdini.http_client.timeout', $config['http_client']['timeout']);
        $container->setParameter('houdini.http_client.retry_attempts', $config['http_client']['retry_attempts']);
        $container->setParameter('houdini.http_client.headers', $config['http_client']['headers']);

        // Install recipe files if they don't exist
        $this->installRecipeFiles($container);

        // Disable services if bundle is disabled
        if (!$config['enabled']) {
            $container->removeDefinition('houdini.telemetry_service');
            $container->removeDefinition('houdini.http_client');
            $container->removeDefinition('houdini.event_listener.request');
            $container->removeDefinition('houdini.event_listener.exception');
        }
    }

    public function getAlias(): string
    {
        return 'houdini';
    }

    private function installRecipeFiles(ContainerBuilder $container): void
    {
        $projectDir = $container->getParameter('kernel.project_dir');
        $configFile = $projectDir . '/config/packages/houdini.yaml';
        $envFile = $projectDir . '/.env';

        // Install config file if it doesn't exist
        if (!file_exists($configFile)) {
            $recipeConfigFile = __DIR__ . '/../../recipe/config/packages/houdini.yaml';
            if (file_exists($recipeConfigFile)) {
                $configDir = dirname($configFile);
                if (!is_dir($configDir)) {
                    mkdir($configDir, 0755, true);
                }
                copy($recipeConfigFile, $configFile);
            }
        }

        // Add environment variables if they don't exist
        if (file_exists($envFile)) {
            $envContent = file_get_contents($envFile);
            if (strpos($envContent, 'HOUDINI_DSN') === false) {
                $envAddition = "\n###> houdini/houdini-symfony ###\n";
                $envAddition .= "HOUDINI_DSN=https://your-backend.example.com/api/telemetry\n";
                $envAddition .= "HOUDINI_API_KEY=your-api-key\n";
                $envAddition .= "HOUDINI_SERVICE_NAME=my-app\n";
                $envAddition .= "HOUDINI_SERVICE_VERSION=1.0.0\n";
                $envAddition .= "###< houdini/houdini-symfony ###\n";

                file_put_contents($envFile, $envContent . $envAddition);
            }
        }
    }
}
