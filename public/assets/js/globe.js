/* PAMIR TO MARS - 3D globe (Earth, Mars, Moon) built on CesiumJS.
 * Imagery is open NASA data: GIBS (Blue Marble, Landsat), Mars Trek (Viking, MOLA), Moon Trek (LRO WAC).
 * No key is needed. CesiumJS is loaded only when the map page is opened (it is a large library).
 * If WebGL or the library is not available, init() returns false and app.js shows the flat map instead.
 */
'use strict';

const Globe = (() => {
  const CESIUM_VERSION = '1.124.0';
  const CESIUM_BASE = `https://cdn.jsdelivr.net/npm/cesium@${CESIUM_VERSION}/Build/Cesium/`;
  const GIBS = 'https://gibs.earthdata.nasa.gov/wmts/epsg3857/best';
  const TREK = 'https://trek.nasa.gov/tiles';

  // Spheres used by NASA Trek tile sets (radius in metres).
  const RADII = { earth: null, mars: 3396190, moon: 1737400 };
  const KM_PER_DEG = { earth: 111.195, mars: (Math.PI * 3396.19) / 180, moon: (Math.PI * 1737.4) / 180 };
  const GALE_CRATER_CENTRE = [-5.4, 137.8]; // approximate crater centre (literature); landing site comes from the database

  let C = null;            // the Cesium namespace
  let cfg = null;          // options from app.js
  let viewer = null, body = 'earth', ell = null;
  let mainEllipsoid = null;
  let spin = true, spinTimer = null, terrainOn = false;
  let layerChoice = { earth: 's2', mars: 'viking', moon: 'lro' };
  let heatSource = null, probeEntities = [], handler = null, selectedSlug = null;
  const pins = {};         // slug -> point entity (to highlight the selected one)

  // ------------------------------------------------------------------ loading
  function loadCesium() {
    if (window.Cesium) return Promise.resolve(window.Cesium);
    return new Promise((resolve, reject) => {
      window.CESIUM_BASE_URL = CESIUM_BASE;
      const css = document.createElement('link');
      css.rel = 'stylesheet'; css.href = CESIUM_BASE + 'Widgets/widgets.css';
      document.head.appendChild(css);
      const s = document.createElement('script');
      s.src = CESIUM_BASE + 'Cesium.js';
      s.onload = () => (window.Cesium ? resolve(window.Cesium) : reject(new Error('Cesium did not start')));
      s.onerror = () => reject(new Error('Could not load CesiumJS (no internet?)'));
      document.head.appendChild(s);
    });
  }

  function webglOk() {
    try {
      const c = document.createElement('canvas');
      return !!(window.WebGLRenderingContext && (c.getContext('webgl2') || c.getContext('webgl')));
    } catch (e) { return false; }
  }

  // ------------------------------------------------------------------ imagery
  function mercator(url, level, credit) {
    return new C.UrlTemplateImageryProvider({ url, maximumLevel: level, credit, tilingScheme: new C.WebMercatorTilingScheme() });
  }
  function equirect(url, level, credit) {
    return new C.UrlTemplateImageryProvider({
      url, maximumLevel: level, credit,
      tilingScheme: new C.GeographicTilingScheme({ ellipsoid: ell, numberOfLevelZeroTilesX: 2, numberOfLevelZeroTilesY: 1 }),
      rectangle: C.Rectangle.MAX_VALUE,
    });
  }
  const blueMarble = () => mercator(`${GIBS}/BlueMarble_ShadedRelief_Bathymetry/default/GoogleMapsCompatible_Level8/{z}/{y}/{x}.jpeg`, 8, 'NASA GIBS Blue Marble');
  const landsat = () => mercator(`${GIBS}/Landsat_WELD_CorrectedReflectance_TrueColor_Global_Annual/default/GoogleMapsCompatible_Level12/{z}/{y}/{x}.jpg`, 12, 'NASA GIBS Landsat WELD (30 m)');
  const sentinel = () => mercator('https://tiles.maps.eox.at/wmts/1.0.0/s2cloudless-2021_3857/default/g/{z}/{y}/{x}.jpg', 14, 'Sentinel-2 cloudless by EOX IT Services (contains modified Copernicus Sentinel data 2021)');

  const LAYERS = {
    earth: [
      { id: 's2', label: 'NASA Blue Marble + ESA Sentinel-2 (10 m)', make: () => [blueMarble(), sentinel()] },
      { id: 'nasa', label: 'NASA Blue Marble + NASA Landsat (30 m, slow server)', make: () => [blueMarble(), landsat()] },
      { id: 'bm', label: 'NASA Blue Marble relief', make: () => [blueMarble()] },
    ],
    mars: [
      { id: 'viking', label: 'NASA Viking colour mosaic', make: () => [equirect(`${TREK}/Mars/EQ/Mars_Viking_MDIM21_ClrMosaic_global_232m/1.0.0/default/default028mm/{z}/{y}/{x}.jpg`, 7, 'NASA/JPL Mars Trek, Viking MDIM 2.1')] },
      { id: 'mola', label: 'NASA MOLA elevation colours', make: () => [equirect(`${TREK}/Mars/EQ/Mars_MGS_MOLA_ClrShade_merge_global_463m/1.0.0/default/default028mm/{z}/{y}/{x}.jpg`, 7, 'NASA/JPL Mars Trek, MOLA')] },
    ],
    moon: [
      { id: 'lro', label: 'NASA LRO WAC mosaic', make: () => [equirect(`${TREK}/Moon/EQ/LRO_WAC_Mosaic_Global_303ppd_v02/1.0.0/default/default028mm/{z}/{y}/{x}.jpg`, 8, 'NASA/GSFC/ASU Moon Trek, LRO WAC')] },
    ],
  };

  // ------------------------------------------------------------------ viewer
  function ellipsoidFor(b) {
    if (b === 'earth') return C.Ellipsoid.WGS84;
    const r = RADII[b];
    return new C.Ellipsoid(r, r, r);
  }

  async function build() {
    if (viewer) { viewer.destroy(); viewer = null; }
    worldSource = null;
    heatSource = null; probeEntities = []; Object.keys(pins).forEach((k) => delete pins[k]);
    ell = ellipsoidFor(body);
    mainEllipsoid = ell;
    try { C.Ellipsoid.default = ell; } catch (e) { /* older Cesium: positions below pass the ellipsoid explicitly */ }
    const el = document.getElementById(cfg.el);
    el.innerHTML = '';
    viewer = new C.Viewer(el, {
      baseLayer: false, baseLayerPicker: false, geocoder: false, homeButton: false, sceneModePicker: false,
      navigationHelpButton: false, animation: false, timeline: false, infoBox: false, selectionIndicator: false,
      fullscreenButton: true, scene3DOnly: true, globe: new C.Globe(ell), mapProjection: new C.GeographicProjection(ell),
      skyAtmosphere: body === 'earth' ? undefined : false, shouldAnimate: true,
    });
    const scene = viewer.scene;
    scene.backgroundColor = C.Color.fromCssColorString('#02050C');
    scene.globe.baseColor = C.Color.fromCssColorString('#1a2236');
    scene.globe.enableLighting = false;
    scene.globe.showGroundAtmosphere = body === 'earth';
    scene.globe.maximumScreenSpaceError = 1.6;
    scene.screenSpaceCameraController.minimumZoomDistance = 250;
    scene.screenSpaceCameraController.maximumZoomDistance = 6e7;
    viewer.screenSpaceEventHandler.removeInputAction(C.ScreenSpaceEventType.LEFT_DOUBLE_CLICK);

    // One tick listener per viewer: slow eastward spin while the camera is high and the user is idle.
    viewer.clock.onTick.addEventListener(() => {
      if (spin && body !== 'moon' && viewer.camera.positionCartographic.height > 4e6) { viewer.camera.rotate(C.Cartesian3.UNIT_Z, -0.0007); }
    });
    applyLayers();
    addPins();
    addRings();
    wireInput();
    setStartView();
    startSpin();
    if (body === 'earth' && cfg.ionToken) tryTerrain();
    updateUi();
  }

  function applyLayers() {
    viewer.imageryLayers.removeAll();
    const def = LAYERS[body].find((l) => l.id === layerChoice[body]) || LAYERS[body][0];
    def.make().forEach((p) => viewer.imageryLayers.addImageryProvider(p));
  }

  /** Relief from Cesium ion if it is reachable. Without it the globe stays smooth: imagery only. */
  async function tryTerrain() {
    try {
      if (cfg.ionToken) { C.Ion.defaultAccessToken = cfg.ionToken; }
      const tp = await C.createWorldTerrainAsync();
      if (!viewer || body !== 'earth') return;
      viewer.terrainProvider = tp;
      viewer.scene.globe.depthTestAgainstTerrain = true;
      terrainOn = true;
      updateUi();
    } catch (e) { terrainOn = false; }
  }

  // ------------------------------------------------------------------ markers
  const pos = (lat, lon, h = 0) => C.Cartesian3.fromDegrees(lon, lat, h, ell);

  function pin(slug, loc, color, size, labelDistance, bold) {
    const col = C.Color.fromCssColorString(color);
    const e = viewer.entities.add({
      id: 'loc:' + slug,
      position: pos(loc.latitude, loc.longitude),
      point: { pixelSize: size, color: col, outlineColor: C.Color.WHITE, outlineWidth: bold ? 3 : 2,
               heightReference: C.HeightReference.CLAMP_TO_GROUND, disableDepthTestDistance: 0 },
      label: { text: loc.name, font: `${bold ? 600 : 500} ${bold ? 16 : 14}px Inter, sans-serif`,
               fillColor: C.Color.WHITE, outlineColor: C.Color.BLACK, outlineWidth: 3, style: C.LabelStyle.FILL_AND_OUTLINE,
               showBackground: true, backgroundColor: C.Color.fromCssColorString('#0A1222D0'), backgroundPadding: new C.Cartesian2(8, 5),
               verticalOrigin: C.VerticalOrigin.BOTTOM, pixelOffset: new C.Cartesian2(0, -(size / 2 + 8)),
               heightReference: C.HeightReference.CLAMP_TO_GROUND,
               distanceDisplayCondition: new C.DistanceDisplayCondition(0, labelDistance),
               translucencyByDistance: new C.NearFarScalar(labelDistance * 0.6, 1.0, labelDistance, 0.0) },
    });
    pins[slug] = e;
    return e;
  }

  /** A soft pulsing ring: makes the Pamir sites easy to spot from orbit. */
  function halo(loc, color) {
    const col = C.Color.fromCssColorString(color);
    const t0 = performance.now();
    const phase = () => ((performance.now() - t0) % 2200) / 2200;
    viewer.entities.add({
      position: pos(loc.latitude, loc.longitude),
      point: {
        pixelSize: new C.CallbackProperty(() => 16 + phase() * 38, false),
        color: new C.CallbackProperty(() => col.withAlpha(0.55 * (1 - phase())), false),
        heightReference: C.HeightReference.CLAMP_TO_GROUND, disableDepthTestDistance: 0,
      },
    });
  }

  function addPins() {
    if (body === 'earth') {
      for (const a of cfg.analogs || []) { pin(a.slug, a, '#22D3EE', 9, 4e6, false); }
      for (const s of cfg.sites || []) {
        const color = s.is_verified ? '#34D399' : '#FBBF24';
        pin(s.slug, s, color, 16, 2.5e6, true);
        halo(s, color);
      }
      // From orbit the three sites are one cluster: a single label says where to look.
      viewer.entities.add({
        position: pos(38.4, 73.7),
        label: { text: 'PAMIR', font: '700 18px "Space Grotesk", Inter, sans-serif', fillColor: C.Color.fromCssColorString('#FDBA74'), outlineColor: C.Color.BLACK, outlineWidth: 4,
                 style: C.LabelStyle.FILL_AND_OUTLINE, verticalOrigin: C.VerticalOrigin.BOTTOM, pixelOffset: new C.Cartesian2(0, -34),
                 distanceDisplayCondition: new C.DistanceDisplayCondition(2.5e6, 6e7) },
      });
    } else {
      for (const r of cfg.refs || []) {
        if (r.body !== body) continue;
        pin(r.slug, r, body === 'mars' ? '#F97316' : '#CBD5E1', 16, 6e7, true);
        halo(r, body === 'mars' ? '#F97316' : '#CBD5E1');
      }
    }
    if (selectedSlug) highlight(selectedSlug);
  }

  function ringPositions(lat, lon, radiusKm) {
    const kd = KM_PER_DEG[body], out = [];
    for (let i = 0; i <= 120; i++) {
      const a = (i / 120) * 2 * Math.PI;
      out.push(pos(lat + (radiusKm * Math.cos(a)) / kd, lon + (radiusKm * Math.sin(a)) / (kd * Math.cos((lat * Math.PI) / 180))));
    }
    return out;
  }

  /** The impact structures drawn as orange rings: Karakul on Earth, Gale on Mars. */
  function addRings() {
    const k = cfg.craters || {};
    let centre = null, km = null, label = '';
    if (body === 'earth' && k.earthKm) {
      const s = (cfg.sites || []).find((x) => x.slug === 'karakul');
      if (s) { centre = [s.latitude, s.longitude]; km = k.earthKm; label = 'Karakul impact structure'; }
    } else if (body === 'mars' && k.marsKm) {
      centre = GALE_CRATER_CENTRE; km = k.marsKm; label = 'Gale Crater';
    }
    if (!centre) return;
    viewer.entities.add({
      polyline: { positions: ringPositions(centre[0], centre[1], km / 2), width: 3, clampToGround: true,
                  material: new C.PolylineDashMaterialProperty({ color: C.Color.fromCssColorString('#F97316'), dashLength: 18 }) },
    });
    viewer.entities.add({
      position: pos(centre[0] + (km / 2) / KM_PER_DEG[body], centre[1]),
      label: { text: `${label} · ${Math.round(km)} km`, font: '500 13px Inter, sans-serif', fillColor: C.Color.fromCssColorString('#FDBA74'),
               outlineColor: C.Color.BLACK, outlineWidth: 3, style: C.LabelStyle.FILL_AND_OUTLINE, verticalOrigin: C.VerticalOrigin.BOTTOM,
               distanceDisplayCondition: new C.DistanceDisplayCondition(0, 1.5e6), heightReference: C.HeightReference.CLAMP_TO_GROUND },
    });
  }

  function highlight(slug) {
    selectedSlug = slug;
    for (const [k, e] of Object.entries(pins)) {
      if (!e.point) continue;
      const on = k === slug;
      e.point.outlineColor = on ? C.Color.fromCssColorString('#F97316') : C.Color.WHITE;
      e.point.outlineWidth = on ? 5 : (cfg.sites || []).some((s) => s.slug === k) ? 3 : 2;
    }
  }

  // ------------------------------------------------------------------ heatmap and probe
  function setHeat(cells, size) {
    if (!viewer) return;
    if (heatSource) { viewer.dataSources.remove(heatSource, true); heatSource = null; }
    if (!cells || !cells.length) return;
    heatSource = new C.CustomDataSource('heat');
    const hl = size.lat / 2, hw = size.lon / 2;
    for (const c of cells) {
      heatSource.entities.add({
        rectangle: { coordinates: C.Rectangle.fromDegrees(c.lon - hw, c.lat - hl, c.lon + hw, c.lat + hl),
                     material: C.Color.fromCssColorString(c.color).withAlpha(0.5) },
        description: c.tip,
      });
    }
    viewer.dataSources.add(heatSource);
  }

  let worldSource = null;
  /** The world scan: big coloured cells over the whole Earth (cells = [{lat, lon, color}]). */
  function setWorld(cells, step) {
    if (!viewer) return;
    if (worldSource) { viewer.dataSources.remove(worldSource, true); worldSource = null; }
    if (!cells || !cells.length) return;
    worldSource = new C.CustomDataSource('world');
    const h = step / 2;
    for (const c of cells) {
      worldSource.entities.add({ rectangle: { coordinates: C.Rectangle.fromDegrees(c.lon - h, Math.max(-90, c.lat - h), c.lon + h, Math.min(90, c.lat + h)),
                                              material: C.Color.fromCssColorString(c.color).withAlpha(0.55) } });
    }
    viewer.dataSources.add(worldSource);
  }

  /** After "Analyze any point": a dashed line to the closest known analog, with its name always visible. */
  function setProbeLink(lat1, lon1, lat2, lon2, name) {
    if (!viewer) return;
    probeEntities.push(viewer.entities.add({
      polyline: { positions: [pos(lat1, lon1), pos(lat2, lon2)], width: 3, arcType: C.ArcType.GEODESIC,
                  material: new C.PolylineDashMaterialProperty({ color: C.Color.fromCssColorString('#22D3EE'), dashLength: 16 }) },
    }));
    probeEntities.push(viewer.entities.add({
      position: pos(lat2, lon2),
      point: { pixelSize: 13, color: C.Color.fromCssColorString('#22D3EE'), outlineColor: C.Color.WHITE, outlineWidth: 3 },
      label: { text: name, font: '600 15px Inter, sans-serif', fillColor: C.Color.WHITE, outlineColor: C.Color.BLACK, outlineWidth: 3,
               style: C.LabelStyle.FILL_AND_OUTLINE, showBackground: true, backgroundColor: C.Color.fromCssColorString('#0A1222D0'),
               verticalOrigin: C.VerticalOrigin.BOTTOM, pixelOffset: new C.Cartesian2(0, -16) },
    }));
  }

  function flyToPoint(lat, lon, rangeM) {
    if (!viewer) return;
    stopSpin();
    viewer.camera.flyTo({ destination: pos(lat, lon, rangeM), orientation: { heading: 0, pitch: C.Math.toRadians(-90), roll: 0 }, duration: 2.5 });
  }

  function setProbe(lat, lon) {
    if (!viewer) return;
    probeEntities.forEach((e) => viewer.entities.remove(e)); probeEntities = [];
    if (lat === null) return;
    const col = C.Color.fromCssColorString('#F97316');
    const t0 = performance.now();
    const phase = () => ((performance.now() - t0) % 1600) / 1600;
    probeEntities.push(viewer.entities.add({
      position: pos(lat, lon),
      point: { pixelSize: 12, color: col, outlineColor: C.Color.WHITE, outlineWidth: 2, heightReference: C.HeightReference.CLAMP_TO_GROUND, disableDepthTestDistance: 0 },
    }));
    probeEntities.push(viewer.entities.add({
      position: pos(lat, lon),
      point: { pixelSize: new C.CallbackProperty(() => 14 + phase() * 40, false),
               color: new C.CallbackProperty(() => col.withAlpha(0.6 * (1 - phase())), false), heightReference: C.HeightReference.CLAMP_TO_GROUND, disableDepthTestDistance: 0 },
    }));
  }

  // ------------------------------------------------------------------ input and camera
  function pickLatLon(position) {
    const ray = viewer.camera.getPickRay(position);
    const p = ray ? viewer.scene.globe.pick(ray, viewer.scene) : null;
    const c = p ? ell.cartesianToCartographic(p) : null;
    return c ? { lat: C.Math.toDegrees(c.latitude), lon: C.Math.toDegrees(c.longitude) } : null;
  }

  function wireInput() {
    if (handler) { try { handler.destroy(); } catch (e) { /* already gone */ } }
    handler = new C.ScreenSpaceEventHandler(viewer.canvas);
    handler.setInputAction((m) => {
      const picked = viewer.scene.pick(m.position);
      if (C.defined(picked) && picked.id && typeof picked.id.id === 'string' && picked.id.id.indexOf('loc:') === 0) {
        stopSpin();
        cfg.onLocation(picked.id.id.slice(4));
        return;
      }
      const ll = pickLatLon(m.position);
      if (ll) { cfg.onPoint(ll.lat, ll.lon); }
    }, C.ScreenSpaceEventType.LEFT_CLICK);
    ['pointerdown', 'wheel', 'touchstart'].forEach((ev) => viewer.canvas.addEventListener(ev, stopSpin, { passive: true }));
  }

  function setStartView() {
    const v = { earth: [73.5, 31, 1.5e7], mars: [137.4, -4.6, 1.5e7], moon: [0, -89.5, 6e6] }[body];
    viewer.camera.setView({ destination: pos(v[1], v[0], v[2]), orientation: { heading: 0, pitch: C.Math.toRadians(-90), roll: 0 } });
  }

  function startSpin() { spin = true; updateUi(); }
  function stopSpin() { if (spin) { spin = false; updateUi(); } }

  /** Fly to a place: range in metres, pitch in degrees (negative looks down). */
  function flyTo(lat, lon, range, pitchDeg, heading) {
    stopSpin();
    const sphere = new C.BoundingSphere(pos(lat, lon, 3000), Math.max(range / 6, 1000));
    viewer.camera.flyToBoundingSphere(sphere, {
      duration: 3.2,
      offset: new C.HeadingPitchRange(C.Math.toRadians(heading || 0), C.Math.toRadians(pitchDeg), range),
    });
  }

  function flyToSlug(slug) {
    const all = [...(cfg.sites || []), ...(cfg.analogs || []), ...(cfg.refs || [])];
    const l = all.find((x) => x.slug === slug);
    if (l) { flyTo(l.latitude, l.longitude, body === 'earth' ? 40000 : 90000, -42, 0); }
  }

  function flyPamir() { flyTo(38.4, 73.7, 330000, -38, 0); }
  function flyHome() { stopSpin(); const v = { earth: [73.5, 31, 1.5e7], mars: [137.4, -4.6, 1.5e7], moon: [0, -89.5, 6e6] }[body];
    viewer.camera.flyTo({ destination: pos(v[1], v[0], v[2]), orientation: { heading: 0, pitch: C.Math.toRadians(-90), roll: 0 }, duration: 2.5 }); }

  /** Same camera height over Karakul, then over Gale: the two craters side by side in time. */
  async function craterFlight() {
    const k = cfg.sites.find((s) => s.slug === 'karakul');
    const g = cfg.refs.find((r) => r.slug === 'gale');
    if (!k || !g) return;
    if (body !== 'earth') { body = 'earth'; await build(); onBodyChanged(); }
    flyTo(k.latitude, k.longitude, 150000, -50, 0);
    setTimeout(async () => {
      body = 'mars'; await build(); onBodyChanged();
      flyTo(GALE_CRATER_CENTRE[0], GALE_CRATER_CENTRE[1], 150000, -50, 0);
    }, 5200);
  }

  // ------------------------------------------------------------------ overlay UI
  function buildUi() {
    const ui = document.getElementById(cfg.ui);
    ui.innerHTML = `
      <div class="g-seg" role="group" aria-label="Choose a world">
        <button data-body="earth">Earth</button><button data-body="mars">Mars</button><button data-body="moon">Moon</button>
      </div>
      <button type="button" class="g-more" aria-expanded="false" aria-controls="gPanel">Menu</button>
      <div class="g-panel" id="gPanel">
        <select id="gLayer" aria-label="Imagery"></select>
        <div class="g-chips" id="gChips"></div>
        <p class="g-hint" id="gHint"></p>
      </div>`;
    const more = ui.querySelector('.g-more');
    more.addEventListener('click', () => { const on = ui.classList.toggle('open'); more.setAttribute('aria-expanded', String(on)); more.textContent = on ? 'Close' : 'Menu'; });
    ui.querySelectorAll('[data-body]').forEach((b) => b.addEventListener('click', async () => {
      if (b.dataset.body === body) return;
      body = b.dataset.body; await build(); onBodyChanged();
    }));
    ui.querySelector('#gLayer').addEventListener('change', (e) => { layerChoice[body] = e.target.value; applyLayers(); updateUi(); });
  }

  function updateUi() {
    const ui = document.getElementById(cfg.ui);
    if (!ui || !viewer) return;
    ui.querySelectorAll('[data-body]').forEach((b) => b.classList.toggle('on', b.dataset.body === body));
    const sel = ui.querySelector('#gLayer');
    sel.innerHTML = LAYERS[body].map((l) => `<option value="${l.id}"${l.id === layerChoice[body] ? ' selected' : ''}>${l.label}</option>`).join('');
    const chips = [];
    if (body === 'earth') {
      chips.push(['Pamir', flyPamir]);
      for (const s of cfg.sites || []) chips.push([s.name.split(' ')[0] === 'Lake' ? 'Karakul' : s.name.split(' ')[0], () => flyToSlug(s.slug)]);
      chips.push(['Crater flight', craterFlight]);
    } else {
      for (const r of cfg.refs || []) if (r.body === body) chips.push([r.slug === 'gale' ? 'Gale Crater' : 'South pole', () => flyToSlug(r.slug)]);
      if (body === 'mars') chips.push(['Crater flight', craterFlight]);
    }
    chips.push([spin ? 'Spinning' : 'Spin', () => { spin ? stopSpin() : startSpin(); }], ['Reset', flyHome]);
    const box = ui.querySelector('#gChips');
    box.innerHTML = '';
    chips.forEach(([text, fn]) => {
      const b = document.createElement('button'); b.type = 'button'; b.textContent = text; b.addEventListener('click', fn); box.appendChild(b);
    });
    const hint = {
      earth: `Drag to rotate · scroll or pinch to zoom down to about 10 m per pixel${terrainOn ? ' · real 3D relief' : ''}`,
      mars: 'Mars at the same scale · NASA Viking colour mosaic',
      moon: 'The Moon seen from above its south pole · NASA LRO',
    }[body];
    ui.querySelector('#gHint').textContent = hint;
  }

  function onBodyChanged() { if (cfg.onBody) cfg.onBody(body); }

  // ------------------------------------------------------------------ crater pair (Compare page)
  // Two small globes, Earth and Mars, with the camera at the SAME height and angle above each crater,
  // so a kilometre looks the same size on both. Each viewer gets its own ellipsoid; the shared Cesium
  // default is set right before each one is built and is restored for the main globe in resume().
  let pairViewers = [];

  function destroyPair() {
    pairViewers.forEach((v) => { try { v.destroy(); } catch (e) { /* already gone */ } });
    pairViewers = [];
  }

  function ringFor(b, lat, lon, radiusKm) {
    const kd = KM_PER_DEG[b], out = [];
    for (let i = 0; i <= 120; i++) {
      const a = (i / 120) * 2 * Math.PI;
      out.push(pos(lat + (radiusKm * Math.cos(a)) / kd, lon + (radiusKm * Math.sin(a)) / (kd * Math.cos((lat * Math.PI) / 180)), 250));
    }
    return out;
  }

  function pairViewer(elId, b, spec, rangeKm) {
    ell = ellipsoidFor(b);
    C.Ellipsoid.default = ell;
    const el = document.getElementById(elId);
    el.innerHTML = '';
    const v = new C.Viewer(el, {
      baseLayer: false, baseLayerPicker: false, geocoder: false, homeButton: false, sceneModePicker: false,
      navigationHelpButton: false, animation: false, timeline: false, infoBox: false, selectionIndicator: false,
      fullscreenButton: false, scene3DOnly: true, globe: new C.Globe(ell), mapProjection: new C.GeographicProjection(ell),
      skyAtmosphere: b === 'earth' ? undefined : false,
    });
    v.scene.backgroundColor = C.Color.fromCssColorString('#02050C');
    v.scene.globe.baseColor = C.Color.fromCssColorString('#1a2236');
    v.scene.globe.showGroundAtmosphere = b === 'earth';
    v.scene.screenSpaceCameraController.minimumZoomDistance = 250;
    const layers = b === 'earth' ? [blueMarble(), sentinel()] : LAYERS.mars[0].make();
    layers.forEach((p) => v.imageryLayers.addImageryProvider(p));
    v.entities.add({
      polyline: { positions: ringFor(b, spec.centre[0], spec.centre[1], spec.km / 2), width: 3, arcType: C.ArcType.NONE,
                  material: new C.PolylineDashMaterialProperty({ color: C.Color.fromCssColorString('#F97316'), dashLength: 18 }) },
    });
    v.entities.add({
      position: pos(spec.lat, spec.lon, 250),
      point: { pixelSize: 11, color: C.Color.fromCssColorString('#22D3EE'), outlineColor: C.Color.WHITE, outlineWidth: 2 },
      label: { text: spec.label, font: '600 14px Inter, sans-serif', fillColor: C.Color.WHITE, outlineColor: C.Color.BLACK, outlineWidth: 3,
               style: C.LabelStyle.FILL_AND_OUTLINE, showBackground: true, backgroundColor: C.Color.fromCssColorString('#0A1222D0'),
               verticalOrigin: C.VerticalOrigin.BOTTOM, pixelOffset: new C.Cartesian2(0, -16) },
    });
    v.camera.flyToBoundingSphere(new C.BoundingSphere(pos(spec.centre[0], spec.centre[1], 0), 1000), {
      duration: 0, offset: new C.HeadingPitchRange(0, C.Math.toRadians(-55), rangeKm * 1000),
    });
    return v;
  }

  /** @returns {Promise<boolean>} false when WebGL or the library is not available */
  async function craterPair(o) {
    if (!webglOk()) return false;
    C = await loadCesium();
    destroyPair();
    pairViewers.push(pairViewer(o.earthEl, 'earth', o.earth, o.rangeKm));
    pairViewers.push(pairViewer(o.marsEl, 'mars', o.mars, o.rangeKm));
    return true;
  }

  function pause() { if (viewer) { viewer.useDefaultRenderLoop = false; } }
  function resume() {
    if (!viewer) return;
    if (mainEllipsoid) { ell = mainEllipsoid; C.Ellipsoid.default = mainEllipsoid; }
    viewer.useDefaultRenderLoop = true;
    viewer.resize();
  }

  // ------------------------------------------------------------------ public
  async function init(options) {
    cfg = options;
    if (!webglOk()) return false;
    C = await loadCesium();
    buildUi();
    await build();
    onBodyChanged();
    return true;
  }

  return {
    init,
    resize() { if (viewer) { viewer.resize(); viewer.scene.requestRender(); } },
    highlight, setHeat, setProbe, setProbeLink, setWorld, flyToPoint, craterPair, destroyPair, pause, resume,
    get body() { return body; },
    setCursor(c) { if (viewer) viewer.canvas.style.cursor = c; },
  };
})();
