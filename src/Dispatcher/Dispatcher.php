<?php

namespace Pablop76\Module\Pposmap\Site\Dispatcher;

\defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\DispatcherInterface;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\Input\Input;
use Joomla\Registry\Registry;
use Pablop76\Module\Pposmap\Site\Helper\PposmapHelper;

class Dispatcher implements DispatcherInterface
{ 
    protected $module;
    
    protected $app;

    public function __construct(\stdClass $module, CMSApplicationInterface $app, Input $input)
    {
        $this->module = $module;
        $this->app = $app;
    }

    public function dispatch()
    {
        $language = $this->app->getLanguage();
        $language->load('mod_pposmap', JPATH_BASE . '/modules/mod_pposmap');

        $username = PposmapHelper::getLoggedonUsername(Text::_('MOD_PPOSMAP_GUEST'));        
        $hello = Text::_('MOD_POPSMAP_GREETING') . $username;
        
        $params = new Registry($this->module->params);

        require ModuleHelper::getLayoutPath('mod_pposmap');
    }
}