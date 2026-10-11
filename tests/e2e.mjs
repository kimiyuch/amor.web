// End-to-end run of the whole flow against ?mock (fake Anthropic stream, Google sign-in and
// Calendar API) in a phone-sized browser. Needs `npm install` and `npm run serve` first.
// Usage: npm run e2e [-- webkit] ; screenshots go to tests/screenshots/.
import { chromium, devices, webkit } from "playwright";
import assert from "node:assert/strict";
import { mkdirSync } from "node:fs";

const BASE = process.env.BASE_URL ?? "http://localhost:8000/";
const engine = process.argv[2] === "webkit" ? webkit : chromium;
const shots = new URL("./screenshots/", import.meta.url).pathname;
mkdirSync(shots, { recursive: true });

const browser = await engine.launch();
const context = await browser.newContext({ ...devices["iPhone 12"], acceptDownloads: true });
const page = await context.newPage();
const errors = [];
page.on("pageerror", (e) => errors.push(e.message));
page.on("console", (m) => m.type() === "error" && errors.push(m.text()));

await page.goto(BASE + "?mock");
await page.screenshot({ path: shots + "1-start.png" });

// Settings: API key
await page.locator("#open-settings").click();
await page.locator('input[name="apiKey"]').fill("sk-ant-mock");
await page.getByRole("button", { name: "Done" }).click();

await page.getByLabel("Name in the roster").fill("Anna Muster");
await page.evaluate(() => window.__mock.loadRoster());
await page.locator("#preview").waitFor();
await page.screenshot({ path: shots + "2-photo.png" });

await page.getByRole("button", { name: "Read shifts" }).click();
await page.locator("#status").waitFor();
await page.locator("#result").waitFor({ timeout: 30_000 });

const checks = await page.evaluate(() => window.__mock.checks);
for (const c of checks) assert.ok(c.ok, `mock check failed: ${c.name}`);
assert.equal(checks.length, 13, "both API turns ran");
assert.equal(await page.locator("#days tr:not(.month)").count(), 34);
assert.deepEqual(await page.locator("#days tr.month").allTextContents(), ["December 2026", "January 2027"]);
assert.equal(await page.locator("#days tr.uncertain").count(), 1);
assert.match(await page.locator("#result-title").textContent(), /Muster Anna/);
assert.ok(await page.locator("#strip-img").evaluate((img) => img.complete && img.naturalWidth > 1000), "row strip rendered");
const codes = await page.evaluate(() => JSON.parse(localStorage.getItem("dienstplan.settings.v1")).codes.map((c) => c.code));
assert.ok(codes.includes("X7"), "unknown code added to the table");
await page.screenshot({ path: shots + "3-result.png", fullPage: true });

// Live preview when editing a code
const row3 = page.locator("#days tr:not(.month)").nth(2);
await row3.locator("input").fill("HO");
assert.equal(await row3.locator(".event").textContent(), "Dienst HO · all day");

// Shift times in settings → timed and overnight events
await page.locator("#open-settings").click();
const codeRow = async (code) => {
  const i = await page.locator('#codes input[data-k="code"]').evaluateAll((els, c) => els.findIndex((e) => e.value === c), code);
  assert.ok(i >= 0, `code ${code} in table`);
  return page.locator("#codes .code-row").nth(i);
};
const setTimes = async (code, start, end) => {
  const row = await codeRow(code);
  await row.locator('input[data-k="start"]').fill(start);
  await row.locator('input[data-k="end"]').fill(end);
};
await setTimes("1", "06:45", "15:15");
await setTimes("S1/BD", "20:00", "07:30");
await (await codeRow("B")).locator('input[data-k="skip"]').check();
await page.locator('input[name="googleClientId"]').fill("mock.apps.googleusercontent.com");
await page.screenshot({ path: shots + "4-settings.png" });
await page.getByRole("button", { name: "Done" }).click();
await page.locator("#google").waitFor();
assert.equal(await page.locator("#days tr:not(.month)").nth(0).locator(".event").textContent(), "Dienst 1 · 06:45–15:15");
assert.equal(await page.locator("#days tr:not(.month)").nth(5).locator(".event").textContent(), "Dienst S1/BD · 20:00–07:30 (+1)");
assert.equal(await page.locator("#days tr:not(.month)").nth(9).locator(".event").textContent(), "—"); // B skipped

// .ics: share sheet where files can be shared (WebKit, like iOS), download elsewhere
const canShareFiles = await page.evaluate(() => Boolean(navigator.canShare?.({ files: [new File(["x"], "a.ics", { type: "text/calendar" })] })));
let filename, ics;
if (canShareFiles) {
  await page.evaluate(() => {
    navigator.share = async ({ files }) => { window.__shared = { name: files[0].name, type: files[0].type, text: await files[0].text() }; };
  });
  await page.getByRole("button", { name: "Add to calendar (.ics)" }).click();
  const shared = await page.waitForFunction(() => window.__shared).then((h) => h.jsonValue());
  assert.equal(shared.type, "text/calendar");
  ({ name: filename, text: ics } = shared);
} else {
  const [download] = await Promise.all([page.waitForEvent("download"), page.getByRole("button", { name: "Add to calendar (.ics)" }).click()]);
  filename = download.suggestedFilename();
  ics = await (await download.createReadStream()).toArray().then((c) => Buffer.concat(c).toString());
}
assert.equal(filename, "dienstplan-2026-12-01_2027-01-03.ics");
assert.match(ics, /DTSTART;TZID=Europe\/Zurich:20261201T064500/);
assert.match(ics, /DTSTART;TZID=Europe\/Zurich:20261206T200000\r\nDTEND;TZID=Europe\/Zurich:20261207T073000/);
assert.match(ics, /BEGIN:VTIMEZONE/);

// Google: a stale event on an empty day must be removed, re-sync must not duplicate
const staleId = await page.evaluate(async () => {
  const { eventId } = await import("./calendar.js");
  const id = eventId("Anna Muster", "2026-12-04");
  window.__mock.calendar.set(id, { id, summary: "old" });
  return id;
});
await page.getByRole("button", { name: "Add to Google Calendar" }).click();
await page.getByText(/events saved/).waitFor();
const status1 = await page.locator("#sync-status").textContent();
const eventCount = await page.evaluate(() => window.__mock.calendar.size);
assert.ok(!(await page.evaluate((id) => window.__mock.calendar.has(id), staleId)), "stale event removed");
assert.match(status1, /^\d+ events saved, 1 removed\.$/);
await page.getByRole("button", { name: "Add to Google Calendar" }).click();
await page.waitForFunction(() => /0 removed|saved\.$/.test(document.querySelector("#sync-status").textContent) && !document.querySelector("#google-sync").disabled);
assert.equal(await page.evaluate(() => window.__mock.calendar.size), eventCount, "re-sync updated, no duplicates");
const calls = await page.evaluate(() => window.__mock.calls);
assert.ok(calls.filter((c) => c === "token").length === 1, "token cached between syncs");
const stored = await page.evaluate(() => [...window.__mock.calendar.values()].find((e) => e.summary === "Dienst S1/BD"));
assert.deepEqual(stored.start, { dateTime: "2026-12-06T20:00:00", timeZone: "Europe/Zurich" });
assert.equal(stored.extendedProperties.private.dienstCode, "S1/BD");
await page.screenshot({ path: shots + "5-synced.png", fullPage: true });

await page.emulateMedia({ colorScheme: "dark" });
await page.screenshot({ path: shots + "6-dark.png", fullPage: true });

assert.deepEqual(errors, []);
console.log(`e2e ok (${engine.name()}, .ics via ${canShareFiles ? "share sheet" : "download"}): ${checks.length} API checks, ${eventCount} Google events, status "${status1}"`);
await browser.close();
