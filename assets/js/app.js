/* PAMIR TO MARS - frontend.
 * All numbers come from our own PHP API (public/api). Nothing is hard-coded here.
 * Security: every text from the API passes through esc() before it is put into HTML.
 */
'use strict';

// ------------------------------------------------------------------ helpers
const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

async function api(path, params = {}) {
  const qs = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) {
    if (v !== null && v !== undefined && v !== '') qs.append(k, v);
  }
  if (window.P2M_STATIC) return staticApi(path, qs);
  let res = null, data = null;
  try {
    res = await fetch(`api/${path}${qs.toString() ? '?' + qs : ''}`, { headers: { Accept: 'application/json' } });
    try { data = await res.json(); } catch (e) { /* not JSON */ }
  } catch (e) { /* network error: try the saved copy below */ }

  // A 4xx answer is the user's mistake (shown as is). Anything else -> saved copy of the data.
  if (res && res.status >= 400 && res.status < 500 && data && data.error) throw new Error(data.error);
  if (!res || !res.ok || data === null) {
    const saved = await staticCopy(path, qs);
    if (saved) { usingSavedData(); return saved; }
    throw new Error(data && data.error ? data.error : `Request failed (${res ? res.status : 'no connection'})`);
  }
  return data;
}

/** Static demo (no PHP): read the saved JSON; the weights of the ESI sliders are applied in the browser. */
async function staticApi(path, qs) {
  if (path === 'esi.php' && [...qs.keys()].some((k) => k.startsWith('w['))) {
    const base = new URLSearchParams(), weights = {};
    for (const [k, v] of qs) {
      const m = /^w\[(.+)\]$/.exec(k);
      if (m) weights[m[1]] = Number(v); else base.append(k, v);
    }
    const d = await staticCopy(path, base);
    if (!d) throw new Error('This view is not part of the saved demo copy.');
    return StaticProbe.reweigh(d, weights);
  }
  const s = await staticCopy(path, qs);
  if (s) return s;
  throw new Error('This view is not part of the saved demo copy.');
}

/** Backup for the demo: JSON files made by scripts/export_static.php (same names as the API calls). */
async function staticCopy(path, qs) {
  const key = path.replace(/\.php$/, '') + (qs.toString() ? '__' + [...qs].map(([k, v]) => `${k}-${v}`).join('_') : '');
  try {
    const r = await fetch(`static/${key.replace(/[^A-Za-z0-9_-]/g, '_')}.json`, { headers: { Accept: 'application/json' } });
    return r.ok ? await r.json() : null;
  } catch (e) { return null; }
}

function usingSavedData() {
  const box = $('globalError');
  if (!box) return;
  box.textContent = 'Live server is not available. Showing the saved copy of the data (same values, exported earlier from the database).';
  box.classList.remove('hidden');
}

function showError(err) {
  const box = $('globalError');
  box.textContent = `Could not load data: ${err.message}. Check that the database is set up (see README).`;
  box.classList.remove('hidden');
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

function fmt(code, v) {
  if (v === null || v === undefined) return '—';
  switch (code) {
    case 'pressure': return v >= 1000 ? `${(v / 1000).toFixed(1)} kPa` : `${Math.round(v)} Pa`;
    case 'temp_surface': case 'temp_air': case 'temp_range': return `${v.toFixed(1)} °C`;
    case 'solar': return `${v.toFixed(2)} kWh/m²/day`;
    case 'humidity': return `${v.toFixed(0)} %`;
    case 'precip': return `${v.toFixed(2)} mm/day`;
    case 'crater': return Math.round(v) === 1 ? 'Yes' : 'No';
    case 'crater_km': return `${Math.round(v)} km`;
    default: return String(Math.round(v * 100) / 100);
  }
}
const badge = (type) => `<span class="badge b-${esc(type)}">${esc(type).toUpperCase()}</span>`;
const pending = '<span class="badge b-pending">NOT LOADED YET</span>';

/** One value cell: number, data-type badge, source and period. */
function valueCell(code, v) {
  if (!v) return pending;
  const src = v.source_url
    ? `<a href="${esc(v.source_url)}" target="_blank" rel="noopener">${esc(v.source_name)}</a>` : esc(v.source_name);
  return `<span class="mono">${esc(fmt(code, v.value))}</span> ${badge(v.data_type)}
    <div class="muted text-xs mt-1">${src}${v.period ? ' · ' + esc(v.period) : ''}</div>
    ${v.note ? `<div class="muted text-xs">${esc(v.note)}</div>` : ''}`;
}

// ------------------------------------------------------------------ state
const state = { sites: [], refs: [], analogs: [], craters: null, selected: null, weights: {}, defaults: {} };
const charts = {};
const CHART_TEXT = '#8B9BB4';

// ------------------------------------------------------------------ routing
const VIEWS = ['home', 'map', 'compare', 'esi', 'sources'];
function route() {
  const v = VIEWS.includes(location.hash.slice(1)) ? location.hash.slice(1) : 'home';
  document.querySelectorAll('.view').forEach((e) => e.classList.toggle('on', e.id === 'v-' + v));
  document.querySelectorAll('.tab').forEach((e) => e.classList.toggle('on', e.dataset.v === v));
  window.scrollTo(0, 0);
  if (typeof Globe !== 'undefined') { if (mapMode === 'globe' && v !== 'map') Globe.pause(); if (v !== 'compare') Globe.destroyPair(); }
  if (v === 'map') initMap();
  if (v === 'compare') renderCompare();
  if (v === 'esi') renderEsiPage();
  if (v === 'sources') renderSources();
}
document.querySelectorAll('.tab').forEach((b) => b.addEventListener('click', () => { location.hash = b.dataset.v; }));
window.addEventListener('hashchange', route);

// ------------------------------------------------------------------ map: 3D globe, with the flat map as a backup
let map = null, heatLayer = null;       // Leaflet (backup) only
let mapMode = null;                      // null | 'pending' | 'globe' | 'leaflet'
let mapWired = false;
const markers = {};                      // Leaflet markers (backup)
let probeMarker = null;

async function initMap() {
  if (mapMode === 'pending') return;
  if (mapMode === 'globe') { Globe.resume(); return; }
  if (mapMode === 'leaflet') { setTimeout(() => map.invalidateSize(), 50); return; }
  mapMode = 'pending';
  let ok = false;
  try {
    const flat = new URLSearchParams(location.search).has('flat');   // ?flat=1 forces the flat map (slow device, no WebGL)
    ok = !flat && typeof Globe !== 'undefined' && await Globe.init({
      el: 'globe', ui: 'globeUI',
      sites: state.sites, analogs: state.analogs, refs: state.refs, craters: state.craters,
      onLocation: (slug) => { selectSite(slug).then(scrollToSide); },
      onPoint: (lat, lon) => { if ($('probeToggle').checked) probeAt({ lat, lng: lon }); },
      onBody: onGlobeBody,
      ionToken: window.P2M_CESIUM_TOKEN || '',   // optional free Cesium ion token: adds real 3D relief
    });
  } catch (e) { console.warn('3D globe is not available, using the flat map:', e); ok = false; }
  if (ok) {
    mapMode = 'globe';
  } else {
    mapMode = 'leaflet';
    $('globeBox').classList.add('hidden');
    $('map').classList.remove('hidden');
    initLeaflet();
  }
  wireMapControls();
  if (mapMode === 'leaflet' && state.sites.length) selectSite(state.selected || state.sites[0].slug);
}

function wireMapControls() {
  if (mapWired) return;
  mapWired = true;
  $('worldToggle').addEventListener('change', renderWorld);
  $('heatRef').addEventListener('change', () => { if ($('worldToggle').checked) renderWorld(); });
  $('probeToggle').addEventListener('change', () => {
    const on = $('probeToggle').checked;
    $('probeHint').classList.toggle('hidden', !on);
    if (mapMode === 'globe') Globe.setCursor(on ? 'crosshair' : ''); else $('map').style.cursor = on ? 'crosshair' : '';
    if (!on) clearProbeMarker();
  });
}

/** The globe switched to Earth, Mars or the Moon. Heatmap and point analysis exist for Earth only. */
function onGlobeBody(body) {
  const earth = body === 'earth';
  $('heatToggle').disabled = !earth; $('probeToggle').disabled = !earth; $('worldToggle').disabled = !earth;
  if (!earth) {
    $('heatToggle').checked = false; $('probeToggle').checked = false; $('worldToggle').checked = false; $('worldInfo').classList.add('hidden');
    $('probeHint').classList.add('hidden');
    $('heatInfo').textContent = 'The heatmap and point analysis work on Earth: they score Earth places against a Mars or Moon reference.';
    const ref = state.refs.find((r) => r.body === body);
    if (ref) selectSite(ref.slug);
  } else {
    $('heatInfo').textContent = 'Turn the heatmap on to score every NASA POWER grid cell of the region.';
    if (state.sites.length) selectSite(state.selected && state.sites.some((s) => s.slug === state.selected) ? state.selected : state.sites[0].slug);
  }
}

function initLeaflet() {
  if (typeof L === 'undefined') { $('map').innerHTML = '<p class="p-6 muted">The map library could not be loaded (no internet?).</p>'; return; }
  map = L.map('map', { center: [38.45, 73.7], zoom: 7, minZoom: 4, maxZoom: 12 });
  const gibs = L.tileLayer(
    'https://gibs.earthdata.nasa.gov/wmts/epsg3857/best/BlueMarble_ShadedRelief_Bathymetry/default/GoogleMapsCompatible_Level8/{z}/{y}/{x}.jpeg',
    { maxNativeZoom: 8, maxZoom: 12, attribution: 'Imagery: NASA GIBS / Blue Marble' });
  const osm = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',
    { maxZoom: 12, attribution: '&copy; OpenStreetMap contributors' });
  gibs.addTo(map);
  L.control.layers({ 'NASA Blue Marble (GIBS)': gibs, 'OpenStreetMap': osm }).addTo(map);
  L.control.scale({ imperial: false }).addTo(map);

  for (const s of state.sites) {
    const color = s.is_verified ? '#34D399' : '#FBBF24';
    const m = L.circleMarker([s.latitude, s.longitude], { radius: 9, color, weight: 2, fillColor: color, fillOpacity: 0.55, bubblingMouseEvents: false }).addTo(map);
    m.bindTooltip(s.name);
    m.on('click', () => { selectSite(s.slug).then(scrollToSide); });
    markers[s.slug] = m;
  }
  map.on('click', (e) => { if ($('probeToggle').checked) probeAt(e.latlng); });
}

// ------------------------------------------------------------------ analyze any point
/** On a phone the side panel sits below the map: bring it into view after a tap. */
function scrollToSide() {
  if (window.innerWidth < 1024) { $('side').scrollIntoView({ behavior: 'smooth', block: 'start' }); }
}

function clearProbeMarker() {
  if (mapMode === 'globe') Globe.setProbe(null);
  else if (probeMarker && map) { map.removeLayer(probeMarker); probeMarker = null; }
}

/** @param {{lat:number,lng:number}} latlng a clicked point (a Leaflet LatLng also works) */
async function probeAt(latlng) {
  const side = $('side');
  const w = typeof latlng.wrap === 'function' ? latlng.wrap() : { lat: latlng.lat, lng: ((latlng.lng + 540) % 360) - 180 };
  clearProbeMarker();
  if (mapMode === 'globe') Globe.setProbe(w.lat, w.lng);
  else probeMarker = L.circleMarker(w, { radius: 9, color: '#F97316', weight: 3, fillColor: '#F97316', fillOpacity: 0.5, bubblingMouseEvents: false }).addTo(map);
  side.innerHTML = '<div class="kicker">Analyzed point</div><p class="mt-3 muted">Asking NASA POWER for the climate of this grid cell… (a new cell can take a few seconds)</p>';
  try {
    const d = window.P2M_STATIC
      ? await StaticProbe.run(w.lat, w.lng, $('heatRef').value, { api, state })
      : await api('probe.php', { lat: w.lat.toFixed(4), lon: w.lng.toFixed(4), ref: $('heatRef').value });
    renderProbe(d);
    scrollToSide();
  } catch (e) { side.innerHTML = `<div class="kicker">Analyzed point</div><p class="errorbox mt-3">${esc(e.message)}</p>`; }
}

/** A short plain-language reading of an analyzed point. Templates only: no AI, every sentence comes from the numbers. */
function explainPoint(d) {
  const e = d.esi;
  const val = (c) => { const q = d.parameters.find((x) => x.code === c); return q && q.value ? q.value.value : null; };
  const refVal = (c) => { const q = e.parameters.find((x) => x.code === c); return q && q.reference ? q.reference.value : null; };
  const refName = ((state.refs.find((r) => r.slug === d.reference) || {}).name || 'the reference site').split(',')[0];
  const out = [];
  const t = val('temp_surface'), rt = refVal('temp_surface');
  if (t !== null && rt !== null) {
    const diff = t - rt;
    out.push(Math.abs(diff) < 12
      ? `The ground here averages ${t.toFixed(0)} °C, within ${Math.abs(diff).toFixed(0)} K of ${refName} (${rt.toFixed(0)} °C, a one-month average).`
      : `The ground here averages ${t.toFixed(0)} °C, about ${Math.abs(diff).toFixed(0)} K ${diff > 0 ? 'warmer' : 'colder'} than ${refName} (${rt.toFixed(0)} °C, a one-month average).`);
  }
  const pr = val('pressure'), rp = refVal('pressure');
  if (pr !== null && rp !== null && rp > 0) {
    out.push(`The air is about ${Math.round(pr / rp)}× denser than at ${refName} (${(pr / 1000).toFixed(0)} kPa against ${Math.round(rp)} Pa). No place on Earth matches Mars in pressure.`);
  }
  const rh = val('humidity');
  if (rh !== null) out.push(rh < 40 ? `The air is dry (relative humidity ${rh.toFixed(0)} %); dryness is not part of the index.` : `Relative humidity is ${rh.toFixed(0)} %; dryness is not part of the index.`);
  if (e.esi !== null && e.reliable) {
    out.push(e.esi >= 0.6 ? `On the parameters we can compare, it is among the closer matches to ${refName}.`
      : e.esi >= 0.45 ? `On the parameters we can compare, it is partly similar to ${refName}.`
      : `On the parameters we can compare, it is far from ${refName}.`);
  } else {
    out.push('There is too little comparable data for a verdict.');
  }
  const nb = d.fingerprint.available && d.fingerprint.neighbours.length ? d.fingerprint.neighbours[0] : null;
  if (nb) out.push(`Its climate is closest to a known analog: ${nb.name} (distance ${nb.distance.toFixed(2)}).`);
  return out.join(' ');
}

function renderProbe(d) {
  const e = d.esi, p = d.point;
  const refName = (state.refs.find((r) => r.slug === d.reference) || {}).name || d.reference;
  const shown = e.esi !== null && e.reliable;
  const color = !shown ? 'var(--muted)' : e.esi > 0.6 ? 'var(--green)' : e.esi > 0.35 ? 'var(--cyan)' : 'var(--mars)';
  const rows = d.parameters.map((q) => `<dt>${esc(q.name)}</dt><dd>${q.value ? `<span class="mono">${esc(fmt(q.code, q.value.value))}</span> ${badge(q.value.data_type)}` : '<span class="badge b-pending">UNKNOWN HERE</span>'}</dd>`).join('');
  const fp = d.fingerprint.available && d.fingerprint.neighbours.length
    ? `<div class="mt-4"><b style="color:var(--text)">Closest known analogs (climate):</b><ol class="list-decimal pl-5 mt-1">${d.fingerprint.neighbours.slice(0, 3).map((n) => `<li>${esc(n.name)} <span class="mono muted">· distance ${n.distance.toFixed(2)}</span></li>`).join('')}</ol></div>` : '';
  $('side').innerHTML = `
    <div class="kicker">Analyzed point</div>
    <h3 class="text-xl font-bold mt-1 mb-1 mono">${p.clicked_lat.toFixed(2)}°, ${p.clicked_lon.toFixed(2)}°</h3>
    <p class="muted text-sm mb-3">NASA POWER cell centre ${p.cell_lat.toFixed(2)}°, ${p.cell_lon.toFixed(3)}°${p.cell_elevation_m !== null ? ' · cell mean elevation ' + Math.round(p.cell_elevation_m).toLocaleString() + ' m' : ''}${p.cached ? ' · from our saved copy' : ''}</p>
    <div class="muted">ESI vs ${esc(refName)}</div>
    <div class="esi-big" style="color:${color}">${shown ? e.esi.toFixed(2) : 'n/a'}</div>
    <p class="muted text-sm mb-3">Data completeness: <b style="color:var(--text)">${e.completeness.available} / ${e.completeness.total}</b> parameters${!shown && e.esi !== null ? ' · <span class="badge b-warn">TOO LITTLE DATA</span>' : ''}</p>
    <div class="mt-1 mb-4 p-3 rounded-lg text-sm" style="background:#0F1830;border-left:3px solid var(--mars)"><b style="color:var(--text)">In plain words</b><br><span class="muted">${esc(explainPoint(d))}</span></div>
    <dl class="kv">${rows}</dl>
    ${fp}
    <ul class="list-disc pl-5 mt-4 space-y-1 muted text-sm">${d.notes.map((n) => `<li>${esc(n)}</li>`).join('')}</ul>
    <p class="disclaimer mt-4">${esc(d.disclaimer)}</p>`;
}

async function selectSite(slug) {
  state.selected = slug;
  if (mapMode === 'globe') Globe.highlight(slug);
  else for (const [k, m] of Object.entries(markers)) m.setStyle({ radius: k === slug ? 13 : 9, weight: k === slug ? 3 : 2 });
  const side = $('side');
  try {
    const d = await api('location.php', { slug });
    const l = d.location;
    const rows = d.parameters.map((p) => `<dt>${esc(p.name)}</dt><dd>${p.value ? `<span class="mono">${esc(fmt(p.code, p.value.value))}</span> ${badge(p.value.data_type)}` : pending}</dd>`).join('');
    // Compare buttons: a site is compared with every reference; a reference with the first site.
    let buttons = [];
    if (l.kind === 'site') {
      buttons = state.refs.map((r) => ({ earth: slug, ref: r.slug, text: `Compare with ${r.body === 'mars' ? 'Mars' : 'Moon'}` }));
    } else if (l.kind === 'reference' && state.sites.length) {
      buttons = [{ earth: state.sites[0].slug, ref: slug, text: `Compare ${state.sites[0].name.replace(' impact structure', '')} with this` }];
    }
    side.innerHTML = `
      <div class="kicker">${l.kind === 'known_analog' ? 'Known Earth analog' : l.kind === 'reference' ? 'Reference site' : 'Selected site'}</div>
      <h3 class="text-xl font-bold mt-1 mb-2">${esc(l.name)}</h3>
      <p class="mb-3">${l.is_verified ? '<span class="badge b-measured">LOCATION VERIFIED</span>' : '<span class="badge b-warn">LOCATION TO VERIFY</span>'}</p>
      <p class="muted mb-4">${esc(l.description)}</p>
      <dl class="kv">
        <dt>Coordinates</dt><dd class="mono">${l.latitude.toFixed(2)}°, ${l.longitude.toFixed(2)}°</dd>
        <dt>Elevation</dt><dd class="mono">${l.elevation_m === null ? '—' : Math.round(l.elevation_m).toLocaleString() + ' m'} <span class="muted text-xs" style="font-family:Inter">${esc(l.elevation_ref || '')}</span></dd>
        ${rows}
      </dl>
      <div class="flex flex-wrap gap-2 mt-5">
        ${buttons.map((b, i) => `<button class="btn ${i === 0 ? 'pri' : ''}" data-earth="${esc(b.earth)}" data-ref="${esc(b.ref)}">${esc(b.text)}</button>`).join('')}
      </div>`;
    side.querySelectorAll('button[data-ref]').forEach((b) => b.addEventListener('click', () => {
      $('cE').value = b.dataset.earth; $('eE').value = b.dataset.earth; $('cR').value = b.dataset.ref; $('eR').value = b.dataset.ref;
      location.hash = 'compare';
    }));
  } catch (e) { side.innerHTML = `<p class="errorbox">${esc(e.message)}</p>`; }
}

function heatColor(t) { // 0..1 -> blue, cyan, green, yellow, orange
  const stops = [[30, 58, 138], [34, 211, 238], [52, 211, 153], [251, 191, 36], [249, 115, 22]];
  const x = Math.min(0.9999, Math.max(0, t)) * (stops.length - 1), i = Math.floor(x), f = x - i;
  const c = stops[i].map((a, k) => Math.round(a + (stops[i + 1][k] - a) * f));
  return `rgb(${c[0]},${c[1]},${c[2]})`;
}

function clearHeat() {
  if (mapMode === 'globe') Globe.setHeat(null);
  else if (heatLayer && map) { map.removeLayer(heatLayer); heatLayer = null; }
}

async function renderHeatmap() {
  if (mapMode !== 'globe' && !map) return;
  clearHeat();
  const info = $('heatInfo');
  if (!$('heatToggle').checked) { info.textContent = 'Turn the heatmap on to score every NASA POWER grid cell of the region.'; return; }
  info.textContent = 'Calculating…';
  try {
    const d = await api('heatmap.php', { ref: $('heatRef').value });
    if (!d.count) { info.innerHTML = `<span class="badge b-pending">NO GRID DATA</span> ${esc(d.note)}`; return; }
    const vals = d.cells.map((c) => c.esi), lo = Math.min(...vals), hi = Math.max(...vals);
    const tip = (c) => `ESI ${c.esi.toFixed(2)} · ${c.available}/${c.total} parameters${c.elevation_m !== null ? ' · cell mean ' + Math.round(c.elevation_m) + ' m' : ''}`;
    const tOf = (c) => (hi > lo ? (c.esi - lo) / (hi - lo) : 0.5);
    if (mapMode === 'globe') {
      Globe.setHeat(d.cells.map((c) => ({ lat: c.lat, lon: c.lon, color: heatColor(tOf(c)), tip: tip(c) })), d.cell_size);
    } else {
      heatLayer = L.layerGroup().addTo(map);
      const hl = d.cell_size.lat / 2, hw = d.cell_size.lon / 2;
      for (const c of d.cells) {
        L.rectangle([[c.lat - hl, c.lon - hw], [c.lat + hl, c.lon + hw]],
          { color: '#0A1222', weight: 1, fillColor: heatColor(tOf(c)), fillOpacity: 0.55 }).bindTooltip(tip(c)).addTo(heatLayer);
      }
      for (const m of Object.values(markers)) m.bringToFront();
    }
    info.innerHTML = `
      <div class="flex items-center gap-3 mono text-xs"><span>${lo.toFixed(2)}</span><div class="legend-bar flex-1"></div><span>${hi.toFixed(2)}</span></div>
      <div class="muted text-xs mt-1">Colours are stretched between the lowest and highest cell, so they show ranking, not large differences.</div>
      <div class="mt-3"><b style="color:var(--text)">Most similar cells:</b>
        <ol class="list-decimal pl-5 mt-1">${d.top5.map((c) => `<li><span class="mono">${c.lat.toFixed(2)}°N, ${c.lon.toFixed(3)}°E</span> · ESI ${c.esi.toFixed(2)}</li>`).join('')}</ol></div>
      <p class="mt-2">${esc(d.note)}</p>`;
  } catch (e) { info.innerHTML = `<span class="errorbox block">${esc(e.message)}</span>`; }
}

// ------------------------------------------------------------------ world scan
let worldLayer = null;

function clearWorld() {
  if (mapMode === 'globe') Globe.setWorld(null);
  else if (worldLayer && map) { map.removeLayer(worldLayer); worldLayer = null; }
}

/** "Where on Earth is most Mars-like?": the saved scan (static/worldscan__ref-*.json) drawn on the globe. */
async function renderWorld() {
  const box = $('worldInfo');
  clearWorld();
  if (!$('worldToggle').checked) { box.classList.add('hidden'); return; }
  box.classList.remove('hidden');
  box.textContent = 'Loading the world scan…';
  try {
    const res = await fetch(`static/worldscan__ref-${encodeURIComponent($('heatRef').value)}.json`);
    if (!res.ok) throw new Error('The world scan exists for Gale Crater only.');
    const d = await res.json();
    // Land only: cells whose mean elevation is below 30 m are mostly sea and are left out.
    const cells = d.cells.filter((c) => c.esi !== null && c.elev !== null && c.elev > 30);
    if (!cells.length) throw new Error('No cells in the saved scan.');
    const vals = cells.map((c) => c.esi), lo = Math.min(...vals), hi = Math.max(...vals);
    const tOf = (c) => (hi > lo ? (c.esi - lo) / (hi - lo) : 0.5);
    const tip = (c) => `ESI ${c.esi.toFixed(2)} · ${Math.round(c.elev)} m · ${c.t} °C`;
    if (mapMode === 'globe') {
      Globe.setWorld(cells.map((c) => ({ lat: c.lat, lon: c.lon, color: heatColor(tOf(c)) })), d.step);
    } else {
      worldLayer = L.layerGroup().addTo(map);
      const h = d.step / 2;
      for (const c of cells) {
        L.rectangle([[Math.max(-90, c.lat - h), c.lon - h], [Math.min(90, c.lat + h), c.lon + h]],
          { color: '#0A1222', weight: 1, fillColor: heatColor(tOf(c)), fillOpacity: 0.5 }).bindTooltip(tip(c)).addTo(worldLayer);
      }
    }
    const item = (c) => `<li><button class="wgo mono" data-lat="${c.lat}" data-lon="${c.lon}" style="text-decoration:underline;cursor:pointer;background:none;border:0;color:inherit;padding:2px 0">${Math.abs(c.lat)}°${c.lat < 0 ? 'S' : 'N'}, ${Math.abs(c.lon)}°${c.lon < 0 ? 'W' : 'E'}</button> · ESI ${c.esi.toFixed(2)} · ${Math.round(c.elev).toLocaleString()} m · ${c.t} °C</li>`;
    const top = [...cells].sort((a, b) => b.esi - a.esi);
    const mid = top.filter((c) => Math.abs(c.lat) < 60);
    box.innerHTML = `
      <div class="flex items-center gap-3 mono text-xs"><span>${lo.toFixed(2)}</span><div class="legend-bar flex-1"></div><span>${hi.toFixed(2)}</span></div>
      <div class="mt-3"><b style="color:var(--text)">Most Mars-like cells on Earth</b> (click to fly there)
        <ol class="list-decimal pl-5 mt-1">${top.slice(0, 5).map(item).join('')}</ol></div>
      <div class="mt-3"><b style="color:var(--text)">Best outside the polar regions</b> (latitude below 60°)
        <ol class="list-decimal pl-5 mt-1">${mid.slice(0, 5).map(item).join('')}</ol></div>
      <p class="mt-3">${esc(d.note)} Cells are ${d.step}° wide (about ${Math.round(d.step * 111)} km) and sea cells are hidden. Scan date: ${esc(d.generated)}.</p>`;
    box.querySelectorAll('button.wgo').forEach((b) => b.addEventListener('click', () => {
      const lat = Number(b.dataset.lat), lon = Number(b.dataset.lon);
      if (mapMode === 'globe') Globe.flyToPoint(lat, lon, 2500000); else if (map) map.setView([lat, lon], 5);
    }));
  } catch (e) { box.innerHTML = `<span class="errorbox block">${esc(e.message)}</span>`; }
}

// ------------------------------------------------------------------ compare
async function renderCompare() {
  if (!$('cE').value || !$('cR').value) return;
  try {
    const d = await api('compare.php', { earth: $('cE').value, ref: $('cR').value });
    const elev = (x) => x.elevation_m === null ? '—' : `${Math.round(x.elevation_m).toLocaleString()} m`;
    let html = `<tr><td><b>Elevation</b></td>
      <td><span class="mono">${elev(d.earth)}</span><div class="muted text-xs mt-1">${esc(d.earth.elevation_ref || '')}</div></td>
      <td><span class="mono">${elev(d.reference)}</span><div class="muted text-xs mt-1">${esc(d.reference.elevation_ref || '')}</div></td>
      <td class="muted text-sm">Different reference surfaces: not subtracted</td></tr>`;
    for (const r of d.rows) {
      let diff = '<span class="muted">—</span>';
      if (r.difference) {
        if (r.difference.kind === 'ratio') {
          const x = r.difference.value;
          diff = x >= 1 ? `<b>${x.toFixed(x >= 10 ? 0 : 1)}×</b> higher on Earth` : `<b>${(1 / x).toFixed(1)}×</b> lower on Earth`;
        } else if (r.difference.kind === 'match') {
          diff = r.difference.value ? '<span style="color:var(--green)">Same</span>' : 'Different';
        } else {
          diff = `<span class="mono">${r.difference.value > 0 ? '+' : ''}${r.difference.value}</span> <span class="muted text-xs">${esc(r.unit)}</span>`;
        }
      }
      html += `<tr><td><b>${esc(r.name)}</b><div class="muted text-xs">${r.in_esi ? 'in the index' : 'context only'}</div></td>
        <td>${valueCell(r.code, r.earth)}</td><td>${valueCell(r.code, r.reference)}</td><td>${diff}</td></tr>`;
    }
    $('ctab').innerHTML = html;
    $('climits').innerHTML = d.limitations.map((t) => `<li>${esc(t)}</li>`).join('');
    renderCraters(d);

    const p = d.rows.find((r) => r.code === 'pressure');
    const eP = p && p.earth ? p.earth.value : null, rP = p && p.reference ? p.reference.value : null;
    if (charts.p) charts.p.destroy();
    charts.p = new Chart($('chP'), {
      type: 'bar',
      data: { labels: ['Sea level (Earth)', d.earth.name, d.reference.name],
              datasets: [{ data: [101325, eP, rP], backgroundColor: ['#334155', '#22D3EE', '#F97316'], borderRadius: 6 }] },
      options: { maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => c.raw === null ? 'no value' : `${Math.round(c.raw).toLocaleString()} Pa` } } },
        scales: { y: { type: 'logarithmic', min: 100, max: 200000, ticks: { color: CHART_TEXT, callback: (v) => [100, 1000, 10000, 100000].includes(v) ? v.toLocaleString() + ' Pa' : '' }, grid: { color: '#22304d' } },
                  x: { ticks: { color: CHART_TEXT }, grid: { display: false } } } },
    });
  } catch (e) { $('ctab').innerHTML = `<tr><td colspan="4"><div class="errorbox">${esc(e.message)}</div></td></tr>`; }
}

// ------------------------------------------------------------------ crater to crater
const BODY_RADIUS_KM = { earth: 6371.0088, mars: 3389.5 };
// Approximate centre of Gale Crater (literature). The landing site itself comes from the database.
const GALE_CRATER_CENTRE = [-5.4, 137.8];
const MARS_TILES = 'https://trek.nasa.gov/tiles/Mars/EQ/Mars_MGS_MOLA_ClrShade_merge_global_463m/1.0.0/default/default028mm/{z}/{y}/{x}.jpg';
const EARTH_TILES = 'https://gibs.earthdata.nasa.gov/wmts/epsg3857/best/BlueMarble_ShadedRelief_Bathymetry/default/GoogleMapsCompatible_Level8/{z}/{y}/{x}.jpeg';
const WINDOW_KM = 240;
const craterMaps = [];

const kmPerDeg = (body) => (Math.PI * BODY_RADIUS_KM[body]) / 180;
function ringPoints(lat, lon, radiusKm, body) {
  const kd = kmPerDeg(body), pts = [];
  for (let i = 0; i < 120; i++) {
    const a = (i / 120) * 2 * Math.PI;
    pts.push([lat + (radiusKm * Math.cos(a)) / kd, lon + (radiusKm * Math.sin(a)) / (kd * Math.cos((lat * Math.PI) / 180))]);
  }
  return pts;
}
function windowBounds(lat, lon, widthKm, heightKm, body) {
  const kd = kmPerDeg(body), dLat = heightKm / 2 / kd, dLon = widthKm / 2 / (kd * Math.cos((lat * Math.PI) / 180));
  return [[lat - dLat, lon - dLon], [lat + dLat, lon + dLon]];
}

let craterToken = 0;
async function renderCraters(d) {
  const token = ++craterToken;
  craterMaps.splice(0).forEach((m) => m.remove());
  if (typeof Globe !== 'undefined') Globe.destroyPair();
  const card = $('craterCard');
  const rowKm = d.rows.find((r) => r.code === 'crater_km');
  const earth = state.sites.find((s) => s.slug === $('cE').value);
  const ref = state.refs.find((s) => s.slug === $('cR').value);
  if (!rowKm || !rowKm.earth || !rowKm.reference || !earth || !ref || ref.body !== 'mars') {
    card.classList.add('hidden'); return;
  }
  card.classList.remove('hidden');
  const eKm = rowKm.earth.value, rKm = rowKm.reference.value;
  $('capE').innerHTML = `<b>${esc(earth.name)}</b>: about ${Math.round(eKm)} km across`;
  $('capM').innerHTML = `<b>${esc(ref.name)}</b>: about ${Math.round(rKm)} km across · <span class="muted">${(rKm / eKm).toFixed(1)}× wider than the Earth structure</span>`;

  // First choice: two small 3D globes at the same camera height. Backup: the flat same-scale maps below.
  if (!new URLSearchParams(location.search).has('flat') && typeof Globe !== 'undefined') {
    let ok = false;
    try {
      ok = await Globe.craterPair({
        earthEl: 'mapE', marsEl: 'mapM', rangeKm: 160,
        earth: { lat: earth.latitude, lon: earth.longitude, centre: [earth.latitude, earth.longitude], km: eKm, label: earth.name.replace(' impact structure', '') },
        mars: { lat: ref.latitude, lon: ref.longitude, centre: ref.slug === 'gale' ? GALE_CRATER_CENTRE : [ref.latitude, ref.longitude], km: rKm, label: 'Curiosity landing site' },
      });
    } catch (e) { console.warn('3D crater globes are not available, using flat maps:', e); }
    if (token !== craterToken) { Globe.destroyPair(); return; }   // a newer choice took over
    if (ok) {
      $('craterIntro').textContent = 'Both globes look at the crater from the same height (160 km) and angle, so a kilometre is the same size on Earth and on Mars. Drag to look around. The orange ring marks the impact structure.';
      $('craterSrc').textContent = 'Earth: NASA Blue Marble and ESA Sentinel-2. Mars: NASA Viking colour mosaic (NASA/JPL Mars Trek). The Gale ring is centred on the approximate crater centre; the pin is the Curiosity landing site. Elevations of the two bodies are not compared (different datums).';
      return;
    }
  }
  if (typeof L === 'undefined') { card.classList.add('hidden'); return; }
  $('craterIntro').textContent = 'Both pictures are 240 km wide, so a kilometre looks the same size on Earth and on Mars. The orange ring marks the impact structure.';
  const box = $('mapE').getBoundingClientRect();
  const heightKm = WINDOW_KM * (box.height / box.width);
  const opts = { zoomSnap: 0, zoomControl: false, dragging: false, scrollWheelZoom: false, doubleClickZoom: false, boxZoom: false, keyboard: false, touchZoom: false };

  const mE = L.map('mapE', opts);
  L.tileLayer(EARTH_TILES, { maxNativeZoom: 8, maxZoom: 14, attribution: 'NASA GIBS' }).addTo(mE);
  mE.fitBounds(windowBounds(earth.latitude, earth.longitude, WINDOW_KM, heightKm, 'earth'), { animate: false });
  L.polygon(ringPoints(earth.latitude, earth.longitude, eKm / 2, 'earth'), { color: '#F97316', weight: 3, fill: false }).addTo(mE);
  L.circleMarker([earth.latitude, earth.longitude], { radius: 5, color: '#fff', weight: 2, fillColor: '#22D3EE', fillOpacity: 1 }).addTo(mE);

  const centre = ref.slug === 'gale' ? GALE_CRATER_CENTRE : [ref.latitude, ref.longitude];
  const mM = L.map('mapM', Object.assign({ crs: L.CRS.EPSG4326 }, opts));
  L.tileLayer(MARS_TILES, { maxNativeZoom: 7, maxZoom: 12, attribution: 'NASA/JPL Mars Trek · MOLA' }).addTo(mM);
  mM.fitBounds(windowBounds(centre[0], centre[1], WINDOW_KM, heightKm, 'mars'), { animate: false });
  L.polygon(ringPoints(centre[0], centre[1], rKm / 2, 'mars'), { color: '#F97316', weight: 3, fill: false }).addTo(mM);
  L.circleMarker([ref.latitude, ref.longitude], { radius: 5, color: '#fff', weight: 2, fillColor: '#22D3EE', fillOpacity: 1 }).bindTooltip('Curiosity landing site').addTo(mM);
  craterMaps.push(mE, mM);

  $('capE').innerHTML = `<b>${esc(earth.name)}</b>: about ${Math.round(eKm)} km across`;
  $('capM').innerHTML = `<b>${esc(ref.name)}</b>: about ${Math.round(rKm)} km across · <span class="muted">${(rKm / eKm).toFixed(1)}× wider than the Earth structure</span>`;
}

// ------------------------------------------------------------------ ESI
let esiTimer = null;
function renderEsiPage() { renderEsi(true); renderSeasons(); renderFingerprint(); }

async function renderEsi(rebuildSliders) {
  if (!$('eE').value || !$('eR').value) return;
  const params = { earth: $('eE').value, ref: $('eR').value };
  for (const [k, w] of Object.entries(state.weights)) params[`w[${k}]`] = w;
  try {
    const d = await api('esi.php', params);
    const v = $('esiV');
    // An index from one parameter (e.g. the Moon: only crater geology) is not shown as a result.
    v.textContent = d.esi === null || !d.reliable ? 'n/a' : d.esi.toFixed(2);
    v.style.color = d.esi === null || !d.reliable ? 'var(--muted)' : d.esi > 0.6 ? 'var(--green)' : d.esi > 0.35 ? 'var(--cyan)' : 'var(--mars)';
    $('esiWarn').innerHTML = d.esi !== null && !d.reliable
      ? `<span class="badge b-warn">TOO LITTLE DATA</span> <span class="muted text-sm">Only ${d.completeness.available} of ${d.completeness.total} parameters can be compared (value would be ${d.esi.toFixed(2)}), so no index is shown.</span>` : '';
    $('esiC').innerHTML = `Data completeness: <b style="color:var(--text)">${d.completeness.available} / ${d.completeness.total}</b> parameters`;
    $('esiCB').style.width = `${d.completeness.total ? (d.completeness.available / d.completeness.total) * 100 : 0}%`;

    for (const p of d.parameters) {
      if (!(p.code in state.defaults)) state.defaults[p.code] = p.weight;
    }
    if (rebuildSliders) {
      $('wlist').innerHTML = d.parameters.map((p) => `
        <div class="mb-4" data-code="${esc(p.code)}">
          <div class="flex flex-wrap justify-between items-center gap-2">
            <span><b>${esc(p.name)}</b> <span class="js-flag"></span></span>
            <span class="mono js-sim"></span>
          </div>
          <div class="muted text-xs js-vals"></div>
          <div class="flex items-center gap-3 mt-1">
            <span class="muted mono text-xs w-14 js-w"></span>
            <input type="range" min="0" max="5" step="1" value="${p.weight}" aria-label="Weight of ${esc(p.name)}">
          </div>
        </div>`).join('');
      $('wlist').querySelectorAll('input[type=range]').forEach((inp) => inp.addEventListener('input', () => {
        const code = inp.closest('[data-code]').dataset.code;
        state.weights[code] = inp.value;
        inp.closest('[data-code]').querySelector('.js-w').textContent = `w = ${inp.value}`;
        clearTimeout(esiTimer);
        esiTimer = setTimeout(() => renderEsi(false), 120);
      }));
    }
    for (const p of d.parameters) {
      const row = $('wlist').querySelector(`[data-code="${p.code}"]`);
      if (!row) continue;
      const missing = p.similarity === null;
      row.querySelector('.js-flag').innerHTML = missing ? '<span class="badge b-pending">EXCLUDED</span>' : '';
      const sim = row.querySelector('.js-sim');
      sim.textContent = missing ? 'no value' : `s = ${p.similarity.toFixed(2)}`;
      sim.style.color = missing ? 'var(--bad)' : 'var(--cyan)';
      row.querySelector('.js-vals').textContent =
        `Earth ${p.earth ? fmt(p.code, p.earth.value) + ' (' + p.earth.data_type + ')' : 'not loaded'} · Reference ${p.reference ? fmt(p.code, p.reference.value) + ' (' + p.reference.data_type + ')' : 'not available'}`;
      row.querySelector('.js-w').textContent = `w = ${p.weight}`;
    }
  } catch (e) { $('esiV').textContent = '–'; $('esiC').innerHTML = `<span class="errorbox block">${esc(e.message)}</span>`; }
}

async function renderSeasons() {
  try {
    const d = await api('seasons.php', { earth: $('eE').value, ref: $('eR').value });
    const best = d.best_month ? d.best_month.month : null;
    if (charts.s) charts.s.destroy();
    charts.s = new Chart($('chS'), {
      type: 'line',
      data: { labels: MONTHS, datasets: [{ data: d.months.map((m) => (m.monthly_parameters > 0 ? m.esi : null)),
        borderColor: '#22D3EE', backgroundColor: '#22D3EE33', fill: true, tension: 0.3, spanGaps: false,
        pointRadius: d.months.map((m) => (m.month === best ? 7 : 3)),
        pointBackgroundColor: d.months.map((m) => (m.month === best ? '#34D399' : '#22D3EE')) }] },
      options: { maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => c.raw === null ? 'no monthly data' : `ESI ${c.raw.toFixed(2)}` } } },
        scales: { y: { suggestedMin: 0, suggestedMax: 1, ticks: { color: CHART_TEXT }, grid: { color: '#22304d' } },
                  x: { ticks: { color: CHART_TEXT }, grid: { display: false } } } },
    });
    $('seasonNote').textContent = (best ? `Highest index: ${MONTHS[best - 1]} (ESI ${d.best_month.esi.toFixed(2)}, default weights). ` +
      'This is about environmental similarity only: winter access to the Pamir is difficult. ' : '') + d.note + (d.caveat ? ' ' + d.caveat : '');
  } catch (e) { $('seasonNote').textContent = e.message; }
}

async function renderFingerprint() {
  try {
    const d = await api('fingerprint.php', { earth: $('eE').value });
    if (!d.available || !d.neighbours.length) {
      $('fpList').innerHTML = pending; $('fpNote').textContent = d.note; return;
    }
    const max = Math.max(...d.neighbours.map((n) => n.distance)) || 1;
    $('fpList').innerHTML = d.neighbours.map((n, i) => `
      <div class="mb-3">
        <div class="flex justify-between gap-3"><span>${i === 0 ? '<b>' : ''}${esc(n.name)}${i === 0 ? '</b> <span class="badge b-measured">CLOSEST</span>' : ''}</span>
          <span class="mono muted">distance ${n.distance.toFixed(2)}</span></div>
        <div class="bar mt-1"><i style="width:${Math.max(4, (1 - n.distance / (max * 1.15)) * 100)}%"></i></div>
      </div>`).join('');
    $('fpNote').textContent = d.note;
  } catch (e) { $('fpNote').textContent = e.message; }
}

// ------------------------------------------------------------------ sources
let sourcesLoaded = false;
async function renderSources() {
  if (sourcesLoaded) return;
  try {
    const d = await api('sources.php');
    $('stab').innerHTML = d.sources.map((s) => `<tr>
      <td><b><a href="${esc(s.url)}" target="_blank" rel="noopener">${esc(s.name)}</a></b></td>
      <td class="muted">${esc(s.provider)}</td><td class="muted">${esc(s.access_method)}</td>
      <td class="muted">${esc(s.resolution || '—')}</td><td class="muted">${esc(s.limitations || '—')}</td>
      <td class="mono">${Number(s.values_stored) > 0 ? esc(s.values_stored) : '<span class="muted">0</span>'}</td></tr>`).join('');
    const how = { log_ratio: 'Logarithm of the ratio', abs_kelvin: 'Absolute difference in kelvin', relative: 'Relative difference', binary: 'Same or different', none: '—' };
    $('ptab').innerHTML = d.parameters.map((p) => `<tr><td><b>${esc(p.name)}</b></td><td class="mono">${esc(p.unit)}</td>
      <td>${p.esi_method === 'none' ? '<span class="muted">context</span>' : 'in the index'}</td>
      <td class="mono">${p.esi_method === 'none' ? '—' : esc(p.default_weight)}</td>
      <td class="muted">${esc(how[p.esi_method] || '')}. ${esc(p.explanation || '')}</td></tr>`).join('');
    sourcesLoaded = true;
  } catch (e) { $('stab').innerHTML = `<tr><td colspan="6"><div class="errorbox">${esc(e.message)}</div></td></tr>`; }
}

// ------------------------------------------------------------------ start
async function start() {
  if (window.P2M_STATIC) { const n = $('demoNote'); n.classList.remove('hidden'); n.style.display = 'block'; }
  if (typeof Chart !== 'undefined') { Chart.defaults.font.family = 'Inter'; Chart.defaults.color = CHART_TEXT; }
  try {
    const [sites, refs] = await Promise.all([api('locations.php', { kind: 'site' }), api('locations.php', { kind: 'reference' })]);
    state.sites = sites.locations; state.refs = refs.locations;
    const opts = (arr) => arr.map((s) => `<option value="${esc(s.slug)}">${esc(s.name)}</option>`).join('');
    ['cE', 'eE'].forEach((id) => { $(id).innerHTML = opts(state.sites); });
    ['cR', 'eR', 'heatRef'].forEach((id) => { $(id).innerHTML = opts(state.refs); });

    $('cE').addEventListener('change', renderCompare); $('cR').addEventListener('change', renderCompare);
    const esiChange = () => { state.weights = {}; renderEsiPage(); };
    $('eE').addEventListener('change', esiChange); $('eR').addEventListener('change', esiChange);
    $('resetW').addEventListener('click', () => { state.weights = {}; renderEsi(true); });
    $('heatToggle').addEventListener('change', renderHeatmap); $('heatRef').addEventListener('change', renderHeatmap);

    // Headline numbers on the overview, the known analogs and the crater sizes come from the database too.
    if (state.sites.length && state.refs.length) {
      const [an, cmp] = await Promise.all([
        api('locations.php', { kind: 'known_analog' }).catch(() => ({ locations: [] })),
        api('compare.php', { earth: state.sites[0].slug, ref: state.refs[0].slug }).catch(() => null),
      ]);
      state.analogs = an.locations;
      if (cmp) {
        const p = cmp.rows.find((r) => r.code === 'pressure');
        if (p && p.reference) $('statGale').textContent = `~${Math.round(p.reference.value)} Pa`;
        if (p && p.difference && p.difference.kind === 'ratio') $('statRatio').textContent = `~${Math.round(p.difference.value)}×`;
        const km = cmp.rows.find((r) => r.code === 'crater_km');
        if (km && km.earth && km.reference) state.craters = { earthKm: km.earth.value, marsKm: km.reference.value };
      }
    }
  } catch (e) { showError(e); }
  route();
}
start();
