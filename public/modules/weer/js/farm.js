// The farm panel (PLAN.md section 2.3): the next 16 days, the rain record and
// frost nights for the farm's own position. Plaashek's addition to Weather
// Compare. The arithmetic is pure and unit-tested (tests/js/farm.test.js); the
// two loaders at the bottom fetch through the farm's proxy.
//
// All times are farm-local ("2026-10-24T13:00", from timezone=auto), so string
// comparison is time comparison and the first ten characters are the day.

import { addDays } from "./dates.js";
import { ARCHIVE_LAG_DAYS, ENDPOINTS, fetchWithTimeout } from "./openmeteo.js";

// The long-term average is over the WMO's current climate normal period.
export const NORMAL_FROM = 1991;
export const NORMAL_TO = 2020;
export const FORECAST_DAYS = 16;
export const PAST_DAYS = 31;

export const DAILY = [
  "weather_code", "temperature_2m_max", "temperature_2m_min", "precipitation_sum",
  "precipitation_probability_max", "wind_speed_10m_max", "wind_gusts_10m_max", "et0_fao_evapotranspiration",
];
export const HOURLY = [
  "temperature_2m", "relative_humidity_2m", "precipitation", "precipitation_probability", "wind_speed_10m", "weather_code",
];

const pad = (n) => String(n).padStart(2, "0");

// "Now" as a farm-local hour string, comparable with Open-Meteo's times.
export function localHour(date = new Date()) {
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:00`;
}

// The first day of the season `today` falls in: the most recent 1st of
// `month` on or before today.
export function seasonStart(today, month) {
  const y = Number(today.slice(0, 4));
  const candidate = `${y}-${pad(month)}-01`;
  return candidate <= today ? candidate : `${y - 1}-${pad(month)}-01`;
}

export function daysBetween(a, b) {
  return Math.round((Date.parse(`${b}T00:00:00Z`) - Date.parse(`${a}T00:00:00Z`)) / 86400000);
}

// Sum of the values whose time t satisfies from < t <= to. Missing values
// count as nothing; null comes back only when there was nothing at all.
export function sumBetween(times, values, from, to) {
  let total = 0;
  let seen = false;
  for (let i = 0; i < times.length; i++) {
    if (times[i] > from && times[i] <= to && values[i] != null) {
      total += values[i];
      seen = true;
    }
  }
  return seen ? Math.round(total * 10) / 10 : null;
}

// Rain over the last 24 hours, 7 days and 30 days, from hourly totals.
export function rainRecord(hourly, now) {
  const back = (days) => `${addDays(now.slice(0, 10), -days)}${now.slice(10)}`;
  const t = hourly.time;
  const p = hourly.precipitation;
  return {
    h24: sumBetween(t, p, back(1), now),
    d7: sumBetween(t, p, back(7), now),
    d30: sumBetween(t, p, back(30), now),
  };
}

// Season to date: the archive's daily totals up to where they stop, then the
// forecast API's hourly totals for the last days the archive hasn't reached.
export function seasonToDate({ archive, hourly, start, now }) {
  const today = now.slice(0, 10);
  const archiveEnd = addDays(today, -ARCHIVE_LAG_DAYS - 1);
  const fromArchive = archive ? sumBetween(archive.time, archive.precipitation_sum, addDays(start, -1), archiveEnd) : null;
  const recentFrom = start > archiveEnd ? `${addDays(start, -1)}T23:00` : `${archiveEnd}T23:00`;
  const fromRecent = sumBetween(hourly.time, hourly.precipitation, recentFrom, now);
  if (fromArchive == null && fromRecent == null) return null;
  return Math.round(((fromArchive || 0) + (fromRecent || 0)) * 10) / 10;
}

// The normal-period average of rain from the season's start to the same point
// in the season, and for the whole season. `normals` is the archive's daily
// precipitation_sum for NORMAL_FROM-01-01 .. NORMAL_TO-12-31 (plus the start of
// the following season when it straddles New Year). A season counts only when
// every day of it has a value.
export function seasonAverage(normals, month, start, today) {
  const byDay = new Map();
  normals.time.forEach((d, i) => byDay.set(d, normals.precipitation_sum[i]));
  const elapsed = daysBetween(start, today);
  const toDate = [];
  const whole = [];
  for (let y = NORMAL_FROM; y <= NORMAL_TO; y++) {
    const s = `${y}-${pad(month)}-01`;
    const length = daysBetween(s, `${y + 1}-${pad(month)}-01`);
    let sum = 0;
    let complete = true;
    for (let d = 0; d < length; d++) {
      const v = byDay.get(addDays(s, d));
      if (v == null) { complete = false; break; }
      sum += v;
      if (d === elapsed) toDate.push(sum);
    }
    if (complete) whole.push(sum);
  }
  const mean = (xs) => (xs.length ? Math.round((xs.reduce((a, b) => a + b, 0) / xs.length) * 10) / 10 : null);
  return { toDate: mean(toDate), whole: mean(whole), seasons: whole.length };
}

// Days from today on whose minimum is at or below the frost threshold.
export function frostNights(daily, threshold, today) {
  return daily.time
    .map((date, i) => ({ date, min: daily.temperature_2m_min[i] }))
    .filter((d) => d.date >= today && d.min != null && d.min <= threshold);
}

// ---------------------------------------------------------------- loading

async function getJson(url, fetchImpl) {
  const res = await fetchWithTimeout(fetchImpl, url, 60000);
  const body = await res.json().catch(() => null);
  if (!res.ok || !body || body.error) {
    const err = new Error((body && body.reason) || `HTTP ${res.status}`);
    err.status = res.status;
    throw err;
  }
  return body;
}

// The forecast and the last month, daily and hourly, in one request.
export function loadForecast(units, fetchImpl = fetch) {
  const p = new URLSearchParams({
    timezone: "auto", forecast_days: String(FORECAST_DAYS), past_days: String(PAST_DAYS),
    daily: DAILY.join(","), hourly: HOURLY.join(","),
  });
  if (units.temperature !== "celsius") p.set("temperature_unit", units.temperature);
  if (units.wind !== "kmh") p.set("wind_speed_unit", units.wind);
  if (units.precipitation !== "mm") p.set("precipitation_unit", units.precipitation);
  return getJson(`${ENDPOINTS.fc.url}?${p}`, fetchImpl);
}

// Archive daily rain: this season so far, and the normal period. The normal
// period never changes, so the farm's proxy fetches it from Open-Meteo once.
export async function loadRainHistory({ start, today, month, units }, fetchImpl = fetch) {
  const daily = (from, to) => {
    const p = new URLSearchParams({ timezone: "auto", start_date: from, end_date: to, daily: "precipitation_sum" });
    if (units.precipitation !== "mm") p.set("precipitation_unit", units.precipitation);
    return getJson(`${ENDPOINTS.archive.url}?${p}`, fetchImpl);
  };
  const archiveEnd = addDays(today, -ARCHIVE_LAG_DAYS - 1);
  // The last normal-period season ends the day before the season start month
  // comes round again: in NORMAL_TO+1 unless seasons start in January.
  const lastDay = new Date(Date.UTC(NORMAL_TO + 1, month - 1, 0));
  const normalEnd = month === 1 ? `${NORMAL_TO}-12-31`
    : `${lastDay.getUTCFullYear()}-${pad(lastDay.getUTCMonth() + 1)}-${pad(lastDay.getUTCDate())}`;
  const [season, normals] = await Promise.all([
    start <= archiveEnd ? daily(start, archiveEnd).then((b) => b.daily) : Promise.resolve(null),
    daily(`${NORMAL_FROM}-01-01`, normalEnd).then((b) => b.daily),
  ]);
  return { season, normals };
}
