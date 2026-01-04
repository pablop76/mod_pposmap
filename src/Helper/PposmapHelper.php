<?php

namespace Pablop76\Module\Pposmap\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;

class PposmapHelper
{
    public static function getLoggedonUsername(string $default)
    {
        $user = Factory::getApplication()->getIdentity();

        if (!empty($user->id)) {
            return (string) $user->username;
        }

        return $default;
    }
}
