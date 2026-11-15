/* PAMIR TO MARS - static demo helpers (used only when window.P2M_STATIC is true, for example on GitHub Pages).
 * There is no PHP or MySQL there, so two things the server normally does are done in the browser:
 *   1. ESI with custom weights, from the saved per-parameter similarities;
 *   2. "Analyze any point": NASA POWER is asked directly (it allows browser requests), then the same
 *      maths as src/Esi.php, src/Fingerprint.php and public/api/probe.php is applied.
 * The numbers are the same as on the PHP server. tests: compare a point on both.
 */
'use strict';

const StaticProbe = (() => {
  const POWER = 'https://power.larc.nasa.gov/api/temporal/climatology/point';
  const POWER_PARAMS = 'T2M,T2M_MAX,T2M_MIN,TS,PS,ALLSKY_SFC_SW_DWN,RH2M,PRECTOTCORR';
  const FEATURES = ['temp_air', 'temp_range', 'solar', 'humidity', 'precip'];
  const MONTH_KEYS = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
  const DISCLAIMER = 'Environmental Similarity Index for specific measurable parameters. It is not a validated habitat suitability score.';
  const cache = new Map();

  // ---------------------------------------------------------------- maths (mirror of src/Esi.php)
  function similarity(method, e, r) {
    if (e === null || r === null || e === undefined || r === undefined) return null;
    switch (method) {
      case 'log_ratio':
        if (e <= 0 || r <= 0) return null;
        return Math.max(0, 1 - Math.abs(Math.log(e / r)) / Math.log(1000));
      case 'abs_kelvin':
        return Math.max(0, 1 - Math.abs(e - r) / 100); // a Celsius difference equals a kelvin difference
      case 'relative': {
        const m = Math.max(Math.abs(e), Math.abs(r));
        return m === 0 ? 1 : Math.max(0, 1 - Math.abs(e - r) / m);
      }
      case 'binary':
        return Math.round(e) === Math.round(r) ? 1 : 0;
      default:
        return null;
    }
  }

  function combine(items) {
    let num = 0, den = 0, available = 0;
    for (const it of items) {
      if (it.similarity === null) continue;
      available++; num += it.weight * it.similarity; den += it.weight;
    }
    return { esi: den > 0 ? Math.round((num / den) * 10000) / 10000 : null, available, total: items.length };
  }

  const reliable = (c) => c.available >= 2 && c.available * 2 >= c.total;

  /** ESI with the visitor's weights, from a saved esi.php answer (the similarities do not change). */
  function reweigh(base, weights) {
    const d = JSON.parse(JSON.stringify(base));
    for (const p of d.parameters) {
      if (Object.prototype.hasOwnProperty.call(weights, p.code)) p.weight = weights[p.code];
    }
    const c = combine(d.parameters);
    d.esi = c.esi; d.completeness = { available: c.available, total: c.total }; d.reliable = reliable(c);
    return d;
  }

  // ---------------------------------------------------------------- NASA POWER (mirror of PowerImporter::readPoint)
  const plausible = (param, v) => ({
    temp_air: v > -100 && v < 70, temp_surface: v > -100 && v < 70, pressure: v > 20000 && v < 110000,
    solar: v >= 0 && v < 15, humidity: v >= 0 && v <= 100, precip: v >= 0 && v < 100,
  }[param] !== false);

  function cellOf(lat, lon) {
    const cLat = Math.max(-90, Math.min(90, Math.round(lat / 0.5) * 0.5));
    let cLon = Math.round((-180 + Math.round((lon + 180) / 0.625) * 0.625) * 1000) / 1000;
    if (cLon > 180) cLon -= 360;
    return [cLat, cLon];
  }

  async function fetchPoint(cLat, cLon) {
    const key = cLat + ',' + cLon;
    if (cache.has(key)) return cache.get(key);
    const url = `${POWER}?parameters=${POWER_PARAMS}&community=RE&longitude=${cLon}&latitude=${cLat}&format=JSON`;
    const ctl = new AbortController();
    const timer = setTimeout(() => ctl.abort(), 20000);
    let json;
    try {
      const res = await fetch(url, { signal: ctl.signal });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      json = await res.json();
    } catch (e) {
      throw new Error('NASA POWER did not answer. Try another place or try again.');
    } finally { clearTimeout(timer); }
    if (!json || !json.properties || !json.properties.parameter) throw new Error('Unexpected answer from NASA POWER.');
    const data = json.properties.parameter;
    const fill = json.header && isFinite(json.header.fill_value) ? Number(json.header.fill_value) : -999;
    const units = {};
    for (const [n, info] of Object.entries(json.parameters || {})) if (info && typeof info.units === 'string') units[n] = info.units;
    const period = (json.header && (json.header.range || json.header.title)) || 'NASA POWER climatology';
    let elev = json.geometry && json.geometry.coordinates ? json.geometry.coordinates[2] : null;
    elev = Number.isFinite(Number(elev)) && Number(elev) !== fill ? Number(elev) : null;
    const note = elev === null ? 'NASA POWER grid-cell average.' : `NASA POWER grid-cell average (cell mean elevation ${Math.round(elev)} m).`;
    const get = (name, k) => {
      const v = data[name] && data[name][k];
      if (v === null || v === undefined || !isFinite(Number(v))) return null;
      const x = Number(v);
      return Math.abs(x - fill) < 1e-6 ? null : x;
    };
    const has = (n, needle, dflt) => (units[n] ? units[n].toLowerCase().includes(needle) : dflt);
    const map = { T2M: ['temp_air', 1], TS: ['temp_surface', 1], RH2M: ['humidity', 1] };
    if (has('PS', 'kpa', true)) map.PS = ['pressure', 1000];
    const su = (units.ALLSKY_SFC_SW_DWN || 'kw-hr/m^2/day').toLowerCase();
    if (su.includes('kw')) map.ALLSKY_SFC_SW_DWN = ['solar', 1]; else if (su.includes('mj')) map.ALLSKY_SFC_SW_DWN = ['solar', 1 / 3.6];
    if (has('PRECTOTCORR', 'mm', true)) map.PRECTOTCORR = ['precip', 1];
    const values = {};
    for (const [pn, [param, scale]] of Object.entries(map)) {
      const v = get(pn, 'ANN');
      if (v !== null && plausible(param, v * scale)) values[param] = { value: v * scale, data_type: 'modelled', period, note };
    }
    // Spread: the mean of the 12 monthly T2M_MAX - T2M_MIN values (the ANN column of POWER is a yearly extreme).
    const spreads = [];
    for (const k of MONTH_KEYS) {
      const mx = get('T2M_MAX', k), mn = get('T2M_MIN', k);
      if (mx !== null && mn !== null && mx >= mn) spreads.push(mx - mn);
    }
    if (spreads.length === 12) {
      values.temp_range = { value: spreads.reduce((a, b) => a + b, 0) / 12, data_type: 'calculated', period,
        note: 'Mean of the 12 monthly T2M_MAX minus T2M_MIN spreads (NASA POWER); larger than a true mean daily range. ' + note };
    }
    if (!Object.keys(values).length) throw new Error('NASA POWER has no usable data for this point right now.');
    const out = { elevation: elev, values };
    cache.set(key, out);
    return out;
  }

  // ---------------------------------------------------------------- fingerprint (mirror of src/Fingerprint.php)
  function nearest(target, vectors, analogs) {
    const complete = Object.entries(vectors).filter(([, v]) => FEATURES.every((f) => v[f] !== null && v[f] !== undefined));
    const byKey = Object.fromEntries(complete);
    if (!byKey[target] || complete.length < 3) return null;
    const z = {};
    for (const f of FEATURES) {
      const col = complete.map(([, v]) => Number(v[f]));
      const mean = col.reduce((a, b) => a + b, 0) / col.length;
      const sd = Math.sqrt(col.reduce((a, x) => a + (x - mean) ** 2, 0) / col.length);
      complete.forEach(([slug, v]) => { (z[slug] = z[slug] || {})[f] = sd > 0 ? (Number(v[f]) - mean) / sd : 0; });
    }
    const out = [];
    for (const slug of analogs) {
      if (slug === target || !z[slug]) continue;
      const s = FEATURES.reduce((a, f) => a + (z[target][f] - z[slug][f]) ** 2, 0);
      out.push({ slug, distance: Math.round(Math.sqrt(s) * 1000) / 1000 });
    }
    out.sort((a, b) => a.distance - b.distance);
    return { neighbours: out, places_used: complete.length };
  }

  // ---------------------------------------------------------------- public: the same answer as api/probe.php
  async function run(lat, lon, refSlug, helpers) {
    const { api, state } = helpers;
    const [cLat, cLon] = cellOf(lat, lon);
    const p = await fetchPoint(cLat, cLon);
    const src = (await api('sources.php')).sources.find((s) => s.code === 'nasa_power') || {};
    const params = (await api('sources.php')).parameters;
    const ref = await api('location.php', { slug: refSlug });
    const refVals = {};
    for (const q of ref.parameters) if (q.value) refVals[q.code] = q.value;

    const e = {};
    for (const [code, v] of Object.entries(p.values)) {
      e[code] = { value: v.value, data_type: v.data_type, source_code: 'nasa_power', source_name: src.name || 'NASA POWER',
        source_url: src.url || null, period: v.period, note: v.note, annual_fallback: false };
    }
    const items = params.filter((q) => q.esi_method !== 'none').map((q) => {
      const ev = e[q.code] || null, rv = refVals[q.code] || null;
      const s = similarity(q.esi_method, ev ? ev.value : null, rv ? rv.value : null);
      return { code: q.code, name: q.name, unit: q.unit, method: q.esi_method, weight: q.default_weight,
        similarity: s === null ? null : Math.round(s * 10000) / 10000, status: s === null ? 'excluded: missing value' : 'included',
        earth: ev, reference: rv };
    });
    const c = combine(items);
    const shown = params.map((q) => ({ code: q.code, name: q.name, unit: q.unit, in_esi: q.esi_method !== 'none', value: e[q.code] || null }));

    // climate fingerprint against the known analogs
    const places = [...state.sites, ...state.analogs];
    const vectors = { probe: {} };
    FEATURES.forEach((f) => { vectors.probe[f] = e[f] ? e[f].value : null; });
    const names = {};
    const analogs = [];
    await Promise.all(places.map(async (pl) => {
      const d = await api('location.php', { slug: pl.slug });
      vectors[pl.slug] = {};
      FEATURES.forEach((f) => { const q = d.parameters.find((x) => x.code === f); vectors[pl.slug][f] = q && q.value ? q.value.value : null; });
    }));
    places.forEach((pl) => { names[pl.slug] = pl.name; if (pl.kind === 'known_analog') analogs.push(pl.slug); });
    const fp = nearest('probe', vectors, analogs);

    const notes = [
      'Climate is the NASA POWER grid-cell average (about 55 km wide), so a mountain point is averaged with its valleys.',
      'Impact geology is unknown for a clicked point, so it is left out of the index (completeness shows this).',
    ];
    if (p.elevation !== null && p.elevation <= 0) notes.push('The cell mean elevation is 0 m or below: this is probably sea or a very low area, and the result is not meaningful for a Mars comparison.');

    return {
      point: { clicked_lat: lat, clicked_lon: lon, cell_lat: cLat, cell_lon: cLon, cell_elevation_m: p.elevation, cached: false },
      reference: refSlug,
      esi: { earth: 'probe', reference: refSlug, month: 0, esi: c.esi, completeness: { available: c.available, total: c.total },
             reliable: reliable(c), parameters: items, disclaimer: DISCLAIMER },
      parameters: shown,
      fingerprint: { available: !!fp, neighbours: fp ? fp.neighbours.map((n) => Object.assign({ name: names[n.slug] }, n)) : [], places_used: fp ? fp.places_used : 0 },
      notes,
      disclaimer: DISCLAIMER,
    };
  }

  return { run, reweigh, similarity, combine, nearest, cellOf };
})();
