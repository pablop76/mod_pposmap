# mod_pposmap

Moduł site (frontend) dla Joomla — wyświetla mapę (Mapbox GL JS lub Leaflet/OSM) z punktami (markerami) i opcjonalną listą punktów.

Autor: pablop76 ([web-service.com.pl](https://web-service.com.pl/))

## Stack

- Joomla 4/5 (moduł typu `module`, `client="site"`), min. Joomla 4.4.0, min. PHP 7.4
- Namespace `Pablop76\Module\Pposmap` (PSR-4, folder `src/`)
- Web Asset Manager (`media/joomla.asset.json`) — rejestruje skrypty/style (mapbox.js, custom.js, style.css, mapbox.css) + zewnętrzne CDN (Leaflet z unpkg)
- Vanilla JS w `media/js/` (bez frameworka, bez build stepu)
- UIkit klasy w szablonie (`tmpl/default.php`) — projekt korzysta z UIkit w Joomla/Gantry

## Struktura

- `mod_pposmap.xml` — manifest instalacyjny + definicja pól parametrów modułu (backend config)
- `script.php` — hooki instalacyjne (install/update/uninstall/preflight/postflight)
- `services/provider.php` — DI container (Dispatcher, Helper, Module service provider)
- `src/Dispatcher/Dispatcher.php` — wejście: ładuje język, params, renderuje `tmpl/default.php`
- `src/Helper/PposmapHelper.php` — helpery (np. nazwa zalogowanego użytkownika)
- `tmpl/default.php` — layout frontendowy: rejestruje web assets, przekazuje params do JS przez `addScriptOptions('mod_pposmap.vars', ...)`, renderuje listę punktów (desktop only, `uk-visible@m`) + `<div id="map">`
- `media/js/mapbox.js`, `media/js/custom.js` — logika inicjalizacji mapy (Mapbox / Leaflet), popupy, telefon→`tel:` link, obrazki w popupach
- `media/css/style.css`, `media/css/mapbox.css` — style mapy i listy punktów
- `language/{en-GB,pl-PL}/mod_pposmap*.ini` — stringi (etykiety pól, komunikaty)

## Konwencje / jak to działa

- Wysokość mapy sterowana przez CSS custom properties `--pposmap-height` / `--pposmap-height-mobile`, ustawiane inline w `style` wrappera na podstawie parametrów `mapheight` / `mapheight_mobile` (fallback do bezpiecznych wartości w CSS gdy puste)
- Dostawca mapy przełączany parametrem `mapboxorleaflet` (`0` = Mapbox, `1` = Leaflet) — warunkowo ładowane inne skrypty/style
- Punkty definiowane w JForm `subform` (`listofpoints`) w XML: lat/lng, tytuł, opis, godziny otwarcia, telefon, obrazek (`accessiblemedia`), opcjonalna grupa (Leaflet layer groups)
- Wszystkie parametry trafiają do frontu przez `Joomla.getOptions('mod_pposmap.vars')` w `custom.js` — nowy parametr w XML zawsze wymaga dodania go też w `tmpl/default.php` (`addScriptOptions`) i odczytania w JS

## Zasady pracy

- Dokumentacja referencyjna:
  - Joomla: [manual.joomla.org/docs](https://manual.joomla.org/docs/)
  - Mapbox GL JS: [mapbox.com](https://www.mapbox.com/)
  - Leaflet: [leafletjs.com](https://leafletjs.com/)
- Zero własnych/autorskich konwencji — wszystko zgodnie ze standardami Joomla (struktura modułu, JForm, Web Asset Manager, DI/service provider, styl kodu).
- Jeśli czegoś nie wiadomo (API, dobra praktyka, sposób działania czegoś w Joomla/Mapbox/Leaflet) — pytać użytkownika zamiast zgadywać. Użytkownik sam to sprawdzi/wyszuka.

## Aktualizacje (update server przez GitHub)

`mod_pposmap.xml` → `<updateservers>` wskazuje na manifest hostowany w tym repo na GitHubie:
`https://raw.githubusercontent.com/pablop76/mod_pposmap/release/updates.xml`

Schemat zgodny z dokumentacją Joomla ([manual.joomla.org — Update Servers](https://manual.joomla.org/docs/4.4/building-extensions/install-update/update-server/)).

Gałąź `release` jest **oddzielona od `main`** — main to bieżąca praca/rozwój, `release` trzyma tylko przetestowany, opublikowany stan (`updates.xml` + odnośniki do paczek). Nic nie trafia na `release` automatycznie.

### Workflow wydania

1. Praca i testy na `main`.
2. Po przetestowaniu: bump wersji w `mod_pposmap.xml` (`<version>`) na `main`, commit.
3. Zbudować paczkę instalacyjną modułu (zip zgodny ze strukturą z `<files>`/`<media>` w `mod_pposmap.xml`).
4. Utworzyć **GitHub Release** z tagiem `vX.Y.Z` i załączyć zip jako asset — URL do niego trafia do `downloadurl`.
5. Na gałęzi `release`: zaktualizować `updates.xml` (nowa `<version>`, `<downloadurl>` wskazujący na asset z Release), commit + push na `release`.
6. Joomla (przycisk „Znajdź aktualizacje” w Menedżerze rozszerzeń) odczyta nowy `updates.xml` i zaproponuje update.
