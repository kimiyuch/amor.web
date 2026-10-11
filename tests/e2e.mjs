// End-to-end run in a phone-sized browser against a local Amor with AMOR_FAKE_READER=true,
// AMOR_PASSWORD=amor and a queue worker running (php artisan queue:work).
// Usage: npm run e2e [-- webkit]; screenshots go to tests/screenshots/.
import { chromium, devices, webkit } from 'playwright';
import assert from 'node:assert/strict';
import { mkdirSync } from 'node:fs';

const BASE = process.env.BASE_URL ?? 'https://amor.web.test';
const PASSWORD = process.env.AMOR_PASSWORD ?? 'amor';
const engine = process.argv[2] === 'webkit' ? webkit : chromium;
const shots = new URL('./screenshots/', import.meta.url).pathname;
const fixture = new URL('./fixtures/roster.jpg', import.meta.url).pathname;
mkdirSync(shots, { recursive: true });

const browser = await engine.launch();
const context = await browser.newContext({ ...devices['iPhone 12'], ignoreHTTPSErrors: true });
const page = await context.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
const rows = page.locator('[data-day]');

// Password
await page.goto(BASE);
assert.match(page.url(), /\/login$/);
await page.getByLabel('Password').fill('wrong');
await page.getByRole('button', { name: 'Open Amor' }).click();
await page.getByText("That password isn't right.").waitFor();
await page.getByLabel('Password').fill(PASSWORD);
await page.screenshot({ path: shots + '1-login.png' });
await page.getByRole('button', { name: 'Open Amor' }).click();
await page.getByRole('button', { name: 'Read shifts' }).waitFor();

// Scan
await page.getByLabel('Name in the roster').fill('Anna Muster');
await page.locator('[data-photo-input]').setInputFiles(fixture);
await page.locator('[data-photo-preview]').waitFor();
const previewSize = () => page.locator('[data-photo-preview]').evaluate((img) => [img.naturalWidth, img.naturalHeight]);
await page.waitForFunction(() => document.querySelector('[data-photo-preview]').naturalWidth > 0);
const [w, h] = await previewSize();
assert.ok(w > h, 'the fixture is landscape');
await page.getByRole('button', { name: 'Rotate' }).click();
await page.waitForFunction(([w]) => document.querySelector('[data-photo-preview]').naturalHeight === w, [w]);
assert.deepEqual(await previewSize(), [h, w], 'rotated by 90°');
assert.deepEqual(await page.locator('[data-photo-input]').evaluate((i) => [i.files.length, i.files[0].type]), [1, 'image/jpeg']);
await page.screenshot({ path: shots + '2-photo-rotated.png' });
for (let i = 0; i < 3; i++) {
    await page.getByRole('button', { name: 'Rotate' }).click();
    await page.waitForFunction(() => !document.querySelector('[data-photo-rotate]').disabled);
}
assert.deepEqual(await previewSize(), [w, h], 'back upright after four turns');
await page.screenshot({ path: shots + '2-photo.png' });
await page.getByRole('button', { name: 'Read shifts' }).click();
await page.getByRole('heading', { name: 'Reading the roster' }).waitFor();
await page.screenshot({ path: shots + '3-reading.png' });

// Review
await page.getByRole('heading', { name: 'Muster Anna (4711)' }).waitFor({ timeout: 60_000 });
assert.equal(await rows.count(), 34);
assert.equal(await page.locator('[data-day][data-uncertain]').count(), 1);
assert.ok(await page.locator('img[alt="Your row in the photo"]').evaluate((img) => img.complete && img.naturalWidth > 500), 'row strip');
assert.match(await page.locator('[data-summary]').textContent(), /^\d+ shifts, 1 to check$/);
await page.screenshot({ path: shots + '4-review.png' });
await page.screenshot({ path: shots + '4-review-full.png', fullPage: true });

const third = rows.nth(2);
await third.locator('input').fill('HO');
assert.equal(await third.getAttribute('data-kind'), 'allday');
assert.equal(await third.locator('[data-event-title]').textContent(), 'Dienst HO');
await rows.nth(4).locator('input').fill('M1');
assert.match(await page.locator('[data-summary]').textContent(), /^\d+ shifts$/, 'checked cell no longer counts');

await page.getByRole('button', { name: 'Save shifts' }).click();
await page.getByText(/\d+ shifts saved\./).waitFor();
await page.getByRole('heading', { name: 'Add them to your calendar' }).waitFor();
const icsHref = await page.getByRole('link', { name: 'Add to calendar' }).getAttribute('href');
const icsResponse = await context.request.get(icsHref);
assert.match(icsResponse.headers()['content-type'], /^text\/calendar/);
assert.ok((await icsResponse.text()).split('BEGIN:VEVENT').length > 10, 'the file has the saved shifts');
await page.getByRole('heading', { name: 'Coming up' }).waitFor();
await page.screenshot({ path: shots + '5-home.png', fullPage: true });

// Settings: shift times, then the feed has timed events
await page.getByRole('link', { name: 'Settings' }).click();
const codeRow = async (code) => {
    const i = await page.locator('[data-code-row] input[name$="[code]"]').evaluateAll((els, c) => els.findIndex((e) => e.value === c), code);
    assert.ok(i >= 0, `code ${code}`);
    return page.locator('[data-code-row]').nth(i);
};
const one = await codeRow('1');
await one.locator('input[name$="[start]"]').fill('06:45');
await one.locator('input[name$="[end]"]').fill('15:15');
await one.locator('input[name$="[title]"]').fill('Frühdienst');
const night = await codeRow('S1/BD');
await night.locator('input[name$="[start]"]').fill('20:00');
await night.locator('input[name$="[end]"]').fill('07:30');
await (await codeRow('B')).locator('input[type=checkbox]').check();
await page.getByRole('button', { name: 'Add code' }).click();
await page.locator('[data-code-row]').last().locator('input[name$="[code]"]').fill('zz');
await page.screenshot({ path: shots + '6-settings.png' });
await page.getByRole('button', { name: 'Save settings' }).click();
await page.getByText('Settings saved.').waitFor();
assert.ok((await page.locator('[data-code-row] input[name$="[code]"]').evaluateAll((els) => els.map((e) => e.value))).includes('ZZ'));

const feedUrl = await page.getByRole('button', { name: 'Copy link' }).getAttribute('data-copy');
const feed = await (await context.request.get(feedUrl)).text();
assert.match(feed, /BEGIN:VCALENDAR/);
assert.match(feed, /SUMMARY:Frühdienst/);
assert.match(feed, /DTSTART;TZID=Europe\/Zurich:\d{8}T200000\r\nDTEND;TZID=Europe\/Zurich:\d{8}T073000/);
assert.match(await page.getByRole('link', { name: 'Subscribe on iPhone or Mac' }).getAttribute('href'), /^webcal:\/\//);

await page.emulateMedia({ colorScheme: 'dark' });
await page.goto(BASE + '/');
await page.screenshot({ path: shots + '7-home-dark.png', fullPage: true });

assert.deepEqual(errors, []);
console.log(`e2e ok (${engine.name()})`);
await browser.close();
