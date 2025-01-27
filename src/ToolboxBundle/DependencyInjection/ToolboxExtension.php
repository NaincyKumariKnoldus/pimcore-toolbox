<?php

namespace ToolboxBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use ToolboxBundle\Manager\ConfigManager;
use ToolboxBundle\Resolver\ContextResolver;

class ToolboxExtension extends Extension implements PrependExtensionInterface
{
    protected array $contextMergeData = [];
    protected array $contextConfigData = [];

    public function prepend(ContainerBuilder $container): void
    {
        $selfConfigs = $container->getExtensionConfig($this->getAlias());

        $rootConfigs = [];
        foreach ($selfConfigs as $rootConfig) {
            unset($rootConfig['context'], $rootConfig['context_resolver']);
            $rootConfigs[] = $rootConfig;
        }

        $contextMerge = [];
        foreach ($selfConfigs as $config) {
            if (isset($config['context'])) {
                foreach ($config['context'] as $contextName => $contextConfig) {
                    if (isset($contextConfig['settings']['merge_with_root'])) {
                        $contextMerge[$contextName] = $contextConfig['settings']['merge_with_root'];
                    }
                }
            }
        }

        $data = [];

        //get context data
        foreach ($selfConfigs as $config) {
            if (isset($config['context'])) {
                foreach ($config['context'] as $contextName => $contextConfig) {
                    if (!isset($contextMerge[$contextName]) || $contextMerge[$contextName] !== true) {
                        continue;
                    }

                    $cleanContextConfig = $contextConfig;
                    unset($cleanContextConfig['settings']);
                    $this->contextConfigData[$contextName][] = $cleanContextConfig;
                }
            }
        }

        //get context merge data
        foreach ($contextMerge as $contextName => $merge) {
            if ($merge === false) {
                continue;
            }

            foreach ($rootConfigs as $rootConfig) {
                $data[] = [
                    'context' => [
                        $contextName => $rootConfig
                    ]
                ];
            }
        }

        $this->contextMergeData = $data;
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        //append merge data
        foreach ($this->contextMergeData as $append) {
            $configs[] = $append;
        }

        //append custom context data
        foreach ($this->contextConfigData as $contextName => $contextConfigs) {
            foreach ($contextConfigs as $el) {
                $configs[] = [
                    'context' => [
                        $contextName => $el
                    ]
                ];
            }
        }

        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $this->validateToolboxContextConfig($config);
        $this->allocateGoogleMapsApiKey($container);

        $contextResolver = $config['context_resolver'];
        unset($config['context_resolver']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yml');

        $configManagerDefinition = $container->getDefinition(ConfigManager::class);
        $configManagerDefinition->addMethodCall('setConfig', [$config]);

        $container->setParameter('toolbox.area_brick.dialog_aware_bricks', $this->determinateConfigDialogAwareBricks($config));

        //context resolver
        $definition = $container->getDefinition(ContextResolver::class);
        $definition->setClass($contextResolver);
    }

    private function determinateConfigDialogAwareBricks(array $config): array
    {
        $configDialogAwareBricks = [];

        foreach ($config['custom_areas'] as $areaId => $areaSection) {
            if (isset($areaSection['config_elements']) && count($areaSection['config_elements']) > 0) {
                $configDialogAwareBricks[] = $areaId;
            }
        }

        foreach ($config['context'] as $context) {
            foreach ($context['custom_areas'] as $areaId => $areaSection) {
                if (isset($areaSection['config_elements']) && count($areaSection['config_elements']) > 0) {
                    $configDialogAwareBricks[] = $areaId;
                }
            }
        }

        return array_unique($configDialogAwareBricks);
    }

    private function allocateGoogleMapsApiKey(ContainerBuilder $container): void
    {
        $googleBrowserApiKey = null;
        $googleSimpleApiKey = null;

        $pimcoreCoreConfig = $container->hasParameter('pimcore.config') ? $container->getParameter('pimcore.config') : [];
        $pimcoreGoogleServiceConfig = isset($pimcoreCoreConfig['services'], $pimcoreCoreConfig['services']['google']) ? $pimcoreCoreConfig['services']['google'] : [];

        // browser api key
        if ($container->hasParameter('pimcore_system_config.services.google.browserapikey')) {
            $googleBrowserApiKey = $container->getParameter('pimcore_system_config.services.google.browserapikey');
        } elseif ($container->hasParameter('toolbox_google_service_browser_api_key')) {
            $googleBrowserApiKey = $container->getParameter('toolbox_google_service_browser_api_key');
        } elseif (isset($pimcoreGoogleServiceConfig['browser_api_key'])) {
            $googleBrowserApiKey = $pimcoreGoogleServiceConfig['browser_api_key'];
        }

        //simple api key
        if ($container->hasParameter('pimcore_system_config.services.google.simpleapikey')) {
            $googleSimpleApiKey = $container->getParameter('pimcore_system_config.services.google.simpleapikey');
        } elseif ($container->hasParameter('toolbox_google_service_simple_api_key')) {
            $googleSimpleApiKey = $container->getParameter('toolbox_google_service_simple_api_key');
        } elseif (isset($pimcoreGoogleServiceConfig['simple_api_key'])) {
            $googleSimpleApiKey = $pimcoreGoogleServiceConfig['simple_api_key'];
        }

        $container->setParameter('toolbox.google_maps.browser_api_key', $googleBrowserApiKey);
        $container->setParameter('toolbox.google_maps.simple_api_key', $googleSimpleApiKey);
    }

    private function validateToolboxContextConfig(array $config): void
    {
        foreach ($config['context'] as $contextId => $contextConfig) {
            //check if theme is same since it's not possible to merge different themes
            if ($contextConfig['settings']['merge_with_root'] === true && isset($contextConfig['theme']) && $contextConfig['theme']['layout'] !== $config['theme']['layout']) {
                @trigger_error(sprintf(
                    'toolbox context conflict for "%s": merged context cannot have another theme than "%s", "%s" given.',
                    $contextId,
                    $config['theme']['layout'],
                    $contextConfig['theme']['layout']
                ), E_USER_ERROR);
            }
        }
    }
}
