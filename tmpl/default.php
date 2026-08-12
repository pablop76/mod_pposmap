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

    /*
     * Moduł przypięty do wszystkich stron powielałby te same lokalizacje w całej
     * witrynie. Puste pole zachowuje dotychczasowe zachowanie, czyli znacznik
     * wszędzie tam, gdzie moduł się wyświetla.
     */
    $schemaMenuItems = array_filter((array) $params->get('schemamenuitems', []), static fn($id) => (int) $id > 0);

    if ($addSchema && $schemaMenuItems) {
        $currentItemId = (int) $this->app->getInput()->getInt('Itemid');
        $addSchema     = in_array($currentItemId, array_map('intval', $schemaMenuItems), true);
    }

    $schemaGraph = [];

    if ($addSchema) {
        $schemaType     = (string) $params->get('schematype', 'Place');
        $addressCountry = trim((string) $params->get('addresscountry', ''));
        $siteRootUrl    = rtrim(Uri::root(), '/');

        /*
         * parentOrganization jest właściwością Organization. LocalBusiness dziedziczy
         * i po Organization, i po Place, więc tam jest w porządku — ale Place
         * i TouristAttraction siedzą wyłącznie w gałęzi Place i tam byłoby nieprawidłowe.
         * Do tego powiązanie z organizacją ma sens tylko dla własnych placówek:
         * podpięcie cudzej firmy pod swoją organizację wprowadza wyszukiwarkę w błąd.
         */
        $organizationTypes = ['LocalBusiness', 'Store', 'Restaurant', 'MedicalBusiness', 'AutomotiveBusiness', 'ProfessionalService', 'LodgingBusiness'];
        $ownLocations      = in_array($schemaType, $organizationTypes, true) && (string) $params->get('schemaownlocations', '0') === '1';
        $organizationId    = $siteRootUrl . '/#organization';

        if ($ownLocations) {
            $schemaGraph[] = [
                '@type' => 'Organization',
                '@id'   => $organizationId,
                'name'  => (string) $this->app->get('sitename'),
                'url'   => $siteRootUrl . '/',
            ];
        }

        // Jedna reguła w wierszu; przecinek jest częścią składni Schema.org
        // ("Mo,Tu 09:00-12:00"), więc nie może służyć za separator.
        $linesToArray = static function ($value) {
            return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $value))));
        };

        /*
         * Zapis "Mo-Fr 09:00-17:00" rozwijany jest na OpeningHoursSpecification.
         * Ta właściwość jest zdefiniowana dla Place, więc obsługuje wszystkie typy
         * oferowane w module — w odróżnieniu od tekstowego openingHours, które
         * należy wyłącznie do LocalBusiness i CivicStructure.
         */
        $weekDays  = ['Mo' => 'Monday', 'Tu' => 'Tuesday', 'We' => 'Wednesday', 'Th' => 'Thursday', 'Fr' => 'Friday', 'Sa' => 'Saturday', 'Su' => 'Sunday'];
        $weekOrder = array_keys($weekDays);

        $parseOpeningHours = static function ($value) use ($linesToArray, $weekDays, $weekOrder) {
            $specifications = [];

            foreach ($linesToArray($value) as $line) {
                if (!preg_match('/^([A-Za-z]{2}(?:\s*[-,]\s*[A-Za-z]{2})*)\s+(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', trim($line), $matches)) {
                    // Wiersz w nieznanym formacie jest pomijany; lepiej stracić godziny
                    // niż wypuścić do wyszukiwarki dane, których nie da się zinterpretować.
                    continue;
                }

                $days = [];

                foreach (explode(',', $matches[1]) as $token) {
                    $token = trim($token);

                    if (strpos($token, '-') === false) {
                        $key = ucfirst(strtolower($token));

                        if (isset($weekDays[$key])) {
                            $days[] = $weekDays[$key];
                        }

                        continue;
                    }

                    [$from, $to] = array_map(static fn($d) => ucfirst(strtolower(trim($d))), explode('-', $token, 2));
                    $fromIndex   = array_search($from, $weekOrder, true);
                    $toIndex     = array_search($to, $weekOrder, true);

                    if ($fromIndex === false || $toIndex === false) {
                        continue;
                    }

                    // Modulo obsługuje zakresy przechodzące przez niedzielę, np. "We-Mo".
                    for ($step = 0; $step < count($weekOrder); $step++) {
                        $index  = ($fromIndex + $step) % count($weekOrder);
                        $days[] = $weekDays[$weekOrder[$index]];

                        if ($index === $toIndex) {
                            break;
                        }
                    }
                }

                $days = array_values(array_unique($days));

                if (!$days) {
                    continue;
                }

                $specifications[] = [
                    '@type'     => 'OpeningHoursSpecification',
                    'dayOfWeek' => count($days) === 1 ? $days[0] : $days,
                    'opens'     => sprintf('%02d:%02d', (int) $matches[2], (int) $matches[3]),
                    'closes'    => sprintf('%02d:%02d', (int) $matches[4], (int) $matches[5]),
                ];
            }

            return $specifications;
        };

        // Pola typu accessiblemedia trzymają ścieżkę z doklejonym fragmentem
        // "#joomlaImage://...". W adresie w danych strukturalnych nie ma on czego szukać.
        $absoluteImageUrl = static function ($media) use ($siteRootUrl) {
            $file = is_object($media) && !empty($media->imagefile) ? (string) $media->imagefile : '';

            if ($file === '') {
                return '';
            }

            $file = explode('#', $file)[0];

            return $file === '' ? '' : $siteRootUrl . '/' . ltrim($file, '/');
        };

        foreach ($validPoints as $index => $point) {
            $pointUrl = trim((string) ($point->pointurl ?? ''));

            /*
             * @id musi być stabilne między odsłonami. Adres podstrony punktu jest
             * najlepszym kandydatem; gdy go nie ma, zostaje identyfikator złożony
             * z numeru modułu i pozycji, dzięki czemu dwie mapy na jednej stronie
             * nie wygenerują tych samych identyfikatorów.
             */
            $entityId = $pointUrl !== ''
                ? rtrim($pointUrl, '/') . '#location'
                : $siteRootUrl . '/#pposmap-' . $moduleId . '-location-' . ($index + 1);

            $entity = [
                '@type' => $schemaType,
                '@id'   => $entityId,
                'name'  => (string) ($point->geotitle ?? ''),
            ];

            if ($ownLocations) {
                $entity['parentOrganization'] = ['@id' => $organizationId];
            }

            $addressParts = [];

            foreach (
                [
                    'streetAddress'   => 'streetaddress',
                    'postalCode'      => 'postalcode',
                    'addressLocality' => 'addresslocality',
                ] as $schemaKey => $field
            ) {
                $value = trim((string) ($point->$field ?? ''));

                if ($value !== '') {
                    $addressParts[$schemaKey] = $value;
                }
            }

            if ($addressCountry !== '') {
                $addressParts['addressCountry'] = $addressCountry;
            }

            if ($addressParts) {
                $entity['address'] = array_merge(['@type' => 'PostalAddress'], $addressParts);
            }

            $entity['geo'] = [
                '@type'     => 'GeoCoordinates',
                'latitude'  => (float) $point->latitudemapbox,
                'longitude' => (float) $point->longitudemapbox,
            ];

            // Dane strukturalne opisują treść, nie jej wygląd — znaczniki tu nie należą.
            $description = trim(strip_tags((string) ($point->geodescription ?? '')));

            if ($description !== '') {
                $entity['description'] = $description;
            }

            if (!empty($point->telephonevalue)) {
                $entity['telephone'] = (string) $point->telephonevalue;
            }

            $image = $absoluteImageUrl($point->popupimage ?? null);

            if ($image !== '') {
                $entity['image'] = $image;
            }

            if ($pointUrl !== '') {
                $entity['url'] = $pointUrl;
            }

            $openingHours = $parseOpeningHours($point->schemaopeninghours ?? '');

            if ($openingHours) {
                $entity['openingHoursSpecification'] = count($openingHours) === 1 ? $openingHours[0] : $openingHours;
            }

            $sameAs = $linesToArray($point->sameas ?? '');

            if ($sameAs) {
                $entity['sameAs'] = count($sameAs) === 1 ? $sameAs[0] : $sameAs;
            }

            $schemaGraph[] = $entity;
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
<?php if ($addSchema && $schemaGraph) : ?>
<script type="application/ld+json"><?php echo json_encode([
    '@context' => 'https://schema.org',
    '@graph'   => $schemaGraph,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<?php endif; ?>
<div class="pposmap-container table-pposmap" data-pposmap-id="<?php echo $moduleId; ?>"<?php echo $wrapperStyleAttr; ?>>
    <?php if ($pointslistmapbox && $validPoints) : ?>
    <div class="pposmap-list">
        <?php foreach ($validPoints as $index => $point) : ?>
        <div class="pposmap-list-item">
            <h3 class="pposmap-list-item-title"><?php echo htmlspecialchars((string) ($point->geotitle ?? ''), ENT_QUOTES, 'UTF-8'); ?></h3>
            <div class="pposmap-list-item-row">
                <?php
                    /*
                     * Na liście idzie czysty tekst. Opis wolno formatować, ale jest tu
                     * skracany po słowach, więc znacznik zostałby przecięty w połowie
                     * i pogrubienie rozlałoby się na resztę listy.
                     */
                    $listDescription = $limitString(strip_tags((string) ($point->geodescription ?? '')), 9);
                ?>
                <p class="pposmap-list-item-desc"><?php echo htmlspecialchars($listDescription, ENT_QUOTES, 'UTF-8'); ?></p>
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