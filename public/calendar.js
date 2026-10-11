// Code → event mapping, Google Calendar sync and .ics export.

const GIS_SRC = "https://accounts.google.com/gsi/client";
const SCOPE = "https://www.googleapis.com/auth/calendar.events";
const API = "https://www.googleapis.com/calendar/v3";

// ---------- codes & dates ----------

export function normalizeCode(code) {
  return String(code ?? "").toUpperCase().replace(/\s+/g, "");
}

export function findCode(codes, code) {
  const key = normalizeCode(code);
  if (!key) return null;
  return codes.find((c) => normalizeCode(c.code) === key) ?? null;
}

export function addDays(date, n) {
  const [y, m, d] = date.split("-").map(Number);
  return new Date(Date.UTC(y, m - 1, d + n)).toISOString().slice(0, 10);
}

/** FNV-1a 32-bit, as base32 (digits 0-9a-v, valid for Google event IDs). */
function fnv32(text) {
  let h = 0x811c9dc5;
  for (const byte of new TextEncoder().encode(text)) {
    h ^= byte;
    h = Math.imul(h, 0x01000193) >>> 0;
  }
  return h.toString(32);
}

export function eventId(person, date) {
  const name = person.trim().toLowerCase().replace(/\s+/g, " ");
  return "dienst" + fnv32(name) + date.replaceAll("-", "");
}

/**
 * Returns the event for one day, or null when the day has no event
 * (empty code or a code marked skip).
 */
export function eventFor(day, settings, person) {
  const code = String(day.code ?? "").trim();
  if (!code) return null;
  const entry = findCode(settings.codes, code);
  if (entry?.skip) return null;
  const title = entry?.title?.trim() || `Dienst ${code}`;
  const base = { id: eventId(person, day.date), date: day.date, code, title };
  if (entry?.start && entry?.end) {
    const endDate = entry.end <= entry.start ? addDays(day.date, 1) : day.date;
    return {
      ...base,
      allDay: false,
      start: `${day.date}T${entry.start}:00`,
      end: `${endDate}T${entry.end}:00`,
    };
  }
  return { ...base, allDay: true, start: day.date, end: addDays(day.date, 1) };
}

export function describeEvent(event) {
  if (!event) return "—";
  if (event.allDay) return `${event.title} · all day`;
  const nextDay = event.end.slice(0, 10) !== event.date ? " (+1)" : "";
  return `${event.title} · ${event.start.slice(11, 16)}–${event.end.slice(11, 16)}${nextDay}`;
}

// ---------- Google Calendar ----------

let token = null; // { value, expiresAt }

function loadGis() {
  if (window.google?.accounts?.oauth2) return Promise.resolve();
  return new Promise((resolve, reject) => {
    const script = document.createElement("script");
    script.src = GIS_SRC;
    script.onload = () => resolve();
    script.onerror = () => reject(new Error("Could not load Google sign-in."));
    document.head.append(script);
  });
}

async function getToken(clientId) {
  if (token && token.expiresAt > Date.now() + 60_000) return token.value;
  await loadGis();
  return new Promise((resolve, reject) => {
    const client = window.google.accounts.oauth2.initTokenClient({
      client_id: clientId,
      scope: SCOPE,
      callback: (res) => {
        if (res.error) return reject(new Error(`Google sign-in failed: ${res.error}`));
        token = { value: res.access_token, expiresAt: Date.now() + res.expires_in * 1000 };
        resolve(token.value);
      },
      error_callback: (err) => reject(new Error(`Google sign-in failed: ${err.type ?? err.message}`)),
    });
    client.requestAccessToken();
  });
}

async function gapi(clientId, method, path, body) {
  for (let attempt = 0; attempt < 2; attempt++) {
    const res = await fetch(API + path, {
      method,
      headers: {
        Authorization: `Bearer ${await getToken(clientId)}`,
        ...(body ? { "Content-Type": "application/json" } : {}),
      },
      body: body ? JSON.stringify(body) : undefined,
    });
    if (res.status === 401 && attempt === 0) {
      token = null; // expired or revoked: sign in again once
      continue;
    }
    return res;
  }
}

async function failure(res, what) {
  let detail = "";
  try {
    detail = (await res.json()).error?.message ?? "";
  } catch {}
  return new Error(`${what} failed (${res.status})${detail ? `: ${detail}` : ""}`);
}

function googleBody(event, settings, person) {
  const when = (value) =>
    event.allDay ? { date: value } : { dateTime: value, timeZone: settings.timeZone };
  return {
    id: event.id,
    summary: event.title,
    status: "confirmed",
    start: when(event.start),
    end: when(event.end),
    extendedProperties: { private: { dienstCode: event.code, dienstPerson: person } },
  };
}

/**
 * Writes one event per day (PUT, then POST on 404) and optionally deletes
 * the fixed-ID event on days without a shift.
 */
export async function syncToGoogle({ days, settings, person, removeEmpty, onProgress }) {
  const { googleClientId: clientId } = settings;
  const cal = `/calendars/${encodeURIComponent(settings.calendarId || "primary")}/events`;
  let saved = 0;
  let removed = 0;
  for (const [i, day] of days.entries()) {
    onProgress?.(i, days.length);
    const event = eventFor(day, settings, person);
    if (event) {
      const body = googleBody(event, settings, person);
      let res = await gapi(clientId, "PUT", `${cal}/${event.id}`, body);
      if (res.status === 404) res = await gapi(clientId, "POST", cal, body);
      if (!res.ok) throw await failure(res, `Saving ${day.date}`);
      saved++;
    } else if (removeEmpty) {
      const res = await gapi(clientId, "DELETE", `${cal}/${eventId(person, day.date)}`);
      if (res.ok) removed++;
      else if (res.status !== 404 && res.status !== 410) throw await failure(res, `Removing ${day.date}`);
    }
  }
  onProgress?.(days.length, days.length);
  return { saved, removed };
}

// ---------- .ics ----------

// Zones that follow CET/CEST (EU summer time rules).
const CENTRAL_EUROPE = new Set([
  "Europe/Zurich", "Europe/Berlin", "Europe/Vienna", "Europe/Paris", "Europe/Rome",
  "Europe/Amsterdam", "Europe/Brussels", "Europe/Luxembourg", "Europe/Madrid",
  "Europe/Copenhagen", "Europe/Stockholm", "Europe/Oslo", "Europe/Prague",
  "Europe/Bratislava", "Europe/Budapest", "Europe/Warsaw", "Europe/Ljubljana",
  "Europe/Zagreb", "Europe/Belgrade", "Europe/Vaduz", "Europe/Monaco",
  "Europe/Malta", "Europe/Andorra", "Europe/San_Marino", "Europe/Vatican",
  "Europe/Busingen", "Europe/Sarajevo", "Europe/Skopje", "Europe/Podgorica",
  "Europe/Tirane", "Europe/Gibraltar",
]);

const vtimezone = (tzid) => [
  "BEGIN:VTIMEZONE",
  `TZID:${tzid}`,
  "BEGIN:DAYLIGHT",
  "TZOFFSETFROM:+0100",
  "TZOFFSETTO:+0200",
  "TZNAME:CEST",
  "DTSTART:19700329T020000",
  "RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU",
  "END:DAYLIGHT",
  "BEGIN:STANDARD",
  "TZOFFSETFROM:+0200",
  "TZOFFSETTO:+0100",
  "TZNAME:CET",
  "DTSTART:19701025T030000",
  "RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU",
  "END:STANDARD",
  "END:VTIMEZONE",
];

const icsDate = (date) => date.replaceAll("-", "");
const icsLocal = (dateTime) => dateTime.replace(/[-:]/g, "");

/** Local wall time in `timeZone` → UTC "YYYYMMDDTHHMMSSZ" (for zones without a VTIMEZONE block). */
function icsUtc(dateTime, timeZone) {
  const [d, t] = dateTime.split("T");
  const [y, mo, da] = d.split("-").map(Number);
  const [h, mi] = t.split(":").map(Number);
  const guess = Date.UTC(y, mo - 1, da, h, mi);
  const fmt = new Intl.DateTimeFormat("en-US", {
    timeZone, hourCycle: "h23", year: "numeric", month: "2-digit", day: "2-digit",
    hour: "2-digit", minute: "2-digit",
  });
  const offsetAt = (ms) => {
    const p = Object.fromEntries(fmt.formatToParts(ms).map((x) => [x.type, x.value]));
    return Date.UTC(p.year, p.month - 1, p.day, p.hour, p.minute) - ms;
  };
  const utc = guess - offsetAt(guess - offsetAt(guess));
  return new Date(utc).toISOString().replace(/[-:]/g, "").slice(0, 15) + "Z";
}

const escapeText = (s) =>
  String(s).replace(/\\/g, "\\\\").replace(/;/g, "\\;").replace(/,/g, "\\,").replace(/\r?\n/g, "\\n");

/** Folds a content line at 75 octets (RFC 5545 §3.1). */
function fold(line) {
  const bytes = new TextEncoder().encode(line);
  if (bytes.length <= 75) return line;
  const out = [];
  let current = "";
  let size = 0;
  for (const ch of line) {
    const n = new TextEncoder().encode(ch).length;
    if (size + n > (out.length ? 74 : 75)) {
      out.push(current);
      current = "";
      size = 0;
    }
    current += ch;
    size += n;
  }
  out.push(current);
  return out.join("\r\n ");
}

export function buildIcs({ days, settings, person }) {
  const tz = settings.timeZone || "Europe/Zurich";
  const withTzid = CENTRAL_EUROPE.has(tz);
  const stamp = new Date().toISOString().replace(/[-:]/g, "").replace(/\.\d+/, "");
  const events = days.map((d) => eventFor(d, settings, person)).filter(Boolean);
  const lines = [
    "BEGIN:VCALENDAR",
    "VERSION:2.0",
    "PRODID:-//Amor//Dienstplan//EN",
    "CALSCALE:GREGORIAN",
    "METHOD:PUBLISH",
    ...(withTzid && events.some((e) => !e.allDay) ? vtimezone(tz) : []),
  ];
  for (const e of events) {
    lines.push("BEGIN:VEVENT", `UID:${e.id}`, `DTSTAMP:${stamp}`);
    if (e.allDay) {
      lines.push(`DTSTART;VALUE=DATE:${icsDate(e.start)}`, `DTEND;VALUE=DATE:${icsDate(e.end)}`);
    } else if (withTzid) {
      lines.push(`DTSTART;TZID=${tz}:${icsLocal(e.start)}`, `DTEND;TZID=${tz}:${icsLocal(e.end)}`);
    } else {
      lines.push(`DTSTART:${icsUtc(e.start, tz)}`, `DTEND:${icsUtc(e.end, tz)}`);
    }
    lines.push(
      `SUMMARY:${escapeText(e.title)}`,
      `DESCRIPTION:${escapeText(`Code ${e.code} – ${person}`)}`,
      "TRANSP:OPAQUE",
      "END:VEVENT",
    );
  }
  lines.push("END:VCALENDAR");
  return { text: lines.map(fold).join("\r\n") + "\r\n", count: events.length };
}

/** Share sheet where files can be shared (iOS, also home-screen mode), else a download. */
export async function deliverIcs(text, filename) {
  const file = new File([text], filename, { type: "text/calendar" });
  if (navigator.canShare?.({ files: [file] })) {
    try {
      await navigator.share({ files: [file], title: filename });
      return "shared";
    } catch (err) {
      if (err.name === "AbortError") return "cancelled";
      // NotAllowedError etc.: fall through to download
    }
  }
  const url = URL.createObjectURL(file);
  const a = Object.assign(document.createElement("a"), { href: url, download: filename });
  document.body.append(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 30_000);
  return "downloaded";
}
