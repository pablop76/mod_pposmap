<?php

    /**
     * @package     Joomla.Site
     * @subpackage  mod_pposmap
     *
     * @copyright   (C) 2024 pablop76, Inc. <https://web-service.com.pl>
     * @license     GNU General Public License version 2 or later; see LICENSE.txt
     */

    defined('_JEXEC') or die;
    use Joomla\CMS\Filter\InputFilter;
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
     * ---------------------------------------------------------------------
     * GeoJSON
     * ---------------------------------------------------------------------
     * Plik jest parsowany po stronie PHP, a nie w przeglądarce, bo punkty z niego
     * mają trafiać także do listy pod mapą i do danych strukturalnych — a te
     * powstają tutaj. Punkty (Point/MultiPoint) są normalizowane do dokładnie tej
     * samej struktury co wiersze subformu i DOPISYWANE NA KOŃCU $validPoints.
     * Dzięki temu lista, blok Schema.org i tablica features w custom.js indeksują
     * się tak samo, bez żadnej dodatkowej logiki po stronie JS.
     *
     * Geometrie liniowe i powierzchniowe nie mają czego szukać na liście punktów,
     * więc idą osobnym kanałem ($shapeFeatures) prosto do warstwy mapy.
     */
    $geojsonSource   = (string) $params->get('geojsonsource', '0');
    $geojsonSchema   = (string) $params->get('geojsonschema', '1') === '1';
    $shapeFeatures   = [];
    $geojsonOffset   = count($validPoints);
    $geojsonWarning  = '';

    if ($geojsonSource === '1' || $geojsonSource === '2') {
        $geojsonRaw = '';

        if ($geojsonSource === '1') {
            $geojsonRaw = (string) $params->get('geojsondata', '');
        } else {
            /*
             * Tylko pliki spod katalogu Joomli i tylko z rozszerzeniem .json/.geojson.
             * realpath() rozwija ".." zanim porównamy ścieżki, więc wpisanie
             * "../../etc/passwd" nie wychodzi poza witrynę. Świadomie NIE pobieramy
             * plików z adresów zewnętrznych: przy parsowaniu serwerowym oznaczałoby to
             * zapytanie HTTP przy każdym wyświetleniu strony.
             */
            $relative = ltrim(str_replace('\\', '/', trim((string) $params->get('geojsonfile', ''))), '/');
            $rootReal = realpath(JPATH_ROOT);

            if ($relative !== '' && $rootReal !== false && preg_match('/\.(geo)?json$/i', $relative)) {
                $fullPath = realpath(JPATH_ROOT . '/' . $relative);

                if ($fullPath !== false
                    && strpos($fullPath, $rootReal . DIRECTORY_SEPARATOR) === 0
                    && is_file($fullPath)
                    && is_readable($fullPath)
                ) {
                    $geojsonRaw = (string) file_get_contents($fullPath);
                } else {
                    $geojsonWarning = 'mod_pposmap: nie można odczytać pliku GeoJSON "' . $relative . '".';
                }
            } elseif ($relative !== '') {
                $geojsonWarning = 'mod_pposmap: ścieżka pliku GeoJSON musi kończyć się na .json albo .geojson.';
            }
        }

        $geojsonRaw = trim($geojsonRaw);

        if ($geojsonRaw !== '') {
            $decoded = json_decode($geojsonRaw);

            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                $geojsonWarning = 'mod_pposmap: GeoJSON jest nieprawidłowy (' . json_last_error_msg() . ').';
                $decoded        = null;
            }

            if (is_object($decoded)) {
                // FeatureCollection → Feature → geometria. GeometryCollection jest rozbijana,
                // a właściwości nadrzędnego Feature powielane na każdą geometrię składową.
                $flatten = static function ($node) use (&$flatten) {
                    $out  = [];
                    $type = is_object($node) && isset($node->type) ? (string) $node->type : '';

                    if ($type === 'FeatureCollection') {
                        foreach ((array) ($node->features ?? []) as $child) {
                            $out = array_merge($out, $flatten($child));
                        }

                        return $out;
                    }

                    if ($type === 'Feature') {
                        $geometry = $node->geometry ?? null;

                        if (!is_object($geometry)) {
                            return $out;
                        }

                        $properties = is_object($node->properties ?? null) ? $node->properties : new \stdClass();

                        if ((string) ($geometry->type ?? '') === 'GeometryCollection') {
                            foreach ((array) ($geometry->geometries ?? []) as $child) {
                                if (is_object($child)) {
                                    $out[] = ['geometry' => $child, 'properties' => $properties];
                                }
                            }

                            return $out;
                        }

                        $out[] = ['geometry' => $geometry, 'properties' => $properties];

                        return $out;
                    }

                    if ($type === 'GeometryCollection') {
                        foreach ((array) ($node->geometries ?? []) as $child) {
                            $out = array_merge($out, $flatten($child));
                        }

                        return $out;
                    }

                    if (in_array($type, ['Point', 'MultiPoint', 'LineString', 'MultiLineString', 'Polygon', 'MultiPolygon'], true)) {
                        $out[] = ['geometry' => $node, 'properties' => new \stdClass()];
                    }

                    return $out;
                };

                // Nazwy właściwości są konfigurowalne, bo każde źródło nazywa je inaczej:
                // Google My Maps daje "name"/"description", eksport z OpenStreetMap "addr:street" itd.
                $map = [];

                foreach (['title', 'description', 'telephone', 'openinghours', 'image', 'group', 'street', 'postalcode', 'locality', 'url'] as $key) {
                    $map[$key] = trim((string) $params->get('geojsonprop_' . $key, ''));
                }

                $readProp = static function ($properties, $key) {
                    if ($key === '' || !is_object($properties) || !isset($properties->$key)) {
                        return '';
                    }

                    $value = $properties->$key;

                    return is_scalar($value) ? trim((string) $value) : '';
                };

                /*
                 * Treść z GeoJSON-a nie przeszła przez filtry JForm, bo pole źródłowe
                 * musi być raw (inaczej JSON się rozsypuje). Filtrujemy więc ręcznie,
                 * dokładnie tak, jak Joomla filtruje odpowiedniki z subformu: tytuł
                 * jak "string", opis jak "safehtml" — opis trafia do dymka jako HTML.
                 */
                $safeHtml = InputFilter::getInstance([], [], 1, 1);

                // Klucze simplestyle-spec (geojson.io, Google My Maps, GitHub) —
                // przepuszczane bez zmian, żeby styl pojedynczego obiektu mógł nadpisać
                // ustawienia modułu. Wartość nienumeryczna/niebędąca tekstem jest pomijana.
                $styleKeys = ['stroke', 'stroke-width', 'stroke-opacity', 'fill', 'fill-opacity'];

                $appendPoint = static function ($lng, $lat, $properties) use (&$validPoints, $map, $readProp, $safeHtml) {
                    if (!is_numeric($lng) || !is_numeric($lat)) {
                        return;
                    }

                    $lng = (float) $lng;
                    $lat = (float) $lat;

                    // Zamienione miejscami współrzędne to najczęstszy błąd w GeoJSON-ie.
                    // Poza zakresem punkt i tak nie istnieje, więc lepiej go pominąć
                    // niż postawić pinezkę w losowym miejscu.
                    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                        return;
                    }

                    $image = $readProp($properties, $map['image']);

                    $validPoints[] = (object) [
                        'longitudemapbox'    => $lng,
                        'latitudemapbox'     => $lat,
                        'geotitle'           => strip_tags($readProp($properties, $map['title'])),
                        'geodescription'     => $safeHtml->clean($readProp($properties, $map['description']), 'html'),
                        'openinghours'       => $readProp($properties, $map['openinghours']),
                        'telephonevalue'     => $readProp($properties, $map['telephone']),
                        'popupimage'         => $image !== '' ? (object) ['imagefile' => $image] : null,
                        'pointmarker'        => null,
                        'layergroup'         => $readProp($properties, $map['group']),
                        'streetaddress'      => $readProp($properties, $map['street']),
                        'postalcode'         => $readProp($properties, $map['postalcode']),
                        'addresslocality'    => $readProp($properties, $map['locality']),
                        'schemaopeninghours' => '',
                        'pointurl'           => $readProp($properties, $map['url']),
                        'sameas'             => '',
                    ];
                };

                foreach ($flatten($decoded) as $entry) {
                    $geometry    = $entry['geometry'];
                    $properties  = $entry['properties'];
                    $type        = (string) ($geometry->type ?? '');
                    $coordinates = $geometry->coordinates ?? null;

                    if ($type === 'Point') {
                        if (is_array($coordinates) && count($coordinates) >= 2) {
                            $appendPoint($coordinates[0], $coordinates[1], $properties);
                        }

                        continue;
                    }

                    if ($type === 'MultiPoint') {
                        foreach ((array) $coordinates as $pair) {
                            if (is_array($pair) && count($pair) >= 2) {
                                $appendPoint($pair[0], $pair[1], $properties);
                            }
                        }

                        continue;
                    }

                    if ($coordinates === null) {
                        continue;
                    }

                    $shapeProperties = [
                        'title'       => strip_tags($readProp($properties, $map['title'])),
                        'description' => $safeHtml->clean($readProp($properties, $map['description']), 'html'),
                    ];

                    foreach ($styleKeys as $styleKey) {
                        $value = $readProp($properties, $styleKey);

                        if ($value !== '') {
                            // stroke-width i *-opacity muszą dojść do Mapboksa jako liczby,
                            // bo wyrażenie coalesce w warstwie oczekuje typu liczbowego.
                            $shapeProperties[$styleKey] = is_numeric($value) ? (float) $value : $value;
                        }
                    }

                    $shapeFeatures[] = [
                        'type'       => 'Feature',
                        'geometry'   => $geometry,
                        'properties' => $shapeProperties,
                    ];
                }
            }
        }
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
            /*
             * Punkty z GeoJSON-a leżą na końcu tablicy, od $geojsonOffset w górę.
             * Wpis bez nazwy pomijamy nawet przy włączonej opcji: węzeł niosący same
             * współrzędne nie mówi wyszukiwarce nic, a rozdyma blok danych strukturalnych.
             * Typowy plik z obrysami albo trasami nie ma właściwości z nazwą i wtedy
             * do JSON-LD nie trafia z niego nic — i tak ma być.
             */
            if ($index >= $geojsonOffset
                && (!$geojsonSchema || trim((string) ($point->geotitle ?? '')) === '')
            ) {
                continue;
            }

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

        /*
         * Same geometrie liniowe i powierzchniowe — punkty z GeoJSON-a poszły już
         * wyżej, w listofpoints. Pusta tablica features oznacza dla custom.js
         * "nie ma czego rysować" i nie powoduje dodania warstw.
         */
        'geojsonshapes'   => ['type' => 'FeatureCollection', 'features' => $shapeFeatures],
        'geojsonstyle'    => [
            'stroke'        => trim((string) $params->get('geojsonstroke', '#3388ff')),
            'strokeWidth'   => (float) $params->get('geojsonstrokewidth', 3),
            'strokeOpacity' => (float) $params->get('geojsonstrokeopacity', 1),
            'fill'          => trim((string) $params->get('geojsonfill', '#3388ff')),
            'fillOpacity'   => (float) $params->get('geojsonfillopacity', 0.2),
        ],
        'geojsonfit'      => (string) $params->get('geojsonfit', '1'),
        'geojsonwarning'  => $geojsonWarning,
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
        <?php
            /*
             * Punkt bez tytułu i bez opisu dałby pustą pozycję z samym przyciskiem.
             * Zdarza się to głównie przy plikach GeoJSON, w których obiekty nie mają
             * żadnych właściwości opisowych. Pominięcie wiersza jest bezpieczne, bo
             * bindListClick w custom.js czyta indeks z atrybutu data-index i sięga po
             * features[index] — dziury w numeracji niczego nie psują.
             */
            if (trim((string) ($point->geotitle ?? '')) === ''
                && trim(strip_tags((string) ($point->geodescription ?? ''))) === ''
            ) {
                continue;
            }
        ?>
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