// UI and settings.
if (new URLSearchParams(location.search).has("mock")) await import("./mock.js");

import { buildIcs, deliverIcs, describeEvent, eventFor, findCode, normalizeCode, syncToGoogle } from "./calendar.js";

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
  person: "",
  abort: null,
};

// ---------- formatting ----------

const WEEKDAYS = ["Su", "Mo", "Tu", "We", "Th", "Fr", "Sa"];
function formatDate(date) {
  const [y, m, d] = date.split("-").map(Number);
  const wd = WEEKDAYS[new Date(Date.UTC(y, m - 1, d)).getUTCDay()];
  return `${wd} ${String(d).padStart(2, "0")}.${String(m).padStart(2, "0")}.`;
}
const formatLong = (date) => date.split("-").reverse().join(".");

function show(el, visible) {
  el.hidden = !visible;
}

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
    $("photo-label").textContent = "Choose another photo";
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
      showError(`"${person}" was not found in this roster.${result.notes ? ` ${result.notes}` : ""}`);
      show($("result"), false);
      return;
    }
    state.person = person;
    state.result = result;
    addUnknownCodes(result.days);
    await renderResult();
    $("result").scrollIntoView({ behavior: "smooth", block: "start" });
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
  $("result-notes").textContent = r.notes;
  show($("result-notes"), Boolean(r.notes?.trim()));

  await renderStrip(r.row_box);

  const tbody = $("days");
  tbody.replaceChildren();
  for (const [i, day] of r.days.entries()) {
    const tr = document.createElement("tr");
    tr.classList.toggle("uncertain", day.uncertain);
    tr.classList.toggle("weekend", [0, 6].includes(new Date(day.date).getUTCDay()));
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
      tr.classList.remove("uncertain");
      tr.querySelector(".event").textContent = describeEvent(eventFor(r.days[i], settings, state.person));
    });
    tr.append(
      Object.assign(document.createElement("td"), { textContent: formatDate(day.date), className: "date" }),
      Object.assign(document.createElement("td"), { className: "code" }),
      Object.assign(document.createElement("td"), {
        className: "event",
        textContent: describeEvent(eventFor(day, settings, state.person)),
      }),
    );
    tr.children[1].append(input);
    tbody.append(tr);
  }
  show($("uncertain-hint"), r.days.some((d) => d.uncertain));
  show($("google"), Boolean(settings.googleClientId));
  show($("android-hint"), /Android/i.test(navigator.userAgent));
  show($("sync-status"), false);
  show($("result"), true);
}

function refreshPreviews() {
  if (!state.result) return;
  const rows = $("days").children;
  state.result.days.forEach((day, i) => {
    rows[i].querySelector(".event").textContent = describeEvent(eventFor(day, settings, state.person));
  });
  show($("google"), Boolean(settings.googleClientId));
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
  if (outcome === "downloaded") syncStatus(`Downloaded a file with ${count} events. Open it to add them.`);
  else if (outcome === "shared") syncStatus(`Shared ${count} events.`);
});

$("google-sync").addEventListener("click", async () => {
  const button = $("google-sync");
  button.disabled = true;
  try {
    const { saved, removed } = await syncToGoogle({
      days: state.result.days,
      settings,
      person: state.person,
      removeEmpty: $("remove-empty").checked,
      onProgress: (done, total) => syncStatus(`Saving to Google Calendar… ${done}/${total}`),
    });
    syncStatus(`${saved} ${saved === 1 ? "event" : "events"} saved${removed ? `, ${removed} removed` : ""}.`);
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
  renderCodes();
  dialog.showModal();
}

$("open-settings").addEventListener("click", openSettings);
document.querySelectorAll("[data-open-settings]").forEach((b) => b.addEventListener("click", openSettings));

form.addEventListener("input", (event) => {
  const name = event.target.name;
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
