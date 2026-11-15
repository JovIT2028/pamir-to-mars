# PAMIR TO MARS

**Which places on Earth look like Mars, and where exactly does the resemblance stop?**
A web platform for the NASA Space Apps 2026 challenge *Identify Earth Locations that Analog the Permanent Moon Base Locations and Mars*.
It compares high-altitude sites of the Pamir Mountains (Tajikistan) with Mars and Moon reference sites, using only open NASA data and a transparent, adjustable index.

> **Scientific note.** The Pamir is *not* identical to Mars or the Moon. The platform compares specific measurable parameters and states every limitation. The result is called the *Environmental Similarity Index (ESI)*; it is **not** a validated habitat suitability score.

- **Live demo (static copy, works on phone and desktop): https://jovit2028.github.io/pamir-to-mars/**
- Full version (PHP + MySQL + admin): see *Install* below
- Slides and 2-minute script: [docs/DEMO_SCRIPT.md](docs/DEMO_SCRIPT.md)
- Team: `[add names]`
- Tajik version of this file: [README.tg.md](README.tg.md)

## What it does

| Feature | Where |
|---|---|
| **3D globe** of Earth, Mars and the Moon (rotate, zoom from orbit to ~10 m imagery), Pamir pins, impact-structure rings | *Analog Map* |
| **Analyze any point**: click anywhere on Earth; the server fetches that cell's NASA POWER climate and scores it against Gale Crater or a Moon site | *Analog Map* |
| **Similarity heatmap** over 30 NASA POWER grid cells of the Eastern Pamir | *Analog Map* |
| **Crater to crater**: Lake Karakul (52 km) and Gale Crater (154 km), same camera height and angle on two small globes | *Earth ↔ Mars / Moon* |
| Comparison table: value, difference, unit, source, period, data type, limitations; log-scale pressure chart | *Earth ↔ Mars / Moon* |
| **ESI** with weight sliders (0 to 5), data completeness, per-parameter similarity | *Similarity Index* |
| Best season (monthly ESI) and **analog fingerprint** (nearest known Earth analog by climate, 9 analogs) | *Similarity Index* |
| Source registry, formula, limitations | *Data & Method* |
| Admin panel (login, places, values, audit log) | `/admin/` |

## Scientific principles

- Every value shows its **source, unit, period and data type**: `measured`, `satellite`, `modelled`, `calculated`, `interpretation`.
- A missing value is stored as `NULL` and shown as *NOT LOADED YET*. It is **never counted as 0**; the parameter is left out of the index and *completeness* (for example 3/4) is shown.
- Earth elevation (above sea level) and Mars elevation (MOLA datum) are shown, **not subtracted**.
- For the Moon, pressure and air temperature are not compared (no atmosphere). With one comparable parameter the index shows `n/a`.
- Differences are shown openly: at about 4,000 m the pressure is still ~80 times higher than at Gale.

### ESI

`ESI = Σ wᵢ·sᵢ / Σ wᵢ` over the parameters that have **both** values (Earth and reference).

| Parameter | Similarity sᵢ | Default weight |
|---|---|---|
| Surface pressure (Pa) | `max(0, 1 − abs(ln(E/R)) / ln 1000)`; 1000× apart = 0 | 3 |
| Surface temperature (K) | `max(0, 1 − abs(E − R) / 100)`; 100 K apart = 0 | 3 |
| Solar radiation | `1 − abs(E − R) / max(E, R)` | 2 |
| Impact-crater geology | 1 if equal, otherwise 0 | 2 |

The limits (1000×, 100 K) and the default weights are choices of the team. They are adjustable and written on screen. Worked example (Karakul ↔ Gale): pressure s ≈ 0.36, temperature s ≈ 0.72, crater s = 1, so ESI ≈ 0.66 from 3 of 4 parameters.

## Data sources

| Source | Used for | Access |
|---|---|---|
| [NASA POWER](https://power.larc.nasa.gov/) (MERRA-2, CERES) | Earth climate: temperature, pressure, solar radiation, humidity, precipitation | public REST API, no key; imported once into MySQL |
| NASA PDS, Curiosity REMS | Gale surface pressure and ground temperature | published values |
| [Earth Impact Database](https://www.unb.ca/passc/ImpactDatabase/) (UNB) | Karakul impact structure | website |
| [NASA GIBS](https://earthdata.nasa.gov/gibs) | Blue Marble base imagery | WMTS tiles |
| [NASA Mars Trek](https://trek.nasa.gov/mars/) | Viking colour mosaic, MOLA | WMTS tiles |
| [NASA Moon Trek](https://trek.nasa.gov/moon/) | LRO WAC mosaic | WMTS tiles |
| Sentinel-2 cloudless by [EOX](https://s2maps.eu/) | Sharp close-up imagery (ESA, contains modified Copernicus Sentinel data 2021) | WMTS tiles; **non-commercial licence**: replace it for commercial use |

Browser requests go to our own API only. NASA data is imported into MySQL beforehand, so the demo does not depend on NASA being online (the *Analyze any point* feature is the one exception, and it caches every cell).

## Static demo on GitHub Pages

GitHub Pages has no PHP or MySQL, so the demo there is a static build: the numbers are the saved JSON exported from the database, ESI weights are re-applied in the browser, and *Analyze any point* asks NASA POWER directly from the browser (same maths as the PHP server; checked on several points). The admin panel is not part of it.

```
php scripts/export_static.php     # refresh the saved copy
php scripts/build_pages.php       # builds dist/ (not committed)
```

The `gh-pages` branch holds the contents of `dist/`; enable it in *Settings → Pages → Deploy from a branch → gh-pages / (root)*.

## Install (Windows, OpenServer)

Requirements: PHP 7.4+ (works up to 8.x) with `pdo_mysql` and `curl`, MySQL 8 or MariaDB 10.3+, internet.

1. Put the folder in `OpenServer\domains\pamir-to-mars\` (the root `.htaccess` sends every request to `public/`; on other hosts set the document root to `public/`).
2. Import `database/install.sql` (phpMyAdmin → Import). It creates the database `pamir_to_mars` with the schema and the data. *Schema only:* `database/schema.sql`.
3. Copy `.env.example` to `.env` and fill `DB_USER` and `DB_PASS`. **Never commit `.env`.**
4. Open `http://pamir-to-mars/`.

Refresh or extend the data (CLI):

```
php scripts/import_power.php     # Pamir sites + known analogs from NASA POWER (upsert, safe to repeat)
php scripts/import_grid.php      # 30 grid cells for the heatmap
php scripts/import_csv.php data/file.csv   # other sources (MODIS, Diviner, SRTM, MCD); see data/example_measurements.csv
php scripts/export_static.php    # backup JSON copy in public/static/ (used automatically if the server is down)
php scripts/create_admin.php admin         # create an admin user (password is typed in the terminal)
```

Useful switches: `?flat=1` shows the flat Leaflet map instead of the 3D globe (weak device or no WebGL).
Optional 3D terrain: set `window.P2M_CESIUM_TOKEN` to a free Cesium ion token.

## Tests

```
php tests/EsiTest.php
```

31 checks: ESI maths (including the six cases of the specification), input validation, SQL-injection-like input, coordinates, analog fingerprint.

## Security

- PDO with real prepared statements (`EMULATE_PREPARES = false`); strict validation of `slug`, weights (0 to 5), `month` (0 to 12) and coordinates.
- The API is `GET` only (`405` otherwise); errors are generic JSON, `display_errors` is off.
- Secrets live in `.env`, outside `public/` and ignored by git; no key is ever sent to the browser.
- All external text is escaped before it reaches the page.
- External API calls: timeout, JSON structure check, fill values (`-999`) become `NULL`, one request at a time; the point-analysis endpoint is rate-limited per IP and cached.
- Admin: `password_hash`, lock after 5 wrong passwords, CSRF token on every `POST`, `HttpOnly` + `SameSite` cookie, audit log.

## Project layout

```
public/            document root: index.html, assets/ (app.js, globe.js, app.css), api/, admin/, static/
src/               Esi.php (maths), EsiService, Fingerprint, PowerImporter, Repository, Validator, Auth, ...
scripts/           CLI imports, export, create_admin
database/          schema.sql, install.sql (schema + data)
tests/             EsiTest.php
docs/              demo script
```

## Known limitations (honest list)

- Gale: the ground temperature stored is a one-month average, not an annual mean, and the solar radiation is not loaded yet. The *best season* chart therefore shows an indication only (the page says so).
- Coordinates and elevations of Murghab, Ak-Baital and the nine known analogs are **not verified** (they are marked *to verify*); SRTM/OpenTopography verification is planned.
- NASA POWER cells are about 55 km wide; mountain sites are regional averages.
- The Moon is compared only by relief and craters (no atmosphere); Diviner data is not loaded yet.
- The daily temperature range is built from monthly T2M_MAX and T2M_MIN and is larger than a true daily range.
- Real 3D terrain needs a Cesium ion token; imagery is draped on a smooth globe without it.

## Use of AI tools

This project was developed with the help of an AI coding assistant (Claude Code by Anthropic) for code, tests, documentation and slides. The team reviewed and tested the result. No AI-generated data is presented as a measurement: every number comes from the sources above, and unknown values are shown as pending.

## License and credits

MIT, see [LICENSE](LICENSE). Libraries: CesiumJS, Leaflet, Chart.js, Tailwind CSS (CDN). Imagery and data credits are shown on the globe and on the *Data & Method* page.
