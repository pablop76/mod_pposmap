if (!window.Joomla) {
  throw new Error("Joomla API was not properly initialised");
}

function getLeaflet() {
  if (window.PPOSMAP_LEAFLET) {
    return window.PPOSMAP_LEAFLET;
  }

  const globalL = window.L;
  if (globalL && typeof globalL.noConflict === "function") {
    const localL = globalL.noConflict();
    window.PPOSMAP_LEAFLET = localL;
    return localL;
  }

  window.PPOSMAP_LEAFLET = globalL;
  return globalL;
}

function toNumber(value, fallback) {
  const numberValue = Number(value);
  return Number.isFinite(numberValue) ? numberValue : fallback;
}

function asString(value) {
  return value == null ? "" : String(value);
}

function buildTelephoneLink(phoneValue) {
  const raw = asString(phoneValue).trim();
  if (!raw) return "";

  // Do tel: bierzemy cyfry oraz ewentualny wiodący +
  const normalized = raw.startsWith("+")
    ? "+" + raw.slice(1).replace(/\D+/g, "")
    : raw.replace(/\D+/g, "");

  if (!normalized || normalized === "+") return "";

  return `<a href="tel:${normalized}">${raw}</a>`;
}

function buildImageHtml(imageObj, title, siteRoot) {
  const file = imageObj && imageObj.imagefile ? String(imageObj.imagefile) : "";
  if (!file) return "";
  return `<img src="${siteRoot || ""}/${file}" alt="${asString(title)}" />`;
}

function buildPopupHtml({ title, description, popupimage, openinghours, telephonevalue }, variant, siteRoot) {
  const imageHtml = buildImageHtml(popupimage, title, siteRoot);
  const opening = asString(openinghours).trim();
  const telLink = buildTelephoneLink(telephonevalue);
  const telHtml = telLink ? `<p>tel:${telLink}</p>` : "";
  const openingHtml = opening ? `<p>${opening}</p>` : "";
  const titleClass = variant === "mapbox" ? ' class="mapbox-popup-title"' : "";
  const descriptionClass = variant === "mapbox" ? ' class="mapbox-popup-description"' : "";

  return `${imageHtml}<h3${titleClass}>${asString(title)}</h3><p${descriptionClass}>${asString(description)}</p>${openingHtml}${telHtml}`;
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
      },
      groupname: point.layergroup,
    });
  }

  return features;
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

function initMapboxInstance(container, mapEl, options, features) {
  const { tokenmapbox, stylemapbox, zoommapbox, markermapbox, siteRoot } = options;
  const zoom = toNumber(zoommapbox, 7);

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
    center: [features[0].geometry.coordinates[0], features[0].geometry.coordinates[1]],
  });

  // add markers to map
  for (const feature of features) {
    const hasCustomMarker = Boolean(markermapbox && markermapbox.imagefile);
    const marker = hasCustomMarker
      ? (() => {
          const el = document.createElement("div");
          el.className = "marker";
          el.setAttribute("role", "img");
          el.setAttribute("aria-label", asString(feature.properties.title));
          el.style.backgroundImage = `url(${siteRoot || ""}/${markermapbox.imagefile})`;
          return new mapboxgl.Marker({ element: el, anchor: "bottom" });
        })()
      : new mapboxgl.Marker({ anchor: "bottom" });

    marker
      .setLngLat(feature.geometry.coordinates)
      .setPopup(
        new mapboxgl.Popup({ offset: 25 }) // add popups
          .setHTML(buildPopupHtml(feature.properties, "mapbox", siteRoot))
      )
      .addTo(map);
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

function initLeafletInstance(container, mapEl, options, features) {
  const { zoommapbox, markermapbox, groupscontrol, allFilterLeaflet, siteRoot } = options;
  const zoom = toNumber(zoommapbox, 7);

  const L = getLeaflet();
  if (!L) {
    console.error("mod_pposmap: Leaflet nie jest dostępny (window.L)");
    return;
  }

  const groupsMode = asString(groupscontrol);

  const hasCustomLeafletIcon = Boolean(markermapbox && markermapbox.imagefile);
  const customIcon = hasCustomLeafletIcon
    ? L.icon({
        iconUrl: `${siteRoot || ""}/${markermapbox.imagefile}`,
        iconSize: [50, "auto"],
        iconAnchor: [27, 64],
        popupAnchor: [0, 0],
      })
    : null;

  const markers = features.map((feature) => {
    const datacontent = buildPopupHtml(feature.properties, "leaflet", siteRoot);
    const markerOptions = customIcon ? { icon: customIcon } : undefined;
    return L.marker([feature.geometry.coordinates[1], feature.geometry.coordinates[0]], markerOptions).bindPopup(datacontent);
  });

  const allMarkers = L.layerGroup(markers);
  const osm = L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
  });

  const map = L.map(mapEl, {
    center: [features[0].geometry.coordinates[1], features[0].geometry.coordinates[0]],
    zoom,
    layers: [osm, allMarkers],
    scrollWheelZoom: false,
  });

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
      const datacontent = buildPopupHtml(item.properties, "leaflet", siteRoot);
      const markerOptions = customIcon ? { icon: customIcon } : undefined;
      const value = L.marker([item.geometry.coordinates[1], item.geometry.coordinates[0]], markerOptions).bindPopup(datacontent);

      if (!acc[group]) {
        acc[group] = [];
      }
      acc[group].push(value);
      return acc;
    }, {});

    const createLayerGroup = Object.fromEntries(Object.entries(grouped).map(([key, value]) => [key, L.layerGroup(value)]));
    const allLabel = asString(allFilterLeaflet) || "All";
    const overlays = { [allLabel]: allMarkers, ...createLayerGroup };
    L.control.layers(null, overlays).addTo(map);
  }
}

function initPposmapInstance(container) {
  const moduleId = container.dataset.pposmapId;
  const options = Joomla.getOptions(`mod_pposmap.vars.${moduleId}`) || {};
  const mapEl = container.querySelector(".pposmap-map");
  if (!mapEl) return;

  const features = buildFeatures(options.listofpoints);
  if (!features.length) {
    return;
  }

  const mode = asString(options.mapboxorleaflet);

  if (mode === "0" || mode === "") {
    initMapboxInstance(container, mapEl, options, features);
  } else {
    initLeafletInstance(container, mapEl, options, features);
  }
}

document.addEventListener("DOMContentLoaded", function () {
  document.querySelectorAll(".table-pposmap[data-pposmap-id]").forEach(initPposmapInstance);
});
