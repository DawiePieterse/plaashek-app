import { test } from "node:test";
import assert from "node:assert/strict";
import { NORMAL_FROM, NORMAL_TO, daysBetween, frostNights, localHour, rainRecord, seasonAverage, seasonStart,
         seasonToDate, sumBetween } from "../../public/modules/weer/js/farm.js";
import { addDays } from "../../public/modules/weer/js/dates.js";

// Hourly series from `from` for `hours` hours, every hour `mm`.
function hourly(from, hours, mm = 1) {
  const time = [];
  const precipitation = [];
  let day = from.slice(0, 10);
  let h = Number(from.slice(11, 13));
  for (let i = 0; i < hours; i++) {
    time.push(`${day}T${String(h).padStart(2, "0")}:00`);
    precipitation.push(mm);
    if (++h === 24) { h = 0; day = addDays(day, 1); }
  }
  return { time, precipitation };
}

test("localHour gives a farm-local hour comparable with Open-Meteo's times", () => {
  assert.equal(localHour(new Date(2026, 9, 4, 7, 45)), "2026-10-04T07:00");
});

test("seasonStart is the most recent 1st of the month", () => {
  assert.equal(seasonStart("2026-10-24", 7), "2026-07-01");
  assert.equal(seasonStart("2026-07-01", 7), "2026-07-01");
  assert.equal(seasonStart("2026-06-30", 7), "2025-07-01");
  assert.equal(seasonStart("2026-02-10", 1), "2026-01-01");
});

test("sumBetween excludes the start, includes the end, skips gaps, and says null for nothing", () => {
  const t = ["2026-10-01T00:00", "2026-10-01T01:00", "2026-10-01T02:00"];
  assert.equal(sumBetween(t, [1, null, 2.25], "2026-10-01T00:00", "2026-10-01T02:00"), 2.3);
  assert.equal(sumBetween(t, [1, 1, 1], "2026-10-02T00:00", "2026-10-03T00:00"), null);
});

test("rainRecord counts back 24 hours, 7 and 30 days from now, and ignores the forecast", () => {
  const h = hourly("2026-09-20T00:00", 40 * 24, 0.5);   // past and future, half a mm every hour
  const r = rainRecord(h, "2026-10-24T10:00");
  assert.equal(r.h24, 12);
  assert.equal(r.d7, 84);
  assert.equal(r.d30, 360);
});

test("seasonToDate joins the archive with the recent days it hasn't reached, without counting a day twice", () => {
  // Today 2026-10-24: the archive stops at 2026-10-17 (lag 6 + 1).
  const archive = { time: [], precipitation_sum: [] };
  for (let d = "2026-07-01"; d <= "2026-10-17"; d = addDays(d, 1)) { archive.time.push(d); archive.precipitation_sum.push(1); }
  const recent = hourly("2026-09-23T00:00", 33 * 24, 0.1);
  const total = seasonToDate({ archive, hourly: recent, start: "2026-07-01", now: "2026-10-24T10:00" });
  // 109 archive days x 1 mm, then 18 Oct 00:00 to 24 Oct 10:00 = 6 x 24 + 11 hours x 0.1 mm.
  assert.equal(total, Math.round((109 + (6 * 24 + 11) * 0.1) * 10) / 10);
});

test("seasonToDate for a season younger than the archive lag uses the recent hours only", () => {
  const recent = hourly("2026-09-23T00:00", 33 * 24, 1);
  const total = seasonToDate({ archive: null, hourly: recent, start: "2026-10-22", now: "2026-10-24T05:00" });
  assert.equal(total, 2 * 24 + 6);
});

test("seasonAverage averages the normal period to the same day, and the whole season", () => {
  const normals = { time: [], precipitation_sum: [] };
  for (let d = `${NORMAL_FROM}-01-01`; d <= `${NORMAL_TO + 1}-06-30`; d = addDays(d, 1)) {
    normals.time.push(d);
    normals.precipitation_sum.push(1);
  }
  const avg = seasonAverage(normals, 7, "2026-07-01", "2026-07-10");
  assert.equal(avg.seasons, NORMAL_TO - NORMAL_FROM + 1);
  assert.equal(avg.toDate, 10);                    // 1 to 10 July, 1 mm a day
  assert.ok(avg.whole === 365 || avg.whole > 365);   // leap years make it 365.x
  assert.equal(daysBetween("2026-07-01", "2026-07-10"), 9);
});

test("seasonAverage leaves out a season with a missing day", () => {
  const normals = { time: [], precipitation_sum: [] };
  for (let d = `${NORMAL_FROM}-01-01`; d <= `${NORMAL_TO}-12-31`; d = addDays(d, 1)) {
    normals.time.push(d);
    normals.precipitation_sum.push(d === `${NORMAL_FROM}-03-03` ? null : 2);
  }
  const avg = seasonAverage(normals, 1, "2026-01-01", "2026-01-31");
  assert.equal(avg.seasons, NORMAL_TO - NORMAL_FROM);
  assert.equal(avg.toDate, 62);
});

test("frostNights lists only today on, at or below the threshold", () => {
  const daily = {
    time: ["2026-06-01", "2026-06-02", "2026-06-03", "2026-06-04"],
    temperature_2m_min: [-1, 2, 2.1, null],
  };
  assert.deepEqual(frostNights(daily, 2, "2026-06-02"), [{ date: "2026-06-02", min: 2 }]);
});
