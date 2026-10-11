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
await page.screenshot({ path: shots + "2b-reading.png" });
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
await page.screenshot({ path: shots + "3a-result-top.png" });
await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
await page.screenshot({ path: shots + "3b-result-bottom.png" });
await page.evaluate(() => window.scrollTo(0, 0));

// Live preview when editing a code
const eventText = (i) => page.locator("#days tr:not(.month)").nth(i).locator(".event").evaluate((td) => [...td.children].map((c) => c.textContent).join(" | "));
assert.equal(await eventText(4), "Dienst M1 | All day | Check on the photo");
assert.match(await page.locator("#result-summary").textContent(), /^\d+ shifts, 1 to check$/);
const row3 = page.locator("#days tr:not(.month)").nth(2);
await row3.locator("input").fill("HO");
assert.equal(await eventText(2), "Dienst HO | All day");
assert.ok(await page.locator("#scan").isHidden(), "scan step hidden after reading");

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
await page.locator("#google-sync").waitFor();
assert.equal(await eventText(0), "Dienst 1 | 06:45 – 15:15");
assert.equal(await eventText(5), "Dienst S1/BD | 20:00 – 07:30 next day");
assert.equal(await eventText(9), "Not added"); // B skipped
assert.equal(await eventText(3), "Free");

// .ics: share sheet where files can be shared (WebKit, like iOS), download elsewhere
const canShareFiles = await page.evaluate(() => Boolean(navigator.canShare?.({ files: [new File(["x"], "a.ics", { type: "text/calendar" })] })));
let filename, ics;
if (canShareFiles) {
  await page.evaluate(() => {
    navigator.share = async ({ files }) => { window.__shared = { name: files[0].name, type: files[0].type, text: await files[0].text() }; };
  });
  await page.getByRole("button", { name: "Add to calendar" }).click();
  const shared = await page.waitForFunction(() => window.__shared).then((h) => h.jsonValue());
  assert.equal(shared.type, "text/calendar");
  ({ name: filename, text: ics } = shared);
} else {
  const [download] = await Promise.all([page.waitForEvent("download"), page.getByRole("button", { name: "Add to calendar" }).click()]);
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
await page.getByRole("button", { name: "Google Calendar" }).click();
await page.getByText(/events saved/).waitFor();
const status1 = await page.locator("#sync-status").textContent();
const eventCount = await page.evaluate(() => window.__mock.calendar.size);
assert.ok(!(await page.evaluate((id) => window.__mock.calendar.has(id), staleId)), "stale event removed");
assert.match(status1, /^\d+ events saved, 1 removed\.$/);
await page.getByRole("button", { name: "Google Calendar" }).click();
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

// Setup link: share it here, open it on a fresh device, and paste it on another
await page.emulateMedia({ colorScheme: "light" });
await page.locator("#open-settings").click();
await page.evaluate(() => { navigator.share = async ({ url }) => { window.__setupUrl = url; }; });
await page.getByRole("button", { name: "Share setup link" }).click();
const setupUrl = await page.waitForFunction(() => window.__setupUrl).then((h) => h.jsonValue());
await page.screenshot({ path: shots + "7-setup-link.png" });
await page.getByRole("button", { name: "Done" }).click();

const fresh = await (await browser.newContext(devices["iPhone 12"])).newPage();
await fresh.goto(BASE + "?mock" + new URL(setupUrl).hash);
assert.equal(await fresh.locator("#setup-done").textContent(), "Amor is set up on this device.");
assert.equal(new URL(fresh.url()).hash, "", "settings removed from the address bar");
const moved = await fresh.evaluate(() => JSON.parse(localStorage.getItem("dienstplan.settings.v1")));
assert.equal(moved.apiKey, "sk-ant-mock");
assert.equal(moved.person, "Anna Muster");
assert.equal(moved.codes.find((c) => c.code === "1").start, "06:45");
assert.equal(await fresh.locator("#person").inputValue(), "Anna Muster");

const pasted = await (await browser.newContext(devices["iPhone 12"])).newPage();
await pasted.goto(BASE + "?mock");
await pasted.locator("#open-settings").click();
await pasted.locator("#setup-input").fill("no link");
assert.equal(await pasted.locator("#setup-status").textContent(), "That isn't a setup link.");
await pasted.locator("#setup-input").fill(setupUrl);
assert.equal(await pasted.locator("#setup-status").textContent(), "Settings imported.");
assert.equal(await pasted.locator("#set-api-key").inputValue(), "sk-ant-mock");

assert.deepEqual(errors, []);
console.log(`e2e ok (${engine.name()}, .ics via ${canShareFiles ? "share sheet" : "download"}): ${checks.length} API checks, ${eventCount} Google events, status "${status1}"`);
await browser.close();
