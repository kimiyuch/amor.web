// Run with: node --test
import { test } from "node:test";
import assert from "node:assert/strict";
import { addDays, buildIcs, eventFor, eventId, findCode, normalizeCode } from "../public/calendar.js";

const settings = {
  timeZone: "Europe/Zurich",
  codes: [
    { code: "1", title: "Frühdienst", start: "06:45", end: "15:15", skip: false },
    { code: "S1/BD", title: "", start: "20:00", end: "07:30", skip: false },
    { code: "HO", title: "Home Office", start: "", end: "", skip: false },
    { code: "B", title: "", start: "", end: "", skip: true },
  ],
};

test("codes match case- and whitespace-insensitively", () => {
  assert.equal(normalizeCode(" s1 / bd "), "S1/BD");
  assert.equal(findCode(settings.codes, "s1/ BD").code, "S1/BD");
  assert.equal(findCode(settings.codes, ""), null);
});

test("event IDs are stable, per person, and use only 0-9a-v", () => {
  const id = eventId("Muster Anna", "2026-12-01");
  assert.equal(id, eventId("  muster   anna ", "2026-12-01"));
  assert.notEqual(id, eventId("Keller Sara", "2026-12-01"));
  assert.match(id, /^dienst[0-9a-v]+20261201$/);
});

test("timed, overnight, all-day and skipped codes", () => {
  const p = "Muster Anna";
  const timed = eventFor({ date: "2026-12-01", code: "1" }, settings, p);
  assert.deepEqual([timed.allDay, timed.start, timed.end, timed.title], [false, "2026-12-01T06:45:00", "2026-12-01T15:15:00", "Frühdienst"]);

  const night = eventFor({ date: "2026-12-31", code: "s1/bd" }, settings, p);
  assert.equal(night.end, "2027-01-01T07:30:00");
  assert.equal(night.title, "Dienst s1/bd");

  const allDay = eventFor({ date: "2026-12-02", code: "HO" }, settings, p);
  assert.deepEqual([allDay.allDay, allDay.start, allDay.end], [true, "2026-12-02", "2026-12-03"]);

  const unknown = eventFor({ date: "2026-12-03", code: "X7" }, settings, p);
  assert.deepEqual([unknown.allDay, unknown.title], [true, "Dienst X7"]);

  assert.equal(eventFor({ date: "2026-12-04", code: "B" }, settings, p), null);
  assert.equal(eventFor({ date: "2026-12-04", code: "" }, settings, p), null);
});

test("addDays crosses month and year boundaries", () => {
  assert.equal(addDays("2026-12-31", 1), "2027-01-01");
  assert.equal(addDays("2028-02-28", 1), "2028-02-29");
});

test(".ics uses TZID times with a VTIMEZONE and VALUE=DATE for all-day events", () => {
  const days = [
    { date: "2026-12-01", code: "1" },
    { date: "2026-12-02", code: "HO" },
    { date: "2026-12-03", code: "" },
    { date: "2026-12-04", code: "B" },
  ];
  const { text, count } = buildIcs({ days, settings, person: "Muster Anna" });
  assert.equal(count, 2);
  assert.ok(text.includes("\r\n"));
  assert.match(text, /BEGIN:VTIMEZONE\r\nTZID:Europe\/Zurich/);
  assert.match(text, /DTSTART;TZID=Europe\/Zurich:20261201T064500/);
  assert.match(text, /DTSTART;VALUE=DATE:20261202\r\nDTEND;VALUE=DATE:20261203/);
  assert.match(text, new RegExp(`UID:${eventId("Muster Anna", "2026-12-01")}`));
  for (const line of text.split("\r\n")) assert.ok(new TextEncoder().encode(line).length <= 75, line);
});

test(".ics outside Central Europe falls back to UTC times", () => {
  const ny = { ...settings, timeZone: "America/New_York" };
  const { text } = buildIcs({ days: [{ date: "2026-07-01", code: "1" }], settings: ny, person: "A" });
  assert.ok(!text.includes("VTIMEZONE"));
  assert.match(text, /DTSTART:20260701T104500Z/); // 06:45 EDT = 10:45 UTC
});
