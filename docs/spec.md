# Dienstplan → Calendar App

Build spec for a phone app that reads a photographed duty roster and adds one person's shifts to Google Calendar.

---

## 1. Prompt (paste into a new session)

````text
Build a Progressive Web App (PWA) that I can install on my phone. It turns a photo of a printed hospital duty roster (POLYPOINT/PEP "Dienstplan", e.g. "Pflege Anästhesie Waid – 01.12.2026 – 03.01.2027") into Google Calendar events for one person.

The roster is a grid: one row per employee (name, sometimes a staff number in brackets), one column per day. The header shows month, weekday and day number per column, and the title shows the full period. Cells contain short shift codes such as 1, 1T, 1E, 1P, M1, M3, 6A, 8B, 9A, 9B, 2S, 3F, B, HO, EI, DI. Some cells have two stacked codes (e.g. "S1" above "BD") – treat those as one code "S1/BD". Empty or grey cells mean no shift. Photos are taken by hand: at an angle, folded, with glare, and rows/columns are slanted.

Architecture
- Static files only, no backend, no build step: index.html, styles.css, app.js (UI/settings), extract.js (Claude), calendar.js (event mapping, Google sync, .ics), sw.js, manifest.webmanifest, icons (SVG + 192/512 PNG).
- Vanilla JS ES modules. Load the official Anthropic TypeScript SDK from esm.sh (pinned version) with dangerouslyAllowBrowser: true. Use the claude-api skill to get the API details right.
- Settings stored in localStorage.

Extraction (extract.js)
- Model: claude-opus-5-5 (configurable in Settings), effort "high", streaming + finalMessage().
- Send a ~2000 px overview of the photo plus a client-side "zoom" tool (strict schema, normalized 0..1 box x0,y0,x1,y1). The tool crops the ORIGINAL full-resolution photo in a canvas (max 2000 px long side, at most 2x upscale) and returns the crop as an image tool_result. Tell the model to zoom into the header and the person's row about 7–10 days at a time before answering. Run a manual tool loop (append-only messages, max ~14 turns).
- Final answer via structured outputs (output_config.format json_schema): person_found, row_label, period_start, period_end (YYYY-MM-DD), row_box (normalized bbox of the person's row), days[] {date, code, uncertain}, notes.
- Match the person's name flexibly (staff numbers, name order, small spelling differences).
- Handle stop_reason refusal / max_tokens. Turn on the server-side fallback for refusals (fallbacks: "default" with beta server-side-fallback-2026-07-01).

UI (mobile-first, light/dark)
- Input for the person's name (remembered), a "take/choose photo" button (file input accept=image/*), photo preview, a "Read shifts" button, progress status ("Looking at the roster…", "Zooming…").
- Result screen: title with row label and period, notes, a cropped image strip of the detected row (horizontally scrollable) to compare against, and a table Date | Code (editable input) | resulting Event preview. Uncertain cells highlighted. Editing a code updates the preview live.
- Primary button: "Add to calendar (.ics)", which works without any Google setup. Secondary/optional: "Add to Google Calendar", shown only when a Google client ID is set in Settings, with the checkbox "Remove previously imported events on days without a shift" (default on).
- Settings dialog: Anthropic API key, model, Google OAuth client ID, calendar ID (default "primary"), time zone (default Europe/Zurich), and an editable shift-code table: code, title, start time, end time, skip. Prefill the codes listed above with empty times. Codes found in a scan that aren't in the table get added automatically.

Calendar (calendar.js)
- Code → event: look up the code (case and whitespace insensitive). With start+end times → timed event in the configured time zone; if end <= start the shift ends the next day. Without times → all-day event. skip → no event. Default title "Dienst <code>".
- Event IDs must stay the same per person and date so re-importing updates instead of duplicating: "dienst" + FNV hash of the person's name in base32 + yyyymmdd (only characters 0-9a-v).
- Google: Google Identity Services token client (https://accounts.google.com/gsi/client), scope https://www.googleapis.com/auth/calendar.events, cache the token until it expires. For each day: PUT events/{id} (status confirmed), on 404 POST with that id. For empty days, if the checkbox is on, DELETE the id and ignore 404/410. Store the code in extendedProperties.private. Show progress and a summary ("14 events saved, 2 removed").
- .ics export: VCALENDAR with VEVENTs, UID = event id, TZID-based times plus a VTIMEZONE block for Central European zones, VALUE=DATE for all-day events.
- Delivering the .ics: if navigator.canShare({ files: [file] }) is true (iOS, also in home-screen mode), call navigator.share with a File named dienstplan-<period>.ics of type text/calendar so the user can open it in Calendar ("Add All"). Otherwise fall back to a normal download via a blob link. On Android, show a hint: the Google Calendar app can't import .ics, so use Samsung Calendar or another calendar app, calendar.google.com → Settings → Import & export, or the Google sync button.

PWA
- manifest (standalone, theme color), apple-touch-icon, service worker that caches the app files network-first (same origin + esm.sh) and never caches API calls.

Deliver a README with setup steps (hosting, Anthropic key, Google Cloud OAuth client) and test it in a mobile-sized browser preview: mock the Anthropic SSE stream (including one zoom tool round trip) and the Google Calendar API to verify the whole flow without real keys.
````

---

## 2. Requirements

### Functional
- Take a photo with the phone camera, or choose one from the gallery.
- Find a given person's row in the roster (flexible name matching).
- Read the code for every date in the plan period, including stacked codes (`S1/BD`) and empty days.
- Detect the plan period (start/end date) from the roster header/title.
- Show the result for review: detected row as an image strip, date list with editable codes, uncertain cells highlighted.
- Map codes to events through a configurable table (title, start, end, skip). Codes without times become all-day events, and shifts that end before they start run into the next day.
- **Main path:** one `.ics` file with all shifts that the user opens to import everything at once (iPhone: share sheet → Calendar → "Add All"). Needs no Google setup.
- **Optional path:** write events directly to Google Calendar (choose which calendar). Re-importing updates existing events instead of creating duplicates, and days that are now empty can be cleared.
- Remember the person's name, keys and code table on the device.

### Non-functional
- Installable PWA (home screen icon, standalone, opens offline).
- Mobile-first UI, light and dark mode.
- No backend: static files, hostable on any static HTTPS host.
- Must cope with slanted, folded or glare-affected photos (zoom tool on the full-resolution photo).
- Keys never leave the device, except in calls to Anthropic and Google.
- Cost: a few cents per scan (several image requests).

### Technical
- HTTPS hosting (required for PWA install and Google sign-in), e.g. Netlify, GitHub Pages, Cloudflare Pages, Vercel.
- A modern mobile browser (iOS Safari 16+, Android Chrome).
- Network access to `api.anthropic.com`, `esm.sh`, `accounts.google.com`, `www.googleapis.com`.
- Local testing: `python3 -m http.server 8000` → `http://localhost:8000`.

### Known limitations / later improvements
- The Anthropic key is stored in the browser. That's fine for personal use, but if you share the app, move the Claude call behind a small server function (e.g. a Netlify/Cloudflare function) that holds the key.
- Shift times aren't known in advance. They must be entered once in Settings.
- The .ics path can't delete shifts that were removed from the plan, and every new roster means a new file (see section 4).
- Later option: a subscription link (`webcal://`) that calendars refresh automatically. It needs a small server, so it isn't part of the no-backend design.

---

## 3. Keys and how to get them

### Anthropic API key (required)
Used to read the photo with Claude.

1. G ign up or log in.
2. **Settings → Billing**: add a payment method / credits.
3. **Settings → API Keys → Create Key**. Give it a name (e.g. "dienstplan-app") and copy the key (`sk-ant-…`). It is only shown once.
4. Paste it into the app: ⚙︎ Settings → *Anthropic API key*.
5. Optional: set a monthly spend limit under **Settings → Limits**.

### Google OAuth client ID (needed only for "Add to Google Calendar")
This isn't a secret key. It's a public client ID; the user signs in with Google in the app.

**Who can create one?** Any Google account, including a free @gmail.com account. Google Cloud Console is free, and you don't need billing for the Calendar API or an OAuth client. The one exception is a work or school account (Google Workspace), where an admin may have blocked Cloud projects. A private Gmail account is the simplest choice.

**Who can use it?** Only one client ID is needed, and the app owner creates it once. Every user signs in with their own Google account and writes to their own calendar; nobody else needs a Cloud project. How many people can sign in depends on the status of the OAuth consent screen:

| Status | Who can sign in | Effort |
|---|---|---|
| **Testing** (default) | Only accounts you add as test users, up to 100 | None. Fine for you and a few colleagues |
| **In production, unverified** | Any Google account, but Google shows an "unverified app" warning; capped at 100 users | Click "Publish app" |
| **In production, verified** | Any Google account, no warning | Google review: `calendar.events` counts as a sensitive scope, so you need a privacy policy page, a domain you own, and a demo video. Typically takes a few days to weeks |

Recommendation: **Testing** is enough for personal or small-team use. Only go through verification to share widely, and move the Anthropic key to a server first.

Setup steps:

1. Go to https://console.cloud.google.com → project picker → **New project** (e.g. "Dienstplan").
2. **APIs & Services → Library** → search "Google Calendar API" → **Enable**.
3. **APIs & Services → OAuth consent screen** (Google Auth Platform):
   - User type **External**, app name, support email.
   - Scopes: add `https://www.googleapis.com/auth/calendar.events`.
   - **Test users / Audience**: add every Google account that will use the app. While the app is in "Testing", only these accounts can sign in. That's fine for personal use; no verification is needed.
4. **APIs & Services → Credentials → Create credentials → OAuth client ID**:
   - Application type: **Web application**.
   - **Authorized JavaScript origins**: your hosting URL (e.g. `https://dienstplan.netlify.app`) and `http://localhost:8000` for local testing. No path and no trailing slash.
   - No redirect URI is needed.
5. Copy the **Client ID** (`…apps.googleusercontent.com`) → app Settings → *Google OAuth client ID*.

### Google Calendar ID (optional)
- Default `primary` = your main calendar.
- For a separate "Dienste" calendar: Google Calendar (web) → create the calendar → ⋮ → *Settings and sharing* → **Integrate calendar → Calendar ID** (`…@group.calendar.google.com`) → app Settings → *Calendar ID*.

### No keys needed for
- The `.ics` export (works without Google).
- Hosting on Netlify/GitHub Pages (a free account is enough).

---

## 4. Importing via .ics: how it behaves on each platform

The app creates **one .ics file with all shifts** of the roster. The user opens it and the calendar app offers to import everything at once.

| Where | What happens |
|---|---|
| **iPhone (Safari)** | Opens a preview listing all events with **"Add All"**. Two taps and they're in Apple Calendar, or a synced Google calendar if you choose it there. |
| **iPhone, app installed on the home screen** | Plain downloads are unreliable in home-screen mode on iOS, so the app uses the **share sheet** (`navigator.share` with the file) there. ⚠️ Not yet tested on a real device. |
| **Android + Samsung Calendar / other calendar apps** | Tapping the downloaded file opens the import, usually all at once. |
| **Android + Google Calendar app** | ❌ The Google Calendar app can't import .ics files. Workaround: calendar.google.com → Settings → **Import & export** → choose file. Or use the "Add to Google Calendar" button. |
| **Mac / Outlook** | Double-click → imports all. |

### Limits compared to the Google sync

| | .ics | Google sync |
|---|---|---|
| Setup | None | Google Cloud client ID (section 3) |
| Works with | Apple, Samsung, Outlook, Google (via web import) | Google Calendar only |
| Re-importing a corrected roster | Each event has a fixed ID (UID) per person and date. Apple Calendar normally updates matching events; Google's import might create duplicates (not verified) | Updates existing events, no duplicates |
| Shift removed from the plan | ❌ Can't delete events | ✅ Deletes the event (if the checkbox is on) |
| Live updates | ❌ New file per roster | Re-scan → updated |

### Decision
- **.ics is the main button.** It needs no setup, and on iPhone the app uses the share sheet.
- **"Add to Google Calendar" is optional**, for Android users and anyone who wants the update and remove behavior. It's only shown once a Google client ID is set.
- **To do:** test the .ics import on a real iPhone (Safari and home-screen mode) and on Android. iOS calendar import can't be simulated in a desktop browser.
