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
    $tileUrl                  = trim((string) $params->get('tileurl', ''));
    $tileAttribution          = trim((string) $params->get('tileattribution', ''));
    $tileMinZoom              = trim((string) $params->get('tileminzoom', ''));
    $tileMaxZoom              = trim((string) $params->get('tilemaxzoom', ''));
    $viewButtonText           = trim((string) $params->get('viewbuttontext', ''));
    $viewButtonClass          = trim((string) $params->get('viewbuttonclass', ''));

    $viewButtonLabel = $viewButtonText !== '' ? $viewButtonText : Text::_('MOD_PPOSMAP_VIEW_BUTTON');

    /*
     * Puste pole klas oznacza własny styl modułu. Podane klasy zastępują go w całości,
     * zamiast się z nim sumować — inaczej reguły modułu i frameworka szablonu biłyby się
     * o kolejność w arkuszach i wynik zależałby od tego, który plik wczytał się później.
     */
    $viewButtonClassAttr = $viewButtonClass !== '' ? $viewButtonClass : 'pposmap-button';

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

    // Samo "500" jest wygodniejsze do wpisania niż "500px", a "70vh" czy "25%"
    // trzeba przepuścić bez zmian.
    $toCssLength = static function ($value) {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        return preg_match('/^\d+$/', $value) ? ($value . 'px') : $value;
    };

    $cssVariables = [
        '--pposmap-height'        => $toCssLength($mapHeightRaw),
        '--pposmap-height-mobile' => $toCssLength($mapHeightMobileRaw),
        '--pposmap-list-width'    => $toCssLength($params->get('listwidth', '')),
    ];

    $wrapperStyleParts = [];

    foreach ($cssVariables as $name => $value) {
        if ($value !== '') {
            $wrapperStyleParts[] = $name . ': ' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . ';';
        }
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
        'tileurl'         => $tileUrl,
        'tileattribution' => $tileAttribution,
        'tileminzoom'     => $tileMinZoom,
        'tilemaxzoom'     => $tileMaxZoom,
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
<div class="pposmap-container table-pposmap" data-pposmap-id="<?php echo $moduleId; ?>"<?php echo $wrapperStyleAttr; ?>>
    <?php if ($pointslistmapbox && $validPoints) : ?>
    <div class="pposmap-list">
        <?php foreach ($validPoints as $index => $point) : ?>
        <div class="pposmap-list-item">
            <h3 class="pposmap-list-item-title"><?php echo $point->geotitle ?? ''; ?></h3>
            <div class="pposmap-list-item-row">
                <p class="pposmap-list-item-desc"><?php echo $limitString($point->geodescription ?? '', 9); ?></p>
                <button
                    type="button"
                    data-index="<?php echo $index; ?>"
                    class="<?php echo htmlspecialchars($viewButtonClassAttr, ENT_QUOTES, 'UTF-8'); ?>"
                    aria-label="<?php echo htmlspecialchars(trim($viewButtonLabel . ' ' . ($point->geotitle ?? '')), ENT_QUOTES, 'UTF-8'); ?>"
                ><?php echo htmlspecialchars($viewButtonLabel, ENT_QUOTES, 'UTF-8'); ?></button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="pposmap-map"></div>
</div>
<!-- End slideshow -->