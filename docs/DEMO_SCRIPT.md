# PAMIR TO MARS: 2-minute demo script

Slides: https://claude.ai/artifact/4iN8vyD2pjaPnmpsvQVfUf (private until you share it)
Spoken language: English (for the judges). Tajik cues in brackets are for you only.

## Before you start (checklist)
- [ ] OpenServer running; `http://pamir-to-mars/` opens; the globe loads (internet and WebGL needed; open `#map` once beforehand so the tiles are warm)
- [ ] `php scripts/export_static.php` was run after the last import (backup copy)
- [ ] Slides open in full screen; the screen recording tool is ready
- [ ] Have `http://pamir-to-mars/#map` in a second tab

## Script (about 2:00)

| Time | Slide / screen | Say |
|---|---|---|
| 0:00 | 1 Cover | "We are PAMIR TO MARS. Our question: which places on Earth resemble Mars, and where exactly does the resemblance stop? We answer it for the Pamir Mountains of Tajikistan." |
| 0:08 | 2 Problem | "Before missions fly, people and hardware are tested on Earth analogs. Known ones are few: Atacama, Haughton, MDRS, Ladakh. High Asia is almost unstudied, and there is no open tool with sourced data and a formula you can read." |
| 0:20 | 3 Idea | "Our anchor is Lake Karakul, inside a 52-kilometre impact structure at 3,960 metres, a natural parallel to Gale Crater. We show the gap openly: even there, the air pressure is about 80 times higher than at Gale." |
| 0:35 | 4 Method | "The Environmental Similarity Index averages how similar each parameter is. A missing value is excluded, never counted as zero, and we always show completeness. Weights are sliders, so you can disagree with us." |
| 0:55 | 5 Data | "Every value shows its source, period and one of five data types. NASA POWER is imported once into our database, so the demo never waits for NASA." |
| 1:05 | **Switch to the website** (slide 6 as backup) | See "Live demo" below. |
| 1:35 | 7 Honesty | "We are careful: the Pamir is not Mars. This is a similarity index, not a habitat score. For the Moon, with no atmosphere, the index honestly shows n/a. What we do not have yet stays pending." |
| 1:47 | 8 Build | "PHP and MySQL, read-only API, keys on the server, a saved copy if our server is down, MIT licence." |
| 1:55 | 9 Close | "Measure it. Source it. Say where it stops. Thank you." |

## Live demo (1:05 to 1:35, about 30 seconds)
1. **3D globe (Analog Map):** it spins by itself. Press **Pamir**: the camera flies down to the three sites (Karakul, Murghab, Ak-Baital) over real satellite imagery.
2. Click **Lake Karakul**: the side panel shows values with badges. Press **Crater flight**: same height over Karakul (52 km), then over Gale on Mars (154 km).
3. Switch the Earth/Mars/Moon buttons once (Mars: Viking mosaic, Gale ring).
4. Back on Earth: turn on **Analyze any point**, click a place far away (for example the Atacama): ESI against Gale and the closest analog.
5. **Similarity Index** page: drag the pressure weight slider; ESI changes at once ("about 0.66, from 3 of 4 parameters"). Optionally turn on the **heatmap**.

## If something breaks
- No internet or server error: the site shows a notice and loads the saved copy from `public/static/`. Keep talking, the numbers are the same.
- Globe too slow or no WebGL: add `?flat=1` to the address for the flat map.
- Worst case: play the recorded video, or use slide 6 (the same results as static numbers).

## Likely questions (prepared answers)
- **"Is Pamir really like Mars?"** No. Pressure is about 80× higher. It is partly similar in cold, dry conditions and impact geology. The index says exactly which parameters.
- **"Why the Moon?"** Mainly a Mars analog. For the Moon we compare only relief and craters, because there is no atmosphere.
- **"Where do the numbers come from?"** Click any value: source, period and type are shown. The registry is on the *Data & Method* page.
- **"What is missing?"** Gale's annual temperature and solar radiation, and an SRTM check of two sites. They show as pending, not zero.

## Before submitting
- Replace `[live demo URL]` and `[GitHub repository URL]` on the last slide.
- Check the video-length rule of the challenge; check Participant Terms 2026 about previously written code.
