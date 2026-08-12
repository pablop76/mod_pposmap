<?php

/**
 * @package     Joomla.Site
 * @subpackage  mod_pposmap
 *
 * @copyright   (C) 2024 pablop76, Inc. <https://web-service.com.pl>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Pablop76\Module\Pposmap\Site\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;

/**
 * Przycisk czyszczący wszystkie wiersze subformu z punktami.
 *
 * Pole nie przechowuje żadnej wartości — cała robota dzieje się w przeglądarce,
 * na wierszach subformu. To celowe: usunięcie zapisuje się dopiero wtedy, gdy
 * administrator kliknie „Zapisz”, więc wyjście z modułu bez zapisu jest naturalnym
 * cofnięciem operacji. Gdyby czyszczenie szło po stronie serwera, klik byłby
 * nieodwracalny w momencie kliknięcia.
 *
 * @since  1.3.0
 */
class ClearpointsField extends FormField
{
    /**
     * Typ pola.
     *
     * @var    string
     * @since  1.3.0
     */
    protected $type = 'Clearpoints';

    /**
     * Buduje markup przycisku.
     *
     * @return  string
     *
     * @since   1.3.0
     */
    protected function getInput()
    {
        $wa = Factory::getApplication()->getDocument()->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('mod_pposmap');
        $wa->useScript('mod_pposmap.admin');

        // Nazwa pola subformu do wyczyszczenia — w XML można ją nadpisać atrybutem target.
        $target = (string) ($this->element['target'] ?? 'listofpoints');

        /*
         * Napisy lecą do JS-a w atrybucie, a nie przez osobne wywołania Text::_ w skrypcie,
         * bo plik JS jest statyczny i nie przechodzi przez warstwę językową Joomli.
         */
        $strings = json_encode([
            'button'   => Text::_('MOD_PPOSMAP_CLEAR_POINTS_BUTTON'),
            'confirm'  => Text::_('MOD_PPOSMAP_CLEAR_POINTS_CONFIRM'),
            'empty'    => Text::_('MOD_PPOSMAP_CLEAR_POINTS_EMPTY'),
            'done'     => Text::_('MOD_PPOSMAP_CLEAR_POINTS_DONE'),
            'undo'     => Text::_('MOD_PPOSMAP_CLEAR_POINTS_UNDO'),
            'restored' => Text::_('MOD_PPOSMAP_CLEAR_POINTS_RESTORED'),
            'missing'  => Text::_('MOD_PPOSMAP_CLEAR_POINTS_MISSING'),
        ], JSON_UNESCAPED_UNICODE);

        $escape = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        /*
         * Klasy Bootstrapa są tu w porządku, w odróżnieniu od frontu: panel administratora
         * Joomli (szablon Atum) zawsze stoi na Bootstrapie, więc nie ma tu ryzyka trafienia
         * na szablon bez frameworka, przed którym ostrzega CLAUDE.md.
         */
        return '<div class="pposmap-clear" data-pposmap-clear'
            . ' data-target="' . $escape($target) . '"'
            . ' data-strings="' . $escape($strings) . '">'
            . '<div class="d-flex flex-wrap align-items-center gap-2">'
            . '<button type="button" class="btn btn-danger" data-pposmap-clear-action>'
            . '<span class="icon-trash" aria-hidden="true"></span> '
            . $escape(Text::_('MOD_PPOSMAP_CLEAR_POINTS_BUTTON'))
            . '</button>'
            . '<button type="button" class="btn btn-secondary d-none" data-pposmap-clear-undo>'
            . '<span class="icon-undo" aria-hidden="true"></span> '
            . $escape(Text::_('MOD_PPOSMAP_CLEAR_POINTS_UNDO'))
            . '</button>'
            . '</div>'
            . '<div class="mt-2 small text-muted" data-pposmap-clear-status role="status" aria-live="polite"></div>'
            . '</div>';
    }
}
