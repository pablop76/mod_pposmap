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
    use Joomla\CMS\Uri\Uri;

    $document = $this->app->getDocument();
    $wa       = $document->getWebAssetManager();
    $wa->getRegistry()->addExtensionRegistryFile('mod_pposmap');

    $moduleId                 = (int) $this->module->id;

    $tokenmapbox              = $params->get('tokenmapbox', '');
    $stylemapbox              = $params->get('stylemapbox', 'mapbox://styles/mapbox/streets-v12');
    $listofpoints             = $params->get('listofpoints', '');
    $zoommapbox               = $params->get('zoommapbox', '1');
    $markermapbox             = $params->get('markermapbox', '');
    $pointslistmapbox         = $params->get('pointslistmapbox', '');
    $addSchema                = (int) $params->get('addschema', 1);
    $clustermarkers           = $params->get('clustermarkers', '0');
    $mapboxorleaflet          = $params->get('mapboxorleaflet', '');
    $groupscontrol            = $params->get('groupscontrol', '');
    $mapHeightRaw             = trim((string) $params->get('mapheight', ''));
    $mapHeightMobileRaw       = trim((string) $params->get('mapheight_mobile', ''));

    /*
     * Jedna kanoniczna, gęsto indeksowana lista punktów. Używa jej lista w HTML, blok
     * Schema.org ORAZ front (trafia do addScriptOptions poniżej). Dzięki temu data-index
     * przycisku "ZOBACZ" jest z definicji tym samym indeksem, co w tablicy features
     * budowanej w custom.js — nie zależy od tego, czy obie strony odfiltrują tak samo.
     *
     * Klucze subformu Joomla (listofpoints0, listofpoints1, ...) NIE są przenumerowywane
     * po usunięciu wiersza ze środka, więc nie wolno po nich iterować licznikiem.
     */
    $validPoints = [];

    foreach ((array) $listofpoints as $point) {
        // Nietknięty parametr ma wartość '' — rzutowanie na tablicę daje [''], nie [].
        if (!\is_object($point) && !\is_array($point)) {
            continue;
        }

        $point = (object) $point;
        $lat   = isset($point->latitudemapbox) ? trim((string) $point->latitudemapbox) : '';
        $lng   = isset($point->longitudemapbox) ? trim((string) $point->longitudemapbox) : '';

        if ($lat === '' || $lng === '' || !is_numeric($lat) || !is_numeric($lng)) {
            continue;
        }

        $validPoints[] = $point;
    }

    $schemaItems = [];

    if ($addSchema) {
        foreach ($validPoints as $index => $point) {
            $item = [
                '@type'    => 'Place',
                'position' => $index + 1,
                'name'     => (string) ($point->geotitle ?? ''),
                'geo'      => [
                    '@type'     => 'GeoCoordinates',
                    'latitude'  => (float) $point->latitudemapbox,
                    'longitude' => (float) $point->longitudemapbox,
                ],
            ];

            if (!empty($point->geodescription)) {
                $item['description'] = (string) $point->geodescription;
            }

            if (!empty($point->telephonevalue)) {
                $item['telephone'] = (string) $point->telephonevalue;
            }

            $schemaItems[] = $item;
        }
    }

    $limitString = static function ($string, $limit, $end = '...') {
        $string = explode(' ', (string) $string, (int) $limit);

        if (count($string) >= (int) $limit) {
            array_pop($string);
            return implode(' ', $string) . $end;
        }

        return implode(' ', $string);
    };

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
        $wa->useScript('mapboxgljs');
        $wa->useStyle('mapboxglcss');
    } else {
        $wa->useScript('leafletjs');
        $wa->useStyle('leafletcss');

        if ((string) $clustermarkers === '1') {
            $wa->useScript('leafletmarkercluster');
            $wa->useStyle('leafletmarkerclustercss');
            $wa->useStyle('leafletmarkerclusterdefaultcss');
        }
    }

    // Nasze style na końcu, żeby mogły nadpisywać vendor CSS.
    $wa->useStyle('mod_pposmap.style');

    $document->addScriptOptions('mod_pposmap.vars.' . $moduleId, [
        'tokenmapbox'     => $tokenmapbox,
        'stylemapbox'     => $stylemapbox,
        'listofpoints'    => $validPoints,
        'zoommapbox'      => $zoommapbox,
        'markermapbox'    => $markermapbox,
        'groupscontrol'   => $groupscontrol,
        'mapboxorleaflet' => $mapboxorleaflet,
        'clustermarkers'  => $clustermarkers,
        'allFilterLeaflet' => Text::_('MOD_PPOSMAP_GROUP_LEAFLET_ALL'),
        'siteRoot'        => rtrim(Uri::root(), '/'),
    ]);

    $wa->useScript('mod_pposmap.custom');

?>
<!-- Start slideshow -->
<?php if ($addSchema && $schemaItems) : ?>
<script type="application/ld+json"><?php echo json_encode([
    '@context'       => 'https://schema.org',
    '@type'          => 'ItemList',
    'itemListElement' => $schemaItems,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<?php endif; ?>
<div class="flex-container table-pposmap" data-pposmap-id="<?php echo $moduleId; ?>"<?php echo $wrapperStyleAttr; ?>>
    <?php if ($pointslistmapbox && $validPoints) : ?>
    <div class="list-items-container uk-visible@m">
        <?php foreach ($validPoints as $index => $point) : ?>
        <div class="list-item">
            <div>
                <h3 class="location mapbox-popup-title"><?php echo $point->geotitle ?? ''; ?></h3>
            </div>
            <div class="uk-flex uk-flex-between uk-flex-middle">
                <p class="mapbox-popup-description"><?php echo $limitString($point->geodescription ?? '', 9); ?></p>
                <button type="button" data-index="<?php echo $index; ?>" class="uk-button uk-button-default button-1"><?php echo Text::_('MOD_PPOSMAP_VIEW_BUTTON'); ?></button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="pposmap-map"></div>
</div>
<!-- End slideshow -->