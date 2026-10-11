<p align="center">
  <img src="docs/icon.png" width="128" height="128" alt="Amor icon">
</p>

<h1 align="center">Amor</h1>

<p align="center">
  Photograph the duty roster, and every shift goes into your calendar.<br>
  A lovebird that reads POLYPOINT/PEP rosters for you. 🦜
</p>

<p align="center">
  <a href="https://amor.kimiyu.ch"><b>Open Amor</b></a>
</p>

---

Amor is a phone app (PWA) that reads a photographed duty roster ("Dienstplan") with Claude and turns one person's shifts into calendar events: as an `.ics` file for any calendar app, or written directly to Google Calendar.

Static files only: no backend, no build step. The spec is in [docs/spec.md](docs/spec.md).

## Local development

The site runs in [Herd](https://herd.laravel.com) at `https://amor.web.test` (Herd serves `public/`). HTTPS is needed for the service worker and Google sign-in; the certificate comes from `herd secure amor.web`.

- `https://amor.web.test/?mock` runs the whole app against simulated Anthropic and Google APIs, so you need no keys. In mock mode, run `__mock.loadRoster()` in the console to load a synthetic roster photo, then search for "Anna Muster".
- Without Herd: `npm run serve` serves `public/` at `http://localhost:8000`.

```sh
npm install          # Playwright, only needed for the end-to-end test
npm test             # unit tests for the event mapping and .ics export
npm run e2e          # full flow in a phone-sized Chromium against ?mock (needs npm run serve)
npm run e2e -- webkit
BASE_URL=https://amor.web.test/ npm run e2e   # same, against Herd
```

The end-to-end test reads a synthetic roster through a mocked Anthropic SSE stream with one zoom round trip, checks the request (model, effort, structured output, fallbacks, strict tool, append-only history), edits codes and shift times, exports the `.ics` (download in Chromium, share sheet in WebKit), and syncs to a fake Google Calendar, including removing a stale event and re-syncing without duplicates. Screenshots go to `tests/screenshots/`.

## Deploying

```sh
./deploy.sh
```

`deploy.sh` runs the unit tests, exports the committed `public/` folder (without `mock.js`) and syncs it over SSH to Hostpoint with your own key. GitHub Actions only runs the tests. `public/.htaccess` forces HTTPS, sets the manifest MIME type and makes browsers revalidate the app files, so updates arrive straight away.

## Where things are

| | |
|---|---|
| `public/index.html`, `styles.css` | The UI (mobile-first, light and dark) |
| `public/app.js` | UI logic and settings (stored in `localStorage`) |
| `public/extract.js` | Claude: overview image, zoom tool loop, structured result |
| `public/calendar.js` | Code → event mapping, Google Calendar sync, `.ics` export and delivery |
| `public/sw.js` | Network-first cache for the app and the SDK; API calls are never cached |
| `public/mock.js` | Test double used by `?mock` and the end-to-end test; not deployed |
| `scripts/make-icon.mjs` | Draws the icon (a peach-faced lovebird with a heart-shaped face on a calendar page) and renders every PNG |

### Look

The colors come from the icon and are defined at the top of `public/styles.css`: `sky` for the background, `leaf` for the lovebird and `peach` for its face and the calendar band. Buttons use a deeper peach (`#cc4232`) so white text stays readable. Headings use Figtree (SIL Open Font License, self-hosted in `public/fonts`); body text uses the system font. After changing the icon, run `node scripts/make-icon.mjs`.

### How the extraction works

`extract.js` sends a ≈2000 px overview of the photo plus a client-side `zoom` tool to `claude-opus-5-5` (effort `high`, adaptive thinking, streaming). The tool crops the original full-resolution photo in a canvas (max 2000 px long side, at most 2× upscale) and returns the crop as an image. Claude zooms into the header and the person's row about 7–10 days at a time, and answers with a JSON schema (`output_config.format`): period, row label, row bounding box, and one code per day with an "uncertain" flag. The loop is capped at 14 turns. Refusals fall back to another model server-side (`fallbacks: "default"`, beta `server-side-fallback-2026-07-01`). The SDK is loaded from esm.sh, pinned to `@anthropic-ai/sdk@0.133.0` (bundled). When you update it, change the version in `extract.js`, `index.html` and `sw.js`.

A scan costs a few cents (several image requests; the prompt prefix is cached between turns).

Event IDs are `dienst` + FNV-1a hash of the person's name (base32) + `yyyymmdd`. They stay the same per person and date, so a re-import updates events instead of duplicating them.

## Setup for users

### Anthropic API key (required)

1. Sign up or log in at [platform.claude.com](https://platform.claude.com).
2. **Settings → Billing**: add a payment method or credits.
3. **Settings → API Keys → Create Key** (e.g. "dienstplan-app") and copy the `sk-ant-…` key. It is only shown once.
4. In the app: ⚙︎ Settings → *Anthropic API key*.
5. Optional: set a monthly spend limit under **Settings → Limits**.

The key is stored in the browser and only sent to Anthropic. That's fine for personal use. If the app is shared more widely, move the Claude call behind a small server function that holds the key.

### Shift times

Times aren't on the roster. Enter them once in Settings → *Shift codes*. Codes without times become all-day events; if the end is before the start, the shift ends the next day; *Skip* creates no event. Codes found in a scan that aren't in the table are added automatically.

### Google Calendar (optional)

Only needed for "Add to Google Calendar" (updates and removals, useful on Android). The client ID isn't a secret: it's created once by the app owner, and every user signs in with their own Google account.

1. [console.cloud.google.com](https://console.cloud.google.com) → **New project** (e.g. "Dienstplan").
2. **APIs & Services → Library** → "Google Calendar API" → **Enable**.
3. **OAuth consent screen**: External, app name, support email; scope `https://www.googleapis.com/auth/calendar.events`; add every user's Google account as a **test user**. While the app is in "Testing", only those accounts (up to 100) can sign in, and no verification is needed.
4. **Credentials → Create credentials → OAuth client ID → Web application**. Authorized JavaScript origins: `https://amor.kimiyu.ch` and `https://amor.web.test` (no path, no trailing slash). No redirect URI.
5. Copy the client ID (`….apps.googleusercontent.com`) → app Settings → *Google OAuth client ID*.

For a separate calendar, create it in Google Calendar (web), then copy ⋮ → *Settings and sharing* → **Calendar ID** into app Settings → *Google calendar ID*. Default: `primary`.

## Importing the .ics

| Where | What happens |
|---|---|
| iPhone (Safari and home-screen app) | The app opens the share sheet with the file → Calendar → **Add All** |
| Android + Samsung Calendar / other apps | Open the downloaded file → import |
| Android + Google Calendar app | Can't import .ics. Use calendar.google.com → Settings → Import & export, or the Google button |
| Mac / Outlook | Double-click → import |

The .ics path can't delete shifts that were removed from the plan; the Google sync can (checkbox "Remove previously imported events on days without a shift").

**Still to test on real devices:** .ics import on iPhone (Safari and home-screen mode) and Android, and opening offline on iOS. iOS calendar import can't be simulated in a desktop browser.
