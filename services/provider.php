<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\Service\Provider\Module as ModuleServiceProvider;
use Joomla\CMS\Extension\Service\Provider\ModuleDispatcherFactory as ModuleDispatcherFactoryServiceProvider;
use Joomla\CMS\Extension\Service\Provider\HelperFactory as HelperFactoryServiceProvider;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {

    public function register(Container $container): void
    {
        $container->registerServiceProvider(new ModuleDispatcherFactoryServiceProvider('\\Pablop76\\Module\\Pposmap'));
        $container->registerServiceProvider(new HelperFactoryServiceProvider('\\Pablop76\\Module\\Pposmap\\Site\\Helper'));
        $container->registerServiceProvider(new ModuleServiceProvider());
    }
};