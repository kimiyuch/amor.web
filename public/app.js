// UI and settings.
if (new URLSearchParams(location.search).has("mock")) await import("./mock.js");

import { buildIcs, deliverIcs, eventFor, findCode, normalizeCode, syncToGoogle } from "./calendar.js";

const extractModule = () => import("./extract.js");
const $ = (id) => document.getElementById(id);
const STORE = "dienstplan.settings.v1";

const DEFAULT_CODES = [
  "1", "1T", "1E", "1P", "M1", "M3", "6A", "8B", "9A", "9B", "2S", "3F", "S1/BD", "B", "HO", "EI", "DI",
].map((code) => ({ code, title: "", start: "", end: "", skip: false }));

const DEFAULTS = {
  person: "",
  apiKey: "",
  model: "claude-opus-5-5",
  googleClientId: "",
  calendarId: "primary",
  timeZone: "Europe/Zurich",
  removeEmpty: true,
  codes: DEFAULT_CODES,
};

function loadSettings() {
  try {
    return { ...DEFAULTS, ...JSON.parse(localStorage.getItem(STORE) ?? "{}") };
  } catch {
    return structuredClone(DEFAULTS);
  }
}

const settings = loadSettings();
const saveSettings = () => localStorage.setItem(STORE, JSON.stringify(settings));

const state = {
  file: null,
  bitmap: null,
  previewUrl: null,
  stripUrl: null,
  result: null, // { row_label, period_start, period_end, days, notes, ... }
  rows: [], // the table row of each day
  person: "",
  abort: null,
};

// ---------- formatting ----------

const WEEKDAYS = ["Su", "Mo", "Tu", "We", "Th", "Fr", "Sa"];
const MONTHS = new Intl.DateTimeFormat("en", { month: "long", year: "numeric", timeZone: "UTC" });
const weekday = (date) => WEEKDAYS[new Date(date).getUTCDay()];
const formatDay = (date) => `${date.slice(8)}.${date.slice(5, 7)}.`;
const formatMonth = (date) => MONTHS.format(new Date(date));
function formatDate(date) {
  const [y, m, d] = date.split("-").map(Number);
  const wd = WEEKDAYS[new Date(Date.UTC(y, m - 1, d)).getUTCDay()];
  return `${wd} ${String(d).padStart(2, "0")}.${String(m).padStart(2, "0")}.`;
}
const formatLong = (date) => date.split("-").reverse().join(".");
const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;
const today = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
};

function show(el, visible) {
  el.hidden = !visible;
}

/** Switches between the scan step and the result step. */
function setView(view) {
  show($("scan"), view === "scan");
  show($("result"), view === "result");
  show($("action-bar"), view === "result");
  window.scrollTo({ top: 0 });
}

const WARNING_ICON = `<svg viewBox="0 0 16 16" aria-hidden="true"><path fill-rule="evenodd" d="M6.701 2.25c.577-1 2.02-1 2.598 0l5.196 9a1.5 1.5 0 0 1-1.299 2.25H2.804a1.5 1.5 0 0 1-1.3-2.25l5.197-9ZM8 4a.75.75 0 0 1 .75.75v3a.75.75 0 1 1-1.5 0v-3A.75.75 0 0 1 8 4Zm0 8a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"/></svg>`;

// ---------- scan screen ----------

function updateScanState() {
  const ready = Boolean(settings.apiKey && $("person").value.trim() && state.bitmap && !state.abort);
  $("read").disabled = !ready;
  show($("setup-hint"), !settings.apiKey);
}

$("person").value = settings.person;
$("person").addEventListener("input", () => {
  settings.person = $("person").value.trim();
  saveSettings();
  updateScanState();
});

$("photo").addEventListener("change", async () => {
  const file = $("photo").files?.[0];
  if (!file) return;
  show($("error"), false);
  try {
    const { loadPhoto } = await extractModule();
    state.bitmap?.close?.();
    state.bitmap = await loadPhoto(file);
    state.file = file;
    if (state.previewUrl) URL.revokeObjectURL(state.previewUrl);
    state.previewUrl = URL.createObjectURL(file);
    $("preview").src = state.previewUrl;
    show($("preview"), true);
    show($("photo-empty"), false);
    show($("photo-change"), true);
    show($("intro"), false);
  } catch (err) {
    showError("This photo could not be opened. Try a JPEG or PNG.");
    console.error(err);
  }
  updateScanState();
});

function setStatus(text) {
  show($("status"), Boolean(text));
  $("status-text").textContent = text ?? "";
}

function showError(message) {
  $("error").textContent = message;
  show($("error"), Boolean(message));
}

$("read").addEventListener("click", async () => {
  const person = $("person").value.trim();
  if (!person || !state.bitmap) return;
  showError("");
  state.abort = new AbortController();
  updateScanState();
  setStatus("Preparing photo…");
  try {
    const { extractShifts } = await extractModule();
    const { result, usage, turns } = await extractShifts({
      bitmap: state.bitmap,
      person,
      apiKey: settings.apiKey,
      model: settings.model || DEFAULTS.model,
      onStatus: setStatus,
      signal: state.abort.signal,
    });
    console.info(`Read in ${turns} turns, ${usage.input} input / ${usage.output} output tokens`);
    if (!result.person_found) {
      showError(`“${person}” isn't in this roster.${result.notes ? ` ${result.notes}` : ""}`);
      return;
    }
    state.person = person;
    state.result = result;
    addUnknownCodes(result.days);
    await renderResult();
    setView("result");
  } catch (err) {
    if (err.name !== "AbortError") {
      showError(err.message || String(err));
      console.error(err);
    }
  } finally {
    state.abort = null;
    setStatus(null);
    updateScanState();
  }
});

$("cancel").addEventListener("click", () => state.abort?.abort());
$("new-scan").addEventListener("click", () => setView("scan"));

function addUnknownCodes(days) {
  let added = false;
  for (const { code } of days) {
    const norm = normalizeCode(code);
    if (norm && !findCode(settings.codes, norm)) {
      settings.codes.push({ code: norm, title: "", start: "", end: "", skip: false });
      added = true;
    }
  }
  if (added) {
    saveSettings();
    renderCodes();
  }
}

// ---------- result screen ----------

async function renderResult() {
  const r = state.result;
  $("result-title").textContent = r.row_label || state.person;
  $("result-period").textContent =
    r.period_start && r.period_end ? `${formatLong(r.period_start)} – ${formatLong(r.period_end)}` : "";
  $("result-notes").innerHTML = WARNING_ICON;
  $("result-notes").append(Object.assign(document.createElement("span"), { textContent: r.notes }));
  show($("result-notes"), Boolean(r.notes?.trim()));

  await renderStrip(r.row_box);

  const tbody = $("days");
  tbody.replaceChildren();
  state.rows = [];
  let month = "";
  for (const [i, day] of r.days.entries()) {
    if (day.date.slice(0, 7) !== month) {
      month = day.date.slice(0, 7);
      const heading = document.createElement("tr");
      heading.className = "month";
      heading.append(Object.assign(document.createElement("td"), { colSpan: 3, textContent: formatMonth(day.date) }));
      tbody.append(heading);
    }
    const tr = document.createElement("tr");
    tr.classList.toggle("weekend", [0, 6].includes(new Date(day.date).getUTCDay()));
    tr.classList.toggle("today", day.date === today());
    const input = Object.assign(document.createElement("input"), {
      type: "text",
      value: day.code,
      spellcheck: false,
      autocapitalize: "characters",
    });
    input.setAttribute("aria-label", `Code for ${formatDate(day.date)}`);
    input.addEventListener("input", () => {
      r.days[i].code = input.value.trim();
      r.days[i].uncertain = false;
      updateRow(i);
      updateSummary();
    });
    const date = Object.assign(document.createElement("td"), { className: "date" });
    date.append(
      Object.assign(document.createElement("b"), { textContent: weekday(day.date) }),
      Object.assign(document.createElement("time"), { dateTime: day.date, textContent: formatDay(day.date) }),
    );
    tr.append(
      date,
      Object.assign(document.createElement("td"), { className: "code" }),
      Object.assign(document.createElement("td"), { className: "event" }),
    );
    tr.children[1].append(input);
    tbody.append(tr);
    state.rows.push(tr);
    updateRow(i);
  }
  updateSummary();
  show($("google-sync"), Boolean(settings.googleClientId));
  show($("android-hint"), /Android/i.test(navigator.userAgent));
  show($("sync-status"), false);
}

function updateSummary() {
  const days = state.result.days;
  const shifts = days.filter((d) => eventFor(d, settings, state.person)).length;
  const unsure = days.filter((d) => d.uncertain).length;
  $("result-summary").textContent = plural(shifts, "shift", "shifts") + (unsure ? `, ${unsure} to check` : "");
}

/** Shows the resulting event of day i and colors its code by kind. */
function updateRow(i) {
  const day = state.result.days[i];
  const event = eventFor(day, settings, state.person);
  const tr = state.rows[i];
  const kind = event ? (event.allDay ? "allday" : "timed") : day.code.trim() ? "skipped" : "free";
  for (const k of ["allday", "timed", "skipped", "free"]) tr.classList.toggle(k, k === kind);
  tr.classList.toggle("uncertain", day.uncertain);

  const title = { allday: event?.title, timed: event?.title, skipped: "Not added", free: "Free" }[kind];
  let when = "";
  if (kind === "allday") when = "All day";
  if (kind === "timed") {
    const nextDay = event.end.slice(0, 10) !== event.date;
    when = `${event.start.slice(11, 16)} – ${event.end.slice(11, 16)}${nextDay ? " next day" : ""}`;
  }
  const cell = tr.querySelector(".event");
  cell.replaceChildren(Object.assign(document.createElement("div"), { className: "event-title", textContent: title }));
  if (when) cell.append(Object.assign(document.createElement("div"), { className: "event-when", textContent: when }));
  if (day.uncertain) {
    const note = Object.assign(document.createElement("div"), { className: "check-note", innerHTML: WARNING_ICON });
    note.append("Check on the photo");
    cell.append(note);
  }
}

function refreshPreviews() {
  if (!state.result) return;
  state.result.days.forEach((_, i) => updateRow(i));
  updateSummary();
  show($("google-sync"), Boolean(settings.googleClientId));
}

async function renderStrip(rowBox) {
  const { cleanBox, crop } = await extractModule();
  const box = cleanBox(rowBox);
  if (!box) return show($("strip-wrap"), false);
  // Pad vertically so neighbouring rows are visible for comparison.
  const pad = Math.max((box.y1 - box.y0) * 0.6, 0.006);
  const padded = cleanBox({ x0: box.x0 - 0.01, x1: box.x1 + 0.01, y0: box.y0 - pad, y1: box.y1 + pad });
  const canvas = crop(state.bitmap, padded, 6000);
  const blob = await new Promise((resolve) => canvas.toBlob(resolve, "image/jpeg", 0.9));
  if (state.stripUrl) URL.revokeObjectURL(state.stripUrl);
  state.stripUrl = URL.createObjectURL(blob);
  $("strip-img").src = state.stripUrl;
  show($("strip-wrap"), true);
}

$("ics").addEventListener("click", async () => {
  const r = state.result;
  const { text, count } = buildIcs({ days: r.days, settings, person: state.person });
  if (!count) return syncStatus("No shifts to add.");
  const period = [r.period_start, r.period_end].filter(Boolean).join("_") || "shifts";
  const outcome = await deliverIcs(text, `dienstplan-${period}.ics`);
  if (outcome === "downloaded") syncStatus(`Downloaded a file with ${plural(count, "event", "events")}. Open it to add them.`);
  else if (outcome === "shared") syncStatus(`Shared ${plural(count, "event", "events")}.`);
});

$("google-sync").addEventListener("click", async () => {
  const button = $("google-sync");
  button.disabled = true;
  try {
    const { saved, removed } = await syncToGoogle({
      days: state.result.days,
      settings,
      person: state.person,
      removeEmpty: settings.removeEmpty,
      onProgress: (done, total) => syncStatus(`Saving to Google Calendar… ${done}/${total}`),
    });
    syncStatus(`${plural(saved, "event", "events")} saved${removed ? `, ${removed} removed` : ""}.`);
  } catch (err) {
    syncStatus(err.message || String(err), true);
    console.error(err);
  } finally {
    button.disabled = false;
  }
});

function syncStatus(text, isError = false) {
  const el = $("sync-status");
  el.textContent = text;
  el.classList.toggle("error", isError);
  show(el, true);
}

// ---------- settings ----------

const dialog = $("settings");
const form = $("settings-form");
const FIELDS = ["apiKey", "model", "googleClientId", "calendarId", "timeZone"];

function openSettings() {
  for (const name of FIELDS) form.elements[name].value = settings[name] ?? "";
  form.elements.removeEmpty.checked = settings.removeEmpty;
  renderCodes();
  dialog.showModal();
}

$("open-settings").addEventListener("click", openSettings);
document.querySelectorAll("[data-open-settings]").forEach((b) => b.addEventListener("click", openSettings));

form.addEventListener("input", (event) => {
  const name = event.target.name;
  if (name === "removeEmpty") {
    settings.removeEmpty = event.target.checked;
    saveSettings();
  }
  if (!FIELDS.includes(name)) return;
  settings[name] = event.target.value.trim();
  saveSettings();
});

dialog.addEventListener("close", () => {
  if (!settings.model) settings.model = DEFAULTS.model;
  if (!settings.calendarId) settings.calendarId = DEFAULTS.calendarId;
  if (!settings.timeZone) settings.timeZone = DEFAULTS.timeZone;
  settings.codes = settings.codes.filter((c) => c.code.trim());
  saveSettings();
  updateScanState();
  refreshPreviews();
});

function renderCodes() {
  const list = $("codes");
  list.replaceChildren();
  settings.codes.forEach((entry) => {
    const row = $("code-row").content.firstElementChild.cloneNode(true);
    for (const input of row.querySelectorAll("[data-k]")) {
      const key = input.dataset.k;
      if (input.type === "checkbox") input.checked = Boolean(entry[key]);
      else input.value = entry[key] ?? "";
      input.addEventListener("input", () => {
        entry[key] = input.type === "checkbox" ? input.checked : input.value.trim();
        saveSettings();
        refreshPreviews();
      });
    }
    row.querySelector("[data-remove]").addEventListener("click", () => {
      settings.codes.splice(settings.codes.indexOf(entry), 1);
      saveSettings();
      row.remove();
    });
    list.append(row);
  });
}

$("add-code").addEventListener("click", () => {
  settings.codes.push({ code: "", title: "", start: "", end: "", skip: false });
  renderCodes();
  $("codes").lastElementChild.querySelector("input").focus();
  $("codes").lastElementChild.scrollIntoView({ block: "nearest" });
});

// ---------- start ----------

updateScanState();
if ("serviceWorker" in navigator && !new URLSearchParams(location.search).has("mock")) {
  navigator.serviceWorker.register("sw.js").catch((err) => console.warn("Service worker:", err));
}
