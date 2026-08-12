if (!window.Joomla) {
  throw new Error("Joomla API was not properly initialised");
}

function getLeaflet() {
  // Uwaga: celowo NIE wywołujemy L.noConflict() — wtyczka leaflet.markercluster
  // wewnętrznie odwołuje się do globalnego window.L (np. w iconCreateFunction),
  // więc po noConflict() rzuca "Cannot read properties of undefined (reading 'MarkerClusterGroup')".
  return window.L;
}

function toNumber(value, fallback) {
  // Uwaga: Number("") i Number(null) dają 0, nie NaN. Bez tych dwóch warunków
  // pusta współrzędna stawiała marker na 0,0 (Zatoka Gwinejska), a pusty zoom
  // dawał zoom 0 zamiast wartości domyślnej.
  if (value === null || value === undefined) return fallback;
  if (typeof value === "string" && value.trim() === "") return fallback;

  const numberValue = Number(value);
  return Number.isFinite(numberValue) ? numberValue : fallback;
}

function asString(value) {
  return value == null ? "" : String(value);
}

/*
 * Opis punktu jest celowo wstawiany jako HTML (pole ma w Joomli filtr safehtml,
 * ktory odsiewa script i atrybuty zdarzen). Wszystko pozostale, co pochodzi
 * z ustawien, musi przejsc przez to escapowanie — zwlaszcza tytul, ktory trafia
 * takze do atrybutu alt obrazka i aria-label.
 */
function escapeHtml(value) {
  return asString(value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

const DEFAULT_TILE_URL = "https://tile.openstreetmap.org/{z}/{x}/{y}.png";
const DEFAULT_TILE_ATTRIBUTION =
  '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

function buildTileLayer(L, options) {
  const customUrl = asString(options.tileurl).trim();
  const customAttribution = asString(options.tileattribution).trim();

  const url = customUrl || DEFAULT_TILE_URL;
  const attribution = customAttribution || DEFAULT_TILE_ATTRIBUTION;

  if (customUrl && !(/\{z\}/.test(url) && /\{x\}/.test(url) && /\{y\}/.test(url))) {
    console.warn(
      "mod_pposmap: adres kafelków nie zawiera pól {z}/{x}/{y} — Leaflet nie pobierze żadnego kafelka."
    );
  }

  // Atrybucja jest warunkiem licencji u większości dostawców, a domyślna
  // (OpenStreetMap) przestaje być prawdziwa w momencie podmiany serwera kafelków.
  if (customUrl && !customAttribution) {
    console.warn(
      "mod_pposmap: ustawiono własny serwer kafelków, ale nie ustawiono atrybucji. " +
        "Mapa pokazuje atrybucję OpenStreetMap, co zwykle narusza licencję dostawcy kafelków."
    );
  }

  const layerOptions = { attribution };
  const minZoom = toNumber(options.tileminzoom, null);
  const maxZoom = toNumber(options.tilemaxzoom, null);

  if (minZoom !== null) {
    layerOptions.minZoom = minZoom;
  }

  if (maxZoom !== null) {
    layerOptions.maxZoom = maxZoom;
  }

  return L.tileLayer(url, layerOptions);
}

function buildTelephoneLink(phoneValue) {
  const raw = asString(phoneValue).trim();
  if (!raw) return "";

  // Do tel: bierzemy cyfry oraz ewentualny wiodący +
  const normalized = raw.startsWith("+")
    ? "+" + raw.slice(1).replace(/\D+/g, "")
    : raw.replace(/\D+/g, "");

  if (!normalized || normalized === "+") return "";

  return `<a href="tel:${encodeURIComponent(normalized)}">${escapeHtml(raw)}</a>`;
}

/*
 * Pola accessiblemedia trzymają ścieżkę względną wobec katalogu Joomli, ale
 * właściwość z GeoJSON-a bywa pełnym adresem cudzego serwera albo data: URI.
 * Doklejenie siteRoot do takiej wartości dałoby adres, którego nikt nie obsłuży.
 */
function isAbsoluteUrl(value) {
  return /^(?:[a-z][a-z0-9+.-]*:|\/\/)/i.test(value);
}

function withSiteRoot(file, siteRoot) {
  const value = asString(file).trim();
  if (!value) return "";
  return isAbsoluteUrl(value) ? value : `${siteRoot || ""}/${value.replace(/^\/+/, "")}`;
}

function buildImageHtml(imageObj, title, siteRoot) {
  const file = imageObj && imageObj.imagefile ? String(imageObj.imagefile) : "";
  if (!file) return "";
  return `<img src="${escapeHtml(withSiteRoot(file, siteRoot))}" alt="${escapeHtml(title)}" />`;
}

const DEFAULT_MARKER_WIDTH = 50;
const DEFAULT_MARKER_HEIGHT = 64;
const DEFAULT_MARKER_ANCHOR = "bottom";

// Ułamkowe położenie punktu zaczepienia w prostokącie ikony (0,0 = lewy górny róg).
// Nazwy i znaczenie jak w Mapbox GL (Marker.anchor / icon-anchor); Leaflet dostaje
// z tego przeliczone piksele. Tabela jest szersza niż lista w panelu, bo Mapbox
// przyjmuje też narożniki, a kod nie ma powodu ich odrzucać.
const MARKER_ANCHOR_OFFSETS = {
  center: [0.5, 0.5],
  top: [0.5, 0],
  bottom: [0.5, 1],
  left: [0, 0.5],
  right: [1, 0.5],
  "top-left": [0, 0],
  "top-right": [1, 0],
  "bottom-left": [0, 1],
  "bottom-right": [1, 1],
};

function resolveMarkerAnchor(value) {
  const key = asString(value).trim().toLowerCase();
  return MARKER_ANCHOR_OFFSETS[key] ? key : DEFAULT_MARKER_ANCHOR;
}

function resolveMarkerSize(options) {
  return {
    width: Math.max(1, toNumber(options.markerwidth, DEFAULT_MARKER_WIDTH)),
    height: Math.max(1, toNumber(options.markerheight, DEFAULT_MARKER_HEIGHT)),
  };
}

function mediaFile(value) {
  return value && value.imagefile ? String(value.imagefile) : "";
}

// Kolejność: własna pinezka punktu, potem domyślna z ustawień modułu.
// Pusty wynik oznacza znacznik wbudowany w bibliotekę mapy.
function resolveMarkerUrl(pointMarker, globalMarker, siteRoot) {
  const file = mediaFile(pointMarker) || mediaFile(globalMarker);
  return file ? withSiteRoot(file, siteRoot) : "";
}

// Jeden budowniczy elementu dla obu dostawców: Mapbox dostaje go jako element
// markera, Leaflet jako zawartość divIcon. Dzięki temu klasa .marker w CSS jest
// jedynym miejscem, które decyduje o wyglądzie pinezki.
function createMarkerElement(iconUrl, title, size) {
  const el = document.createElement("div");
  el.className = "pposmap-marker";
  el.setAttribute("role", "img");
  el.setAttribute("aria-label", asString(title));
  el.style.setProperty("--pposmap-marker-width", `${size.width}px`);
  el.style.setProperty("--pposmap-marker-height", `${size.height}px`);
  el.style.backgroundImage = `url("${iconUrl}")`;
  return el;
}

function buildLeafletIcon(L, iconUrl, title, size, anchorKey) {
  const [offsetX, offsetY] = MARKER_ANCHOR_OFFSETS[anchorKey];
  const anchorX = Math.round(size.width * offsetX);
  const anchorY = Math.round(size.height * offsetY);

  return L.divIcon({
    className: "pposmap-leaflet-marker",
    html: createMarkerElement(iconUrl, title, size),
    iconSize: [size.width, size.height],
    iconAnchor: [anchorX, anchorY],
    // Liczone względem iconAnchor, więc -anchorY wypada przy górnej krawędzi
    // ikony: dymek otwiera się nad pinezką, a nie na niej.
    popupAnchor: [0, -anchorY],
  });
}

function buildPopupHtml({ title, description, popupimage, openinghours, telephonevalue }, variant, siteRoot) {
  const imageHtml = buildImageHtml(popupimage, title, siteRoot);
  const opening = asString(openinghours).trim();
  const telLink = buildTelephoneLink(telephonevalue);
  const telHtml = telLink ? `<p>tel:${telLink}</p>` : "";
  // Godziny to zwykły tekst (pole ma filtr string), ale każdy wiersz ma być osobną linią.
  const openingHtml = opening ? `<p>${escapeHtml(opening).replace(/\r?\n/g, "<br>")}</p>` : "";
  const titleClass = variant === "mapbox" ? ' class="mapbox-popup-title"' : "";
  const descriptionClass = variant === "mapbox" ? ' class="mapbox-popup-description"' : "";

  // Opis zostaje surowy — to jedyne pole, w którym formatowanie jest zamierzone.
  return `${imageHtml}<h3${titleClass}>${escapeHtml(title)}</h3><p${descriptionClass}>${asString(description)}</p>${openingHtml}${telHtml}`;
}

function buildFeatures(originalData) {
  const features = [];

  if (!originalData) {
    return features;
  }

  for (const key in originalData) {
    const point = originalData[key];
    if (!point) continue;

    const lng = toNumber(point.longitudemapbox, NaN);
    const lat = toNumber(point.latitudemapbox, NaN);
    if (!Number.isFinite(lng) || !Number.isFinite(lat)) continue;

    features.push({
      type: "Feature",
      geometry: {
        type: "Point",
        coordinates: [lng, lat],
      },
      properties: {
        title: point.geotitle,
        description: point.geodescription,
        popupimage: point.popupimage,
        openinghours: point.openinghours,
        telephonevalue: point.telephonevalue,
        pointmarker: point.pointmarker,
      },
      groupname: point.layergroup,
    });
  }

  return features;
}

/*
 * Geometrie liniowe i powierzchniowe z GeoJSON-a. Punkty tu nie trafiają — te
 * przechodzą przez PHP do listofpoints, żeby lista pod mapą i dane strukturalne
 * indeksowały się tak samo jak markery.
 */
function getShapeFeatures(options) {
  const shapes = options.geojsonshapes;
  return shapes && Array.isArray(shapes.features) ? shapes.features : [];
}

const DEFAULT_SHAPE_STYLE = {
  stroke: "#3388ff",
  strokeWidth: 3,
  strokeOpacity: 1,
  fill: "#3388ff",
  fillOpacity: 0.2,
};

function resolveShapeStyle(options) {
  const style = options.geojsonstyle || {};

  return {
    stroke: asString(style.stroke).trim() || DEFAULT_SHAPE_STYLE.stroke,
    strokeWidth: toNumber(style.strokeWidth, DEFAULT_SHAPE_STYLE.strokeWidth),
    strokeOpacity: toNumber(style.strokeOpacity, DEFAULT_SHAPE_STYLE.strokeOpacity),
    fill: asString(style.fill).trim() || DEFAULT_SHAPE_STYLE.fill,
    fillOpacity: toNumber(style.fillOpacity, DEFAULT_SHAPE_STYLE.fillOpacity),
  };
}

// Dymek obszaru ma tylko tytuł i opis — reszta pól punktu (godziny, telefon,
// zdjęcie) nie ma sensu dla granicy gminy czy przebiegu trasy.
// Opis jest wstawiany jako HTML, bo przeszedł przez filtr safehtml w PHP.
function buildShapePopupHtml(properties) {
  const props = properties || {};
  const title = asString(props.title).trim();
  const description = asString(props.description).trim();

  if (!title && !description) return "";

  const titleHtml = title ? `<h3>${escapeHtml(title)}</h3>` : "";
  const descriptionHtml = description ? `<p>${description}</p>` : "";

  return `${titleHtml}${descriptionHtml}`;
}

/*
 * Zagnieżdżenie tablicy coordinates zależy od typu geometrii: Point ma [lng, lat],
 * LineString tablicę par, Polygon tablicę pierścieni, MultiPolygon jeszcze poziom
 * wyżej. Zamiast rozpisywać każdy typ osobno schodzimy rekurencyjnie do pierwszej
 * pary liczb.
 */
function extendBounds(bounds, coordinates) {
  if (!Array.isArray(coordinates)) return;

  if (typeof coordinates[0] === "number" && typeof coordinates[1] === "number") {
    const [lng, lat] = coordinates;
    if (!Number.isFinite(lng) || !Number.isFinite(lat)) return;

    bounds.minLng = Math.min(bounds.minLng, lng);
    bounds.maxLng = Math.max(bounds.maxLng, lng);
    bounds.minLat = Math.min(bounds.minLat, lat);
    bounds.maxLat = Math.max(bounds.maxLat, lat);
    return;
  }

  for (const item of coordinates) {
    extendBounds(bounds, item);
  }
}

function collectBounds(features, shapes) {
  const bounds = { minLng: Infinity, minLat: Infinity, maxLng: -Infinity, maxLat: -Infinity };

  for (const feature of features) {
    extendBounds(bounds, feature.geometry.coordinates);
  }

  for (const feature of shapes) {
    extendBounds(bounds, feature.geometry && feature.geometry.coordinates);
  }

  if (!Number.isFinite(bounds.minLng) || !Number.isFinite(bounds.minLat)) {
    return null;
  }

  // Jeden punkt albo pionowa/pozioma linia: fitBounds na zerowym prostokącie
  // przybliża mapę do maksimum, więc taki widok zostawiamy zoomowi z ustawień.
  bounds.degenerate = bounds.minLng === bounds.maxLng && bounds.minLat === bounds.maxLat;

  return bounds;
}

// Środek mapy: pierwszy punkt jak dotąd, a przy pliku z samymi obszarami
// (mapa bez ani jednego punktu) środek prostokąta obejmującego geometrie.
function resolveCenter(features, bounds) {
  if (features.length) {
    return features[0].geometry.coordinates;
  }

  if (bounds) {
    return [(bounds.minLng + bounds.maxLng) / 2, (bounds.minLat + bounds.maxLat) / 2];
  }

  return [0, 0];
}

function shouldFitBounds(options, bounds) {
  return asString(options.geojsonfit) === "1" && bounds !== null && !bounds.degenerate;
}

function bindListClick(container, { features, onSelect }) {
  if (!container) return;

  container.addEventListener("click", (event) => {
    const trigger = event.target && event.target.closest ? event.target.closest("[data-index]") : null;
    if (!trigger || !container.contains(trigger)) return;

    const index = Number(trigger.dataset.index);
    if (!Number.isInteger(index) || !features[index]) return;
    onSelect(index);
  });
}

function parseMaybeJson(value) {
  if (typeof value !== "string") return value;
  try {
    return JSON.parse(value);
  } catch (e) {
    return value;
  }
}

function addMapboxMarkers(map, features, markermapbox, siteRoot, size, anchor) {
  for (const feature of features) {
    const iconUrl = resolveMarkerUrl(feature.properties.pointmarker, markermapbox, siteRoot);
    const marker = iconUrl
      ? new mapboxgl.Marker({
          element: createMarkerElement(iconUrl, feature.properties.title, size),
          anchor,
        })
      : new mapboxgl.Marker({ anchor });

    marker
      .setLngLat(feature.geometry.coordinates)
      .setPopup(
        new mapboxgl.Popup({ offset: 25 }) // add popups
          .setHTML(buildPopupHtml(feature.properties, "mapbox", siteRoot))
      )
      .addTo(map);
  }
}

// Mapbox GL zmieniał API loadImage między wydaniami: starsze przyjmują callback,
// nowsze zwracają Promise i callback ignorują. Obsługujemy oba naraz, bo resolve()
// jest idempotentne, więc zadziała ta ścieżka, która w danej wersji faktycznie żyje.
function loadMapImage(map, url) {
  return new Promise((resolve) => {
    let result;

    try {
      result = map.loadImage(url, (error, image) => {
        resolve(error || !image ? null : image);
      });
    } catch (e) {
      resolve(null);
      return;
    }

    if (result && typeof result.then === "function") {
      result.then((value) => resolve((value && value.data) || value || null)).catch(() => resolve(null));
    }
  });
}

function addMapboxClusters(map, mapEl, features, markermapbox, siteRoot, size, anchor) {
  const sourceId = "pposmap-points";
  const clusterLayerId = "pposmap-clusters";
  const clusterCountLayerId = "pposmap-cluster-count";
  const pointLayerId = "pposmap-unclustered";
  const plainPointLayerId = "pposmap-unclustered-plain";

  /*
   * W trybie klastrowania punkty nie są elementami DOM, tylko wpisami w źródle
   * GeoJSON, więc ikona nie może być obrazkiem w CSS. Każda unikalna pinezka
   * ląduje w rejestrze obrazków mapy pod własną nazwą, a feature niesie tę nazwę
   * we właściwości "icon" — dzięki temu icon-image może być wyrażeniem
   * ["get", "icon"] i każdy punkt dostaje swoją pinezkę.
   */
  const iconNames = new Map();

  for (const feature of features) {
    const url = resolveMarkerUrl(feature.properties.pointmarker, markermapbox, siteRoot);

    if (url && !iconNames.has(url)) {
      iconNames.set(url, `pposmap-icon-${iconNames.size}`);
    }

    feature.properties.icon = url ? iconNames.get(url) : "";
  }

  // Odpowiednik background-size: contain — obrazek wpisany w prostokąt
  // bez zniekształcenia, tak samo jak w ścieżce bez klastrowania.
  const iconScale = (image) =>
    image && image.width && image.height ? Math.min(size.width / image.width, size.height / image.height) : 1;

  const setupLayers = (loaded) => {
    // Ikona, której nie udało się wczytać, zabrałaby punkt z mapy bez śladu:
    // symbol z nieznaną nazwą obrazka po prostu się nie rysuje. Takie punkty
    // wracają do warstwy z kółkami, żeby nie zniknęły.
    const failed = new Set(loaded.filter((entry) => !entry.image).map((entry) => entry.name));

    if (failed.size) {
      for (const feature of features) {
        if (failed.has(feature.properties.icon)) {
          feature.properties.icon = "";
        }
      }
    }

    const usable = loaded.filter((entry) => entry.image);

    map.addSource(sourceId, {
      type: "geojson",
      data: { type: "FeatureCollection", features },
      cluster: true,
      clusterMaxZoom: 14,
      clusterRadius: 50,
    });

    map.addLayer({
      id: clusterLayerId,
      type: "circle",
      source: sourceId,
      filter: ["has", "point_count"],
      paint: {
        "circle-color": ["step", ["get", "point_count"], "#51bbd6", 10, "#f1f075", 30, "#f28cb1"],
        "circle-radius": ["step", ["get", "point_count"], 18, 10, 24, 30, 32],
      },
    });

    map.addLayer({
      id: clusterCountLayerId,
      type: "symbol",
      source: sourceId,
      filter: ["has", "point_count"],
      layout: {
        "text-field": ["get", "point_count_abbreviated"],
        "text-font": ["DIN Offc Pro Medium", "Arial Unicode MS Bold"],
        "text-size": 14,
      },
    });

    if (usable.length) {
      // Każda ikona ma inny rozmiar źródłowy, więc jedna stała skala by nie wystarczyła.
      let iconSize;

      if (usable.length === 1) {
        iconSize = iconScale(usable[0].image);
      } else {
        iconSize = ["match", ["get", "icon"]];

        for (const entry of usable) {
          iconSize.push(entry.name, iconScale(entry.image));
        }

        iconSize.push(1);
      }

      map.addLayer({
        id: pointLayerId,
        type: "symbol",
        source: sourceId,
        filter: ["all", ["!", ["has", "point_count"]], ["!=", ["get", "icon"], ""]],
        layout: {
          "icon-image": ["get", "icon"],
          "icon-size": iconSize,
          "icon-anchor": anchor,
          "icon-allow-overlap": true,
        },
      });
    }

    // Punkty bez własnej i bez domyślnej pinezki (albo takie, których obrazek padł).
    map.addLayer({
      id: plainPointLayerId,
      type: "circle",
      source: sourceId,
      filter: ["all", ["!", ["has", "point_count"]], ["==", ["get", "icon"], ""]],
      paint: {
        "circle-color": "#11b4da",
        "circle-radius": 8,
        "circle-stroke-width": 2,
        "circle-stroke-color": "#fff",
      },
    });

    const pointLayers = usable.length ? [pointLayerId, plainPointLayerId] : [plainPointLayerId];

    map.on("click", clusterLayerId, (event) => {
      const feature = event.features && event.features[0];
      if (!feature) return;

      const center = feature.geometry.coordinates;
      const source = map.getSource(sourceId);
      // Ta metoda ma ten sam problem z wersjami API co loadImage.
      const result = source.getClusterExpansionZoom(feature.properties.cluster_id, (error, targetZoom) => {
        if (error) return;
        map.easeTo({ center, zoom: targetZoom });
      });

      if (result && typeof result.then === "function") {
        result.then((targetZoom) => map.easeTo({ center, zoom: targetZoom })).catch(() => {});
      }
    });

    for (const layerId of pointLayers) {
      map.on("click", layerId, (event) => {
        const feature = event.features && event.features[0];
        if (!feature) return;

        const properties = { ...feature.properties, popupimage: parseMaybeJson(feature.properties.popupimage) };

        new mapboxgl.Popup({ offset: 25 })
          .setLngLat(feature.geometry.coordinates.slice())
          .setHTML(buildPopupHtml(properties, "mapbox", siteRoot))
          .addTo(map);
      });
    }

    for (const layerId of [clusterLayerId, ...pointLayers]) {
      map.on("mouseenter", layerId, () => {
        mapEl.style.cursor = "pointer";
      });
      map.on("mouseleave", layerId, () => {
        mapEl.style.cursor = "";
      });
    }
  };

  if (iconNames.size) {
    Promise.all(
      Array.from(iconNames, ([url, name]) =>
        loadMapImage(map, url).then((image) => {
          if (image && !map.hasImage(name)) {
            map.addImage(name, image);
          }

          return { name, image };
        })
      )
    ).then(setupLayers);
  } else {
    setupLayers([]);
  }
}

function addMapboxShapes(map, mapEl, shapes, style) {
  if (!shapes.length) return;

  const sourceId = "pposmap-shapes";
  const fillLayerId = "pposmap-shapes-fill";
  const lineLayerId = "pposmap-shapes-line";

  map.addSource(sourceId, {
    type: "geojson",
    data: { type: "FeatureCollection", features: shapes },
  });

  /*
   * Wyrażenie ["geometry-type"] bywa raportowane raz jako "Polygon", raz jako
   * "MultiPolygon" — zależnie od tego, czy dane przeszły przez kafelkowanie.
   * Wymieniamy więc obie formy; nadmiarowa pozycja w match nic nie kosztuje,
   * a brakująca cicho gubi całą geometrię.
   */
  map.addLayer({
    id: fillLayerId,
    type: "fill",
    source: sourceId,
    filter: ["match", ["geometry-type"], ["Polygon", "MultiPolygon"], true, false],
    paint: {
      // coalesce pozwala pojedynczemu obiektowi nadpisać styl modułu własnym
      // kluczem simplestyle-spec (stroke, fill, fill-opacity...), bez rozbijania
      // źródła na osobną warstwę dla każdego obiektu.
      "fill-color": ["coalesce", ["get", "fill"], style.fill],
      "fill-opacity": ["coalesce", ["get", "fill-opacity"], style.fillOpacity],
    },
  });

  // Wielokąty są i w tej warstwie: fill rysuje samo wypełnienie, obrys wymaga line.
  map.addLayer({
    id: lineLayerId,
    type: "line",
    source: sourceId,
    filter: [
      "match",
      ["geometry-type"],
      ["LineString", "MultiLineString", "Polygon", "MultiPolygon"],
      true,
      false,
    ],
    layout: { "line-join": "round", "line-cap": "round" },
    paint: {
      "line-color": ["coalesce", ["get", "stroke"], style.stroke],
      "line-width": ["coalesce", ["get", "stroke-width"], style.strokeWidth],
      "line-opacity": ["coalesce", ["get", "stroke-opacity"], style.strokeOpacity],
    },
  });

  for (const layerId of [fillLayerId, lineLayerId]) {
    map.on("click", layerId, (event) => {
      const feature = event.features && event.features[0];
      if (!feature) return;

      const html = buildShapePopupHtml(feature.properties);
      if (!html) return;

      // Obszar nie ma jednego punktu, więc dymek otwiera się tam, gdzie kliknięto.
      new mapboxgl.Popup({ offset: 8 }).setLngLat(event.lngLat).setHTML(html).addTo(map);
    });

    map.on("mouseenter", layerId, () => {
      mapEl.style.cursor = "pointer";
    });
    map.on("mouseleave", layerId, () => {
      mapEl.style.cursor = "";
    });
  }
}

function initMapboxInstance(container, mapEl, options, features, shapes, bounds) {
  const { tokenmapbox, stylemapbox, zoommapbox, markermapbox, siteRoot, clustermarkers } = options;
  const zoom = toNumber(zoommapbox, 7);
  const clusteringEnabled = asString(clustermarkers) === "1";
  const size = resolveMarkerSize(options);
  const anchor = resolveMarkerAnchor(options.markeranchor);

  if (!tokenmapbox) {
    console.warn("mod_pposmap: Brak tokena Mapbox (tokenmapbox)");
  }
  if (!stylemapbox) {
    console.warn("mod_pposmap: Brak stylu Mapbox (stylemapbox)");
  }

  mapboxgl.accessToken = tokenmapbox;

  //ustawienie m.innymi znacznika pierwszej koordynaty w centrum mapy
  const map = new mapboxgl.Map({
    container: mapEl,
    style: stylemapbox,
    zoom,
    center: resolveCenter(features, bounds),
  });

  if (clusteringEnabled) {
    map.on("load", () => addMapboxClusters(map, mapEl, features, markermapbox, siteRoot, size, anchor));
  } else {
    addMapboxMarkers(map, features, markermapbox, siteRoot, size, anchor);
  }

  // addSource wymaga wczytanego stylu, więc kształty dokładamy dopiero po "load".
  if (shapes.length) {
    map.on("load", () => addMapboxShapes(map, mapEl, shapes, resolveShapeStyle(options)));
  }

  if (shouldFitBounds(options, bounds)) {
    map.fitBounds(
      [
        [bounds.minLng, bounds.minLat],
        [bounds.maxLng, bounds.maxLat],
      ],
      { padding: 30, duration: 0 }
    );
  }

  map.addControl(new mapboxgl.NavigationControl());
  map.scrollZoom.disable();

  map.on("style.load", () => {
    map.setFog({}); // Set the default atmosphere style
  });

  // Set the marker point centrally by clicking on the list outside the map
  bindListClick(container, {
    features,
    onSelect: (index) => {
      const coordinates = features[index].geometry.coordinates;

      map.setCenter([coordinates[0], coordinates[1]]);

      for (const popup of mapEl.getElementsByClassName("mapboxgl-popup")) {
        popup.remove();
      }

      new mapboxgl.Popup({ offset: 25 })
        .setLngLat(coordinates)
        .setHTML(buildPopupHtml(features[index].properties, "mapbox", siteRoot))
        .addTo(map);
    },
  });
}

function addLeafletShapes(L, map, shapes, style) {
  if (!shapes.length) return null;

  const layer = L.geoJSON(
    { type: "FeatureCollection", features: shapes },
    {
      // Klucze simplestyle-spec w properties obiektu nadpisują ustawienia modułu.
      style: (feature) => {
        const props = (feature && feature.properties) || {};

        return {
          color: asString(props.stroke).trim() || style.stroke,
          weight: toNumber(props["stroke-width"], style.strokeWidth),
          opacity: toNumber(props["stroke-opacity"], style.strokeOpacity),
          fillColor: asString(props.fill).trim() || style.fill,
          fillOpacity: toNumber(props["fill-opacity"], style.fillOpacity),
        };
      },
      onEachFeature: (feature, featureLayer) => {
        const html = buildShapePopupHtml(feature && feature.properties);

        if (html) {
          featureLayer.bindPopup(html);
        }
      },
    }
  );

  /*
   * Kształty idą do mapy przed markerami, ale Leaflet i tak trzyma je w niższym
   * panelu (overlayPane) niż pinezki (markerPane), więc obrys nigdy nie zasłoni
   * punktu, nawet gdy go obejmuje.
   */
  layer.addTo(map);

  return layer;
}

function createMarkerLayer(L, markerList, clusteringEnabled) {
  if (clusteringEnabled && typeof L.markerClusterGroup === "function") {
    const group = L.markerClusterGroup();
    group.addLayers(markerList);
    return group;
  }
  return L.layerGroup(markerList);
}

function initLeafletInstance(container, mapEl, options, features, shapes, bounds) {
  const { zoommapbox, markermapbox, groupscontrol, allFilterLeaflet, siteRoot, clustermarkers } = options;
  const zoom = toNumber(zoommapbox, 7);
  const clusteringEnabled = asString(clustermarkers) === "1";

  const L = getLeaflet();
  if (!L) {
    console.error("mod_pposmap: Leaflet nie jest dostępny (window.L)");
    return;
  }

  const groupsMode = asString(groupscontrol);
  const size = resolveMarkerSize(options);
  const anchorKey = resolveMarkerAnchor(options.markeranchor);

  /*
   * Ikona musi powstawać osobno dla każdego markera. divIcon dostaje gotowy element
   * DOM, a jeden element nie może należeć do dwóch markerów naraz — przy grupach
   * ten sam punkt bywa tworzony po raz drugi w warstwie grupy.
   */
  const createLeafletMarker = (feature) => {
    const iconUrl = resolveMarkerUrl(feature.properties.pointmarker, markermapbox, siteRoot);
    const markerOptions = iconUrl
      ? { icon: buildLeafletIcon(L, iconUrl, feature.properties.title, size, anchorKey) }
      : undefined;

    return L.marker([feature.geometry.coordinates[1], feature.geometry.coordinates[0]], markerOptions).bindPopup(
      buildPopupHtml(feature.properties, "leaflet", siteRoot)
    );
  };

  const markers = features.map(createLeafletMarker);

  const allMarkers = createMarkerLayer(L, markers, clusteringEnabled);
  const tiles = buildTileLayer(L, options);
  const center = resolveCenter(features, bounds);

  const map = L.map(mapEl, {
    // Leaflet przyjmuje [lat, lng], GeoJSON trzyma [lng, lat] — stąd zamiana.
    center: [center[1], center[0]],
    zoom,
    layers: [tiles, allMarkers],
    scrollWheelZoom: false,
  });

  addLeafletShapes(L, map, shapes, resolveShapeStyle(options));

  if (shouldFitBounds(options, bounds)) {
    map.fitBounds(
      [
        [bounds.minLat, bounds.minLng],
        [bounds.maxLat, bounds.maxLng],
      ],
      { padding: [20, 20] }
    );
  }

  bindListClick(container, {
    features,
    onSelect: (index) => {
      const coordinates = features[index].geometry.coordinates;
      const content = buildPopupHtml(features[index].properties, "leaflet", siteRoot);

      map.setView(new L.LatLng(coordinates[1], coordinates[0]), zoom);

      L.popup(new L.LatLng(coordinates[1], coordinates[0]), {
        content,
      }).openOn(map);

      map.panTo(new L.LatLng(coordinates[1], coordinates[0]));
    },
  });

  if (groupsMode !== "0") {
    const grouped = features.reduce((acc, item) => {
      const group = asString(item.groupname).trim();
      if (!group) {
        return acc;
      }
      const value = createLeafletMarker(item);

      if (!acc[group]) {
        acc[group] = [];
      }
      acc[group].push(value);
      return acc;
    }, {});

    const createLayerGroup = Object.fromEntries(Object.entries(grouped).map(([key, value]) => [key, createMarkerLayer(L, value, clusteringEnabled)]));
    const allLabel = asString(allFilterLeaflet) || "All";
    const overlays = { [allLabel]: allMarkers, ...createLayerGroup };
    L.control.layers(null, overlays).addTo(map);
  }
}

function initPposmapInstance(container) {
  // Zabezpieczenie przed podwójną inicjalizacją (skrypt wczytany dwa razy,
  // moduł wstrzyknięty przez AJAX itp.) — dwie mapy w jednym kontenerze.
  if (container.dataset.pposmapReady === "1") return;
  container.dataset.pposmapReady = "1";

  const moduleId = container.dataset.pposmapId;
  const options = Joomla.getOptions(`mod_pposmap.vars.${moduleId}`) || {};
  const mapEl = container.querySelector(".pposmap-map");
  if (!mapEl) return;

  // Błąd wczytania albo parsowania GeoJSON-a rozpoznaje PHP; do przeglądarki
  // trafia gotowy komunikat, żeby administrator nie szukał po omacku.
  if (options.geojsonwarning) {
    console.warn(options.geojsonwarning);
  }

  const features = buildFeatures(options.listofpoints);
  const shapes = getShapeFeatures(options);

  // Mapa z samymi obszarami, bez ani jednego punktu, jest poprawnym przypadkiem:
  // granica gminy czy przebieg trasy nie potrzebuje pinezki.
  if (!features.length && !shapes.length) {
    return;
  }

  const bounds = collectBounds(features, shapes);
  const mode = asString(options.mapboxorleaflet);

  if (mode === "0" || mode === "") {
    initMapboxInstance(container, mapEl, options, features, shapes, bounds);
  } else {
    initLeafletInstance(container, mapEl, options, features, shapes, bounds);
  }
}

function initAllPposmapInstances() {
  document.querySelectorAll(".table-pposmap[data-pposmap-id]").forEach(initPposmapInstance);
}

// Nie wolno polegać wyłącznie na DOMContentLoaded: optymalizatory (LiteSpeed Cache,
// JCH Optimize) potrafią odroczyć ten plik do pierwszej interakcji użytkownika,
// czyli do momentu, w którym zdarzenie już dawno poleciało i mapa nigdy nie wystartuje.
if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", initAllPposmapInstances, { once: true });
} else {
  initAllPposmapInstances();
}
