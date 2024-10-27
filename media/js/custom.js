if (!window.Joomla) {
  throw new Error("Joomla API was not properly initialised");
}

const { tokenmapbox } = Joomla.getOptions("mod_pposmap.vars");
const { stylemapbox } = Joomla.getOptions("mod_pposmap.vars");
const { listofpoints } = Joomla.getOptions("mod_pposmap.vars");
const { zoommapbox } = Joomla.getOptions("mod_pposmap.vars");
const { geotitle } = Joomla.getOptions("mod_pposmap.vars");
const { geodescription } = Joomla.getOptions("mod_pposmap.vars");
const { markermapbox } = Joomla.getOptions("mod_pposmap.vars");
const { mapboxorleaflet } = Joomla.getOptions("mod_pposmap.vars");
document.addEventListener("DOMContentLoaded", function () {
  let originalData = listofpoints;
  // Iteracja po istniejących punktach
  // Tworzymy tablicę, która będzie zawierać nowe obiekty typu "Feature"
  let features = [];
  for (let key in originalData) {
    let point = originalData[key];

    // Tworzenie nowej struktury typu "Feature" dla każdego punktu
    let feature = {
      type: "Feature",
      geometry: {
        type: "Point",
        coordinates: [parseFloat(point.longitudemapbox), parseFloat(point.latitudemapbox)],
      },
      properties: {
        title: point.geotitle,
        description: point.geodescription,
        popupimage: point.popupimage,
      },
    };

    // Dodawanie nowego obiektu do tablicy features
    features.push(feature);
  }

  // Wyświetlenie wyniku
  const geojson = {
    type: "FeatureCollection",
    features: features,
  };
  if (mapboxorleaflet == "0") {
    mapboxgl.accessToken = tokenmapbox;

    //ustawienie m.innymi znacznika pierwszej koordynaty w centrum mapy
    const map = new mapboxgl.Map({
      container: "map",
      style: stylemapbox,
      zoom: zoommapbox,
      center: [features[0].geometry.coordinates[0], features[0].geometry.coordinates[1]],
    });

    // add markers to map
    for (const feature of geojson.features) {
      // create a HTML element for each feature
      const el = document.createElement("div");
      el.className = "marker";

      const imageshow = function () {
        // imgpopup.src = feature.properties.popupimage.imagefile;
        if (feature.properties.popupimage.imagefile) {
          return `<img src=/${feature.properties.popupimage.imagefile}/>`;
        } else {
          return "";
        }
      };

      // make a marker for each feature and add to the map
      new mapboxgl.Marker(el).setLngLat(feature.geometry.coordinates).addTo(map);

      new mapboxgl.Marker(el)
        .setLngLat(feature.geometry.coordinates)
        .setPopup(
          new mapboxgl.Popup({ offset: 25 }) // add popups
            .setHTML(`${imageshow()}<h3 class="mapbox-popup-title">${feature.properties.title}</h3><p class="mapbox-popup-description">${feature.properties.description}</p>`)
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
    //zmiana markera na własny
    document.querySelectorAll(".marker").forEach((element) => {
      element.style.backgroundImage = "url(" + "/" + markermapbox.imagefile + ")";
      if (!markermapbox.alt_empty) element.setAttribute("alt", markermapbox.alt_text);
    });
    // Set the marker point centrally by clicking on the list outside the map
    const tableMapbox = document.querySelector(".table-pposmap");
    tableMapbox.addEventListener("click", (e) => {
      const tableElement = e.target.parentNode;
      const coordinates = features[tableElement.dataset.index - 1].geometry.coordinates;
      const title = features[tableElement.dataset.index - 1].properties.title;
      const description = features[tableElement.dataset.index - 1].properties.description;
      const popupimage = function () {
        if (features[tableElement.dataset.index - 1].properties.popupimage.imagefile) {
          return `<img src=/${features[tableElement.dataset.index - 1].properties.popupimage.imagefile}/>`;
        } else {
          return "";
        }
      };
      map.setCenter([coordinates[0], coordinates[1]]);
      // remove popup by clicking outside the map
      for (var popup of document.getElementsByClassName("mapboxgl-popup")) {
        popup.remove();
      }
      // adding a popup by clicking outside the map on the list of points
      popup = new mapboxgl.Popup().setLngLat(coordinates).setHTML(`${popupimage()}<h3 class="mapbox-popup-title">${title}</h3><p class="mapbox-popup-description">${description}</p>`).addTo(map);
    });
  } else {
    var map = L.map("map").setView([features[0].geometry.coordinates[1], features[0].geometry.coordinates[0]], zoommapbox);

    L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", {
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    }).addTo(map);
    // custom marker
    var greenIcon = L.icon({
      iconUrl: "/" + markermapbox.imagefile,
      iconSize: [50, 50], // size of the icon
      iconAnchor: [27, 64], // point of the icon which will correspond to marker's location
      popupAnchor: [0, 0], // point from which the popup should open relative to the iconAnchor
    });

    for (let i = 0; i < features.length; i++) {
      let datacontent = `<img src=/${features[i].properties.popupimage.imagefile}><h3>${features[i].properties.title}</h3><p>${features[i].properties.description}</p>`;
      L.marker([features[i].geometry.coordinates[1], features[i].geometry.coordinates[0]], { icon: greenIcon }).addTo(map).bindPopup(datacontent);
    }
    const tableMapbox = document.querySelector(".table-pposmap");
    tableMapbox.addEventListener("click", (e) => {
      const tableElement = e.target.parentNode;
      const coordinates = features[tableElement.dataset.index - 1].geometry.coordinates;
      const title = features[tableElement.dataset.index - 1].properties.title;
      const description = features[tableElement.dataset.index - 1].properties.description;
      const popupimage = function () {
        if (features[tableElement.dataset.index - 1].properties.popupimage.imagefile) {
          return `<img src=/${features[tableElement.dataset.index - 1].properties.popupimage.imagefile}/>`;
        } else {
          return "";
        }
      };
      const popupContent = `${popupimage()}<h3>${title}</h3><p>${description}</p>`;
      L.marker([coordinates[1], coordinates[0]], { icon: greenIcon }).addTo(map).bindPopup(popupContent).openPopup();
    });
  }
});
