if (!window.Joomla) {
  throw new Error("Joomla API was not properly initialised");
}

const {
  tokenmapbox,
  stylemapbox,
  listofpoints,
  zoommapbox,
  markermapbox,
  mapboxorleaflet,
  groupscontrol,
  allFilterLeaflet,
} = Joomla.getOptions("mod_pposmap.vars") || {};

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

function buildPopupHtml({ title, description, popupimage, openinghours, telephonevalue }) {
  const imageHtml = buildImageHtml(popupimage);
  const opening = asString(openinghours).trim();
  const telLink = buildTelephoneLink(telephonevalue);
  const telHtml = telLink ? `<p>tel:${telLink}</p>` : "";
  const openingHtml = opening ? `<p>${opening}</p>` : "";

  return `${imageHtml}<h3 class="mapbox-popup-title">${asString(title)}</h3><p class="mapbox-popup-description">${asString(description)}</p>${openingHtml}${telHtml}`;
}

function buildLeafletPopupHtml({ title, description, popupimage, openinghours, telephonevalue }) {
  const imageHtml = buildImageHtml(popupimage);
  const opening = asString(openinghours).trim();
  const telLink = buildTelephoneLink(telephonevalue);
  const telHtml = telLink ? `<p>tel:${telLink}</p>` : "";
  const openingHtml = opening ? `<p>${opening}</p>` : "";

  return `${imageHtml}<h3>${asString(title)}</h3><p>${asString(description)}</p>${openingHtml}${telHtml}`;
}

function buildImageHtml(imageObj) {
  const file = imageObj && imageObj.imagefile ? String(imageObj.imagefile) : "";
  if (!file) return "";
  return `<img src="/${file}" />`;
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

function bindListClick({ features, onSelect }) {
  const container = document.querySelector(".table-pposmap");
  if (!container) return;

  container.addEventListener("click", (event) => {
    const link = event.target && event.target.closest ? event.target.closest("a[data-index]") : null;
    if (!link || !container.contains(link)) return;

    const index = Number(link.dataset.index);
    if (!Number.isInteger(index) || !features[index]) return;
    onSelect(index);
  });
}

document.addEventListener("DOMContentLoaded", function () {
  const features = buildFeatures(listofpoints);
  if (!features.length) {
    return;
  }

  const geojson = {
    type: "FeatureCollection",
    features,
  };

  const mode = asString(mapboxorleaflet);
  const zoom = toNumber(zoommapbox, 7);

  if (mode === "0" || mode === "") {
    if (!tokenmapbox) {
      console.warn("mod_pposmap: Brak tokena Mapbox (tokenmapbox)");
    }

    if (!stylemapbox) {
      console.warn("mod_pposmap: Brak stylu Mapbox (stylemapbox)");
    }

    mapboxgl.accessToken = tokenmapbox;

    //ustawienie m.innymi znacznika pierwszej koordynaty w centrum mapy
    const map = new mapboxgl.Map({
      container: "map",
      style: stylemapbox,
      zoom,
      center: [features[0].geometry.coordinates[0], features[0].geometry.coordinates[1]],
    });
    // add markers to map
    for (const feature of geojson.features) {
      const hasCustomMarker = Boolean(markermapbox && markermapbox.imagefile);
      const marker = hasCustomMarker
        ? (() => {
            const el = document.createElement("div");
            el.className = "marker";
            el.setAttribute("role", "img");
            el.setAttribute("aria-label", asString(feature.properties.title));
            el.style.backgroundImage = `url(/${markermapbox.imagefile})`;
            return new mapboxgl.Marker({ element: el, anchor: "bottom" });
          })()
        : new mapboxgl.Marker({ anchor: "bottom" });

      marker
        .setLngLat(feature.geometry.coordinates)
        .setPopup(
          new mapboxgl.Popup({ offset: 25 }) // add popups
            .setHTML(buildPopupHtml({
              title: feature.properties.title,
              description: feature.properties.description,
              popupimage: feature.properties.popupimage,
              openinghours: feature.properties.openinghours,
              telephonevalue: feature.properties.telephonevalue,
            }))
        )
        .addTo(map);
    }

    map.addControl(new mapboxgl.NavigationControl());
    map.scrollZoom.disable();

    map.on("style.load", () => {
      map.setFog({}); // Set the default atmosphere style
    });

    // The following values can be changed to control rotation speed:

    // At low zooms, complete a revolution every two minutes.
    const secondsPerRevolution = 240;
    // Above zoom level 5, do not rotate.
    const maxSpinZoom = 5;
    // Rotate at intermediate speeds between zoom levels 3 and 5.
    const slowSpinZoom = 3;

    let userInteracting = false;
    const spinEnabled = true;

    function spinGlobe() {
      const zoom = map.getZoom();
      if (spinEnabled && !userInteracting && zoom < maxSpinZoom) {
        let distancePerSecond = 360 / secondsPerRevolution;
        if (zoom > slowSpinZoom) {
          // Slow spinning at higher zooms
          const zoomDif = (maxSpinZoom - zoom) / (maxSpinZoom - slowSpinZoom);
          distancePerSecond *= zoomDif;
        }
        const center = map.getCenter();
        center.lng -= distancePerSecond;
        // Smoothly animate the map over one second.
        // When this animation is complete, it calls a 'moveend' event.
        map.easeTo({ center, duration: 1000, easing: (n) => n });
      }
    }

    // Pause spinning on interaction
    map.on("mousedown", () => {
      userInteracting = true;
    });
    map.on("dragstart", () => {
      userInteracting = true;
    });

    // When animation is complete, start spinning if there is no ongoing interaction
    map.on("moveend", () => {
      spinGlobe();
    });
    spinGlobe();
    // Set the marker point centrally by clicking on the list outside the map

    bindListClick({
      features,
      onSelect: (index) => {
        const coordinates = features[index].geometry.coordinates;

        map.setCenter([coordinates[0], coordinates[1]]);

        for (const popup of document.getElementsByClassName("mapboxgl-popup")) {
          popup.remove();
        }

        new mapboxgl.Popup({ offset: 25 })
          .setLngLat(coordinates)
          .setHTML(buildPopupHtml({
            title: features[index].properties.title,
            description: features[index].properties.description,
            popupimage: features[index].properties.popupimage,
            openinghours: features[index].properties.openinghours,
            telephonevalue: features[index].properties.telephonevalue,
          }))
          .addTo(map);
      },
    });
  } else {
    const groupsMode = asString(groupscontrol);

    const hasCustomLeafletIcon = Boolean(markermapbox && markermapbox.imagefile);
    const customIcon = hasCustomLeafletIcon
      ? L.icon({
          iconUrl: "/" + markermapbox.imagefile,
          iconSize: [50, "auto"],
          iconAnchor: [27, 64],
          popupAnchor: [0, 0],
        })
      : null;

    const markers = features.map((feature) => {
      const datacontent = buildLeafletPopupHtml({
        title: feature.properties.title,
        description: feature.properties.description,
        popupimage: feature.properties.popupimage,
        openinghours: feature.properties.openinghours,
        telephonevalue: feature.properties.telephonevalue,
      });

      const markerOptions = customIcon ? { icon: customIcon } : undefined;
      return L.marker([feature.geometry.coordinates[1], feature.geometry.coordinates[0]], markerOptions).bindPopup(datacontent);
    });

    const allMarkers = L.layerGroup(markers);
    const osm = L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", {
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    });

    const map = L.map("map", {
      center: [features[0].geometry.coordinates[1], features[0].geometry.coordinates[0]],
      zoom,
      layers: [osm, allMarkers],
      scrollWheelZoom: false,
    });

    bindListClick({
      features,
      onSelect: (index) => {
        const coordinates = features[index].geometry.coordinates;
        const content = buildLeafletPopupHtml({
          title: features[index].properties.title,
          description: features[index].properties.description,
          popupimage: features[index].properties.popupimage,
          openinghours: features[index].properties.openinghours,
          telephonevalue: features[index].properties.telephonevalue,
        });

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
        const datacontent = buildLeafletPopupHtml({
          title: item.properties.title,
          description: item.properties.description,
          popupimage: item.properties.popupimage,
          openinghours: item.properties.openinghours,
          telephonevalue: item.properties.telephonevalue,
        });
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
});
