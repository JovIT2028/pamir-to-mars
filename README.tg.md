# PAMIR TO MARS — NASA Space Apps 2026 (тоҷикӣ)

> English version: [README.md](README.md)

Платформа, ки макони Помирро бо Mars ва Moon бо **Environmental Similarity Index (ESI)** муқоиса мекунад.
Танҳо маълумоти кушодаи NASA; ҳар қимат манбаъ, давра ва навъ дорад (measured / satellite / modelled / calculated / interpretation).

> ESI — нишондиҳандаи шаффофи ШИБОҲАТ аст, на «баҳои мувофиқатии зист». Помир айнан Mars ё Moon нест.

## 1. Талабот
- OpenServer (Windows 11): модулҳои **PHP 7.4** (кор мекунад то PHP 8.x) ва **MySQL 8.0** / MariaDB 10.3+
- Интернет (CDN-и Tailwind/Leaflet/Chart.js ва NASA POWER)

## 2. Насб
1. Папкаро ба `...\OpenServer\domains\pamir-to-mars\` гузоред; домен `pamir-to-mars` (`.htaccess` худаш ба `/public` мефиристад).
2. phpMyAdmin → **Import** → `database/install.sql` (базаи `pamir_to_mars`-ро мебарад ва нав месозад, бо маълумот).
   Танҳо сохтор: `database/schema.sql`.
3. `.env.example` -ро нусха карда `.env` номгузорӣ кунед, `DB_USER` / `DB_PASS` -ро пур кунед. **`.env`-ро ҳеҷ гоҳ ба GitHub напартоед.**
4. Кушоед: `http://pamir-to-mars/`

## 3. Маълумоти воқеии NASA
Аз терминал (PHP-и OpenServer, масалан `D:\OpenServer\modules\php\PHP_7.4\php.exe`):
```
php scripts/import_power.php        # Помир + аналогҳои маълум (Atacama, Ladakh, Haughton, MDRS)
php scripts/import_grid.php         # 30 катак (lat 37–39.5, lon 72.5–75) барои харитаи гармӣ
php scripts/export_static.php       # нусхаи захиравии JSON барои демо (public/static/)
```
Импорт бо upsert менависад (такрор нусха намесозад). Қимати -999 партофта мешавад, ҳеҷ гоҳ 0 намешавад.

Маълумоти иловагӣ (MODIS, LRO Diviner, SRTM, Mars Climate Database) бо CSV:
```
php scripts/import_csv.php data/your_file.csv
```
Сарлавҳа: `location_slug,parameter_code,source_code,month,value,data_type,period,note` (month 0 = солона; ҳамаи сатрҳо ё ҳеҷ кадом).

## 3b. Имкониятҳои иловагӣ
- **Глобуси 3D** (саҳифаи «Analog Map»): Замин, Миррих ва Моҳ дар як глобуси чархзанандаи CesiumJS (бе калид, бо бор шудани танбал). Аломат ва ҳалқаи 3 нуқтаи Помир, 9 аналог, ҳалқаи кратери Қарокӯл ва Gale. Тасвир: NASA GIBS Blue Marble (дур) + ESA Sentinel-2 10 м (наздик; Landsat-и NASA ҳамчун интихоб, аммо сервери GIBS суст аст), NASA Viking ва LRO барои Миррих ва Моҳ. Агар WebGL набошад ё `?flat=1`, харитаи ҳамвори Leaflet кушода мешавад. Релефи 3D ихтиёрист: токени ройгони Cesium ion дар `window.P2M_CESIUM_TOKEN` гузоред.
- **Ҳар ҷойро санҷед** (дар ҳамин глобус, «Analyze any point»): клик ба ҳар нуқтаи глобус → `api/probe.php` иқлими катаки NASA POWER-ро мегирад (кэш дар `probe_cache`, маҳдудият 8 нуқтаи нав дар дақиқа барои ҳар IP), ESI бо Gale/Моҳ ва наздиктарин аналогҳоро ҳисоб мекунад. Ҳеҷ чиз ба `measurements` навишта намешавад.
- **Кратер ба паҳлуи кратер** (саҳифаи «Earth ↔ Mars / Moon»): ду глобуси 3D (Замин ва Миррих) бо камераи ҳамон баландӣ (160 км) ва кунҷ; агар WebGL набошад, харитаҳои ҳамвори ҳамон андоза (240 км). Диаметрҳо аз базаи маълумот (`crater_km`).
- **12 макони «изи ангушт»**: 3 нуқтаи Помир + 9 аналоги маъруф (Atacama, Ladakh, Haughton, MDRS, Dry Valleys, Dallol, Salar de Uyuni, Qaidam, Askja). Координатаҳои аналогҳо тахминианд, бояд тасдиқ шаванд.
- **Админ-панел** (`/admin/`): воридшавӣ, таҳрири маконҳо ва қиматҳо (бо манбаъ ва навъ), журнали аудит. Корбар созед (парол дар терминал ворид мешавад, ба ягон файл навишта намешавад):
  ```
  php scripts/create_admin.php admin
  ```
  Ҳимоя: `password_hash`, қулф баъд аз 5 хато, CSRF-токен, cookie HttpOnly+SameSite, `audit_logs`.

## 4. Санҷиш
```
php tests/EsiTest.php      # 31 тест (ESI, валидатсия, изи ангушт)
```

## 5. Резерви демо
Агар сервер ё база кор накунад, `app.js` худаш `public/static/*.json`-ро мехонад ва огоҳӣ нишон медиҳад.
Пас аз ҳар импорти нав `php scripts/export_static.php`-ро иҷро кунед.

## 6. Сохтор
| Роҳ | Вазифа |
|---|---|
| `database/install.sql`, `schema.sql` | 5 ҷадвал + маълумот |
| `src/Esi.php` | формулаи ESI: log_ratio, abs_kelvin, relative, binary |
| `src/EsiService.php` | ҳисоб, completeness, `reliable` |
| `src/Fingerprint.php` | z-score + масофаи Евклидӣ ба аналогҳо |
| `src/PowerImporter.php` | NASA POWER импорт бо санҷиши воҳидҳо |
| `src/compat.php` | polyfill барои PHP 7.4 |
| `public/api/*.php` | API (танҳо GET, PDO prepared statements) |
| `public/index.html`, `assets/`, `static/` | интерфейс ва нусхаи захиравӣ |
| `scripts/` | импортҳо ва экспорт (CLI) |

## 7. Ҳолати маълумот (ростқавлона)
- Параметрҳои `pending` «NOT LOADED YET» нишон дода мешаванд; чизе ихтироъ нашудааст.
- Координата/баландии Murghab, Ak-Baital ва аналогҳо **тасдиқ нашудаанд** — бо SRTM/OpenTopography санҷед.
- Фишори Замин — NASA POWER (modelled, баландии миёнаи катак) ва формулаи атмосфераи стандартӣ (calculated).
- Gale: фишор 730 Pa (REMS, measured). **Ҳарорати Gale як моҳи миёна аст (на солона) ва радиатсияи офтоб ҳанӯз нест** — аз ин рӯ ESI-и Gale 3/4 параметр дорад ва «беҳтарин мавсим» бо эҳтиёт хонда шавад.
- «Диапазони ҳарорати рӯзона» = T2M_MAX − T2M_MIN-и моҳона (NASA POWER); аз диапазони ҳақиқии рӯзона калонтар аст.
- ESI танҳо вақте нишон дода мешавад, ки ≥ 2 параметр муқоиса шавад (барои Моҳ: «n/a»).

## 8. Амният
PDO (EMULATE_PREPARES=false), валидатсияи slug/ваз/моҳ, матни API дар JS бо `esc()`, `.env` берун аз webroot ва аз браузер пӯшида (403), display_errors хомӯш, калидҳо дар frontend нестанд, API танҳо GET.

## 9. Литсензия ва манбаъҳо
MIT (`LICENSE`). Манбаъҳо: NASA POWER, Curiosity REMS (NASA PDS), Earth Impact Database (UNB), NASA GIBS, Martínez et al. 2017 (Space Sci. Rev.) — рӯйхати пурра дар саҳифаи «Data & Method».
