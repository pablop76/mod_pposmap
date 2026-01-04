<?php

    /**
     * @package     Joomla.Site
     * @subpackage  mod_pposmap
     *
     * @copyright   (C) 2024 pablop76, Inc. <https://web-service.com.pl>
     * @license     GNU General Public License version 2 or later; see LICENSE.txt
     */

    defined('_JEXEC') or die;
    use Joomla\CMS\Language\Text;

    $document = $this->app->getDocument();
    $wa       = $document->getWebAssetManager();
    $wa->getRegistry()->addExtensionRegistryFile('mod_pposmap');

    $tokenmapbox              = $params->get('tokenmapbox', '');
    $stylemapbox              = $params->get('stylemapbox', 'mapbox://styles/mapbox/streets-v12');
    $listofpoints             = $params->get('listofpoints', '');
    $zoommapbox               = $params->get('zoommapbox', '1');
    $markermapbox             = $params->get('markermapbox', '');
    $pointslistmapbox         = $params->get('pointslistmapbox', '');
    $mapboxorleaflet          = $params->get('mapboxorleaflet', '');
    $groupscontrol            = $params->get('groupscontrol', '');
    $mapHeightRaw             = trim((string) $params->get('mapheight', ''));
    $mapHeightMobileRaw       = trim((string) $params->get('mapheight_mobile', ''));

    $mapHeightCss = '';
    if ($mapHeightRaw !== '') {
        $mapHeightCss = preg_match('/^\d+$/', $mapHeightRaw) ? ($mapHeightRaw . 'px') : $mapHeightRaw;
    }

    $mapHeightMobileCss = '';
    if ($mapHeightMobileRaw !== '') {
        $mapHeightMobileCss = preg_match('/^\d+$/', $mapHeightMobileRaw) ? ($mapHeightMobileRaw . 'px') : $mapHeightMobileRaw;
    }

    $wrapperStyleParts = [];
    if ($mapHeightCss !== '') {
        $wrapperStyleParts[] = '--pposmap-height: ' . htmlspecialchars($mapHeightCss, ENT_QUOTES, 'UTF-8') . ';';
    }
    if ($mapHeightMobileCss !== '') {
        $wrapperStyleParts[] = '--pposmap-height-mobile: ' . htmlspecialchars($mapHeightMobileCss, ENT_QUOTES, 'UTF-8') . ';';
    }
    $wrapperStyleAttr = $wrapperStyleParts ? (' style="' . implode(' ', $wrapperStyleParts) . '"') : '';

    $isMapbox = ((string) $mapboxorleaflet) === '0' || $mapboxorleaflet === '';

    if ($isMapbox) {
        $wa->useScript('mapbox');
        $wa->useStyle('stylemapbox');
    } else {
        $wa->useScript('leafletjs');
        $wa->useStyle('leafletcss');
    }

    // Nasze style na końcu, żeby mogły nadpisywać vendor CSS.
    $wa->useStyle('mod_pposmap.style');

    $document->addScriptOptions('mod_pposmap.vars', [
        'tokenmapbox'     => $tokenmapbox,
        'stylemapbox'     => $stylemapbox,
        'listofpoints'    => $listofpoints,
        'zoommapbox'      => $zoommapbox,
        'markermapbox'    => $markermapbox,
        'groupscontrol'   => $groupscontrol,
        'mapboxorleaflet' => $mapboxorleaflet,
        'allFilterLeaflet' => Text::_('MOD_PPOSMAP_GROUP_LEAFLET_ALL'),
    ]);

    $wa->useScript('mod_pposmap.custom');

?>
<!-- Start slideshow -->
<div class="flex-container table-pposmap"<?php echo $wrapperStyleAttr; ?>>
    <?php if ($pointslistmapbox) {?>
    <div class="list-items-container uk-visible@m">
        <?php
            $points = $listofpoints;
            $limitString = static function ($string, $limit, $end = '...') {
                $string = explode(' ', (string) $string, (int) $limit);

                if (count($string) >= (int) $limit) {
                    array_pop($string);
                    return implode(' ', $string) . $end;
                }

                return implode(' ', $string);
            };

                for ($i = 0; $i <= count((array) $listofpoints) - 1; $i++) {
                $point = $points->{"listofpoints" . $i}; ?>
        <div class="list-item">
            <div>
                <h3 class="location mapbox-popup-title"><?php echo $point->geotitle; ?></h3>
            </div>
            <div class="uk-flex uk-flex-between uk-flex-middle">
                <p class="mapbox-popup-description"><?php echo $limitString($point->geodescription, 9); ?></p>
                <a data-index='<?php echo $i; ?>' class="uk-button uk-button-default button-1">ZOBACZ</a>
            </div>
        </div>
        <?php
        }?>
    </div>
    <?php
        }
        ?>
    <div id="map"></div>
</div>
<!-- End slideshow -->