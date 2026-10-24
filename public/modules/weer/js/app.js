// Plaashek Weather: Weather Compare's year-against-year chart for the farm's
// own position, with a farm panel on top (the next 16 days, the rain record
// and frost). PLAN.md section 2.3. Startup, state, and the wiring between the
// farm panel, the chart card and the dialogs.
//
// What Weather Compare had and this page leaves out, by the owner's call: the
// map and place search, a second location to compare with, and the AI "Ask".
// The farm's position, name and settings come from Farm settings, in
// window.PLAASHEK, which the server writes into the page.

import { addDays, refX, todayStr, xLabel, yearsText } from "./dates.js";
import { AGGS, CLIMATE_MODELS, COVERAGE, FARM_FIELDS, FIELDS, SOURCE_LABEL, defaultAgg, fieldById, firstYear,
         isUnavailable, lastYear, unitLabel } from "./fields.js";
import { MAX_FIELDS, loadState, saveState } from "./state.js";
import { WEATHER_HORIZON_DAYS, fetchCurrent, RateLimitError } from "./openmeteo.js";
import { loadLocation } from "./data.js";
import { cacheClear, cacheCount } from "./cache.js";
import { buildSeries, summaryRows } from "./series.js";
import { dualAxisLineChart, escapeHtml, exportPDF, legend } from "./chart.js";
import { bindOffline, describeWeather, isNetworkError, setOffline, toast } from "./ui.js";
import { buildSummary, quickSummary } from "./insights.js";
import { FORECAST_DAYS, frostNights, loadForecast, loadRainHistory, localHour, rainRecord, seasonAverage,
         seasonStart, seasonToDate } from "./farm.js";

export const VERSION = "1.0.0";

const PAGE = window.PLAASHEK || { farm: {}, t: {}, locale: "en" };
const FARM = PAGE.farm;
const LOCALE = PAGE.locale === "af" ? "af-ZA" : "en-ZA";
const FORECAST_KEY = "plaashek_weer_forecast_v1";
const HISTORY_KEY = "plaashek_weer_rain_v1";

const $ = (id) => document.getElementById(id);
const today = (() => {
  const str = todayStr();
  return { str, year: Number(str.slice(0, 4)), x: refX(str) };
})();

// Translated text from the server (app/Modules/Weer/lang); the key itself if
// a string is missing, so nothing is ever blank. {name} placeholders filled.
function t(key, vars = {}) {
  const s = PAGE.t[key] ?? key;
  return s.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? vars[k] : m));
}

let state = loadState(today.year);
let refreshSeq = 0;
let lastDrawn = null;
let showAllYears = false;
let fieldSlot = 0;
let sourceFilter = "farm";

// ---------------------------------------------------------------- helpers

function specs() {
  return state.fields.map((f) => {
    const field = fieldById(f.id);
    return field && { field, agg: f.agg, key: `${field.id}|${f.agg || "-"}` };
  }).filter(Boolean);
}

// The one location: the farm. Empty until Farm settings has a position.
function locList() {
  return FARM.lat == null || FARM.lon == null ? [] : [{ id: "A", lat: FARM.lat, lon: FARM.lon, name: FARM.name }];
}

function forecastReachesNextYear() {
  return addDays(today.str, WEATHER_HORIZON_DAYS).slice(0, 4) !== String(today.year);
}

// The years the chosen fields can have data for, which is what the year
// picker offers: from the earliest any of them reaches back to, to this year
// - or 2050 once a climate projection is on the chart.
function yearRange() {
  const ss = specs();
  const min = Math.min(...ss.map((s) => firstYear(s.field)));
  let max = Math.max(...ss.map((s) => lastYear(s.field, today.year)));
  if (state.showForecast && forecastReachesNextYear() && ss.some((s) => s.field.source !== "climate")) {
    max = Math.max(max, today.year + 1);
  }
  return { min, max };
}

function chartYears() {
  const { min, max } = yearRange();
  return state.years.filter((y) => y >= min && y <= max);
}

function fmt(v, decimals) {
  return v == null ? "–" : v.toLocaleString(LOCALE, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
}

function dayName(date, opts = { weekday: "short", day: "numeric" }) {
  return new Date(`${date}T12:00:00`).toLocaleDateString(LOCALE, opts);
}

function update({ reload = true } = {}) {
  saveState(state);
  renderSlots();
  renderOptions();
  renderYears();
  if (reload) refresh();
  else draw();
}

// ---------------------------------------------------------------- the farm panel

// Read from and written to localStorage, so the panel still shows the last
// forecast with no signal - said with its age, never passed off as current.
function remember(key, value) {
  try { localStorage.setItem(key, JSON.stringify({ at: Date.now(), value })); } catch { /* storage full or blocked */ }
}
function recall(key) {
  try { return JSON.parse(localStorage.getItem(key)); } catch { return null; }
}

async function renderFarm() {
  if (!locList().length) {
    $("farmBody").innerHTML = `<div class="text-sm text-slate-500">${escapeHtml(t("no_position"))}
      ${PAGE.settingsUrl ? `<a class="underline" href="${escapeHtml(PAGE.settingsUrl)}">${escapeHtml(t("farm_settings"))}</a>` : ""}</div>`;
    return;
  }
  const units = state.units;
  let forecast = null;
  let stale = null;
  try {
    forecast = await loadForecast(units);
    remember(FORECAST_KEY, { units, forecast });
    setOffline(false);
  } catch (err) {
    const saved = recall(FORECAST_KEY);
    if (saved && JSON.stringify(saved.value.units) === JSON.stringify(units)) {
      forecast = saved.value.forecast;
      stale = saved.at;
    }
    if (isNetworkError(err)) setOffline(true);
    if (!forecast) {
      $("farmBody").innerHTML = `<div class="text-sm text-red-700">${escapeHtml(t("forecast_failed"))} ${escapeHtml(err.message || "")}</div>`;
      return;
    }
  }

  const now = localHour();
  const start = seasonStart(today.str, FARM.seasonMonth || 7);
  const rain = rainRecord(forecast.hourly, now);
  const frost = frostNights(forecast.daily, FARM.frostThreshold ?? 2, today.str);
  const pUnit = forecast.daily_units?.precipitation_sum || "mm";
  const tUnit = forecast.daily_units?.temperature_2m_max || "°C";
  const wUnit = forecast.daily_units?.wind_speed_10m_max || "km/h";

  $("farmBody").innerHTML = `
    ${stale ? `<div class="text-xs text-amber-700 mb-2">${escapeHtml(t("as_of", { time: new Date(stale).toLocaleString(LOCALE) }))}</div>` : ""}
    ${frostHtml(frost, tUnit)}
    <div class="font-semibold text-sm mb-1">${escapeHtml(t("next_days", { n: FORECAST_DAYS }))}</div>
    <div class="day-strip">${daysHtml(forecast.daily, frost, { pUnit, tUnit, wUnit })}</div>
    <div class="font-semibold text-sm mt-3 mb-1">${escapeHtml(t("next_hours"))}</div>
    <div class="hour-strip">${hoursHtml(forecast.hourly, now)}</div>
    <div class="font-semibold text-sm mt-3 mb-1">${escapeHtml(t("rain_record"))}</div>
    <div id="rainRecord" class="rain-grid">${rainHtml(rain, null, pUnit, start)}</div>`;

  // The season's rain needs the archive (and its 30-year average); it fills
  // in when that arrives, or from the last copy when offline.
  try {
    const history = await loadRainHistory({ start, today: today.str, month: FARM.seasonMonth || 7, units });
    const season = {
      toDate: seasonToDate({ archive: history.season, hourly: forecast.hourly, start, now }),
      average: seasonAverage(history.normals, FARM.seasonMonth || 7, start, today.str),
    };
    remember(HISTORY_KEY, { units, start, season });
    $("rainRecord").innerHTML = rainHtml(rain, season, pUnit, start);
  } catch {
    const saved = recall(HISTORY_KEY);
    if (saved && saved.value.start === start && JSON.stringify(saved.value.units) === JSON.stringify(units)) {
      $("rainRecord").innerHTML = rainHtml(rain, saved.value.season, pUnit, start);
    }
  }
}

function frostHtml(frost, tUnit) {
  if (!frost.length) {
    return `<div class="frost-none mb-3"><i class="fa-solid fa-temperature-arrow-up"></i> ${escapeHtml(t("no_frost", { t: fmt(FARM.frostThreshold ?? 2, 1) + " " + tUnit }))}</div>`;
  }
  const list = frost.map((d) => `${dayName(d.date)} (${fmt(d.min, 1)} ${tUnit})`).join(", ");
  return `<div class="frost-warning mb-3"><i class="fa-solid fa-snowflake"></i> <b>${escapeHtml(t("frost_risk"))}</b> ${escapeHtml(list)}</div>`;
}

function daysHtml(daily, frost, { pUnit, tUnit, wUnit }) {
  const frostDays = new Set(frost.map((d) => d.date));
  return daily.time.map((date, i) => {
    if (date < today.str) return "";
    const w = describeWeather(daily.weather_code[i], 1);
    const prob = daily.precipitation_probability_max?.[i];
    return `<div class="day-card ${frostDays.has(date) ? "frost" : ""} ${date === today.str ? "today" : ""}">
      <div class="text-xs font-semibold">${date === today.str ? escapeHtml(t("today")) : escapeHtml(dayName(date))}</div>
      <i class="fa-solid ${w.icon} text-lg my-1" title="${escapeHtml(t(w.text))}"></i>
      <div class="text-sm"><b>${fmt(daily.temperature_2m_max[i], 0)}°</b> <span class="text-slate-500">${fmt(daily.temperature_2m_min[i], 0)}°</span></div>
      <div class="text-xs text-sky-700">${fmt(daily.precipitation_sum[i], 1)} ${escapeHtml(pUnit)}${prob != null ? ` · ${prob}%` : ""}</div>
      <div class="text-xs text-slate-500">${fmt(daily.wind_speed_10m_max[i], 0)} ${escapeHtml(wUnit)}</div>
    </div>`;
  }).join("");
}

// Every third hour of the next 48, so the strip fits a phone without a long scroll.
function hoursHtml(hourly, now) {
  const out = [];
  for (let i = 0; i < hourly.time.length && out.length < 16; i++) {
    const time = hourly.time[i];
    if (time < now || Number(time.slice(11, 13)) % 3 !== 0) continue;
    const hour = Number(time.slice(11, 13));
    const w = describeWeather(hourly.weather_code[i], hour >= 6 && hour < 19 ? 1 : 0);
    out.push(`<div class="hour-card">
      <div class="text-[11px] text-slate-500">${time.slice(0, 10) === today.str ? "" : escapeHtml(dayName(time.slice(0, 10), { weekday: "short" }))} ${time.slice(11, 16)}</div>
      <i class="fa-solid ${w.icon}" title="${escapeHtml(t(w.text))}"></i>
      <div class="text-sm font-semibold">${fmt(hourly.temperature_2m[i], 0)}°</div>
      <div class="text-[11px] text-sky-700">${fmt(hourly.precipitation[i], 1)} · ${hourly.precipitation_probability?.[i] ?? "–"}%</div>
      <div class="text-[11px] text-slate-500">${hourly.relative_humidity_2m[i] ?? "–"}% · ${fmt(hourly.wind_speed_10m[i], 0)}</div>
    </div>`);
  }
  return out.join("");
}

function rainHtml(rain, season, pUnit, start) {
  const cell = (label, value, note = "") => `<div class="rain-cell"><div class="text-xs text-slate-500">${escapeHtml(label)}</div>
    <div class="text-lg font-semibold">${fmt(value, 1)} <span class="text-xs font-normal">${escapeHtml(pUnit)}</span></div>
    ${note ? `<div class="text-xs text-slate-500">${note}</div>` : ""}</div>`;
  let seasonNote = escapeHtml(t("loading"));
  if (season) {
    const avg = season.average;
    const pct = avg?.toDate ? Math.round((season.toDate / avg.toDate) * 100) : null;
    seasonNote = avg?.toDate != null
      ? escapeHtml(t("season_vs_average", { avg: fmt(avg.toDate, 0), unit: pUnit, pct: pct ?? "–", whole: fmt(avg.whole, 0) }))
      : "";
  }
  return cell(t("last_24h"), rain.h24) + cell(t("last_7d"), rain.d7) + cell(t("last_30d"), rain.d30)
    + cell(t("season_since", { date: dayName(start, { day: "numeric", month: "long" }) }), season?.toDate ?? null, seasonNote);
}

// ---------------------------------------------------------------- measurements

function sourceBadge(field) {
  return `<span class="badge">${escapeHtml(t(`source_${field.source}`))}</span>`;
}

function renderSlots() {
  const html = state.fields.map((f, i) => {
    const field = fieldById(f.id);
    if (!field) return "";
    const aggSel = field.res === "hourly" && field.aggs.length > 1
      ? `<select class="agg-select border border-slate-300 rounded p-1 text-xs" data-slot="${i}">
           ${field.aggs.map((a) => `<option value="${a}" ${a === f.agg ? "selected" : ""}>${escapeHtml(t(`agg_${a}`) || AGGS[a])}</option>`).join("")}
         </select>`
      : field.res === "hourly" ? `<span class="text-xs text-slate-500">${escapeHtml(t(`agg_${f.agg}`))}</span>`
        : `<span class="text-xs text-slate-500">${escapeHtml(t("daily_value"))}</span>`;
    return `
      <div class="slot">
        <span class="text-xs font-semibold text-slate-500 w-16">${escapeHtml(t(i === 0 ? "left_axis" : "right_axis"))}</span>
        <button class="field-btn text-sm font-medium text-left hover:underline" data-slot="${i}">
          ${escapeHtml(field.label)} <span class="text-slate-500 font-normal">${unitLabel(field, state.units) ? `(${escapeHtml(unitLabel(field, state.units))})` : ""}</span>
          <i class="fa-solid fa-chevron-down text-xs text-slate-400"></i></button>
        ${sourceBadge(field)}
        ${aggSel}
        ${state.fields.length > 1 ? `<button class="text-slate-400 hover:text-slate-600 ml-auto" data-remove-slot="${i}" title="${escapeHtml(t("remove"))}"><i class="fa-solid fa-xmark"></i></button>` : ""}
      </div>`;
  }).join("");
  const add = state.fields.length < MAX_FIELDS
    ? `<button class="btn !text-xs" id="addFieldBtn"><i class="fa-solid fa-plus"></i> ${escapeHtml(t("add_second"))}</button>` : "";
  $("fieldSlots").innerHTML = html + add;
}

function bindSlots() {
  $("fieldSlots").addEventListener("click", (e) => {
    const btn = e.target.closest(".field-btn");
    if (btn) return openFieldDialog(Number(btn.dataset.slot));
    if (e.target.closest("#addFieldBtn")) return openFieldDialog(state.fields.length);
    const rm = e.target.closest("[data-remove-slot]");
    if (rm) {
      state.fields.splice(Number(rm.dataset.removeSlot), 1);
      update();
    }
  });
  $("fieldSlots").addEventListener("change", (e) => {
    if (!e.target.classList.contains("agg-select")) return;
    state.fields[Number(e.target.dataset.slot)].agg = e.target.value;
    update();
  });
}

function openFieldDialog(slot) {
  fieldSlot = slot;
  $("fieldDialogTitle").textContent = t(slot === 0 ? "measurement_left" : "measurement_right");
  $("fieldSearch").value = "";
  sourceFilter = "farm";
  renderSourceChips();
  renderFieldList();
  $("fieldDialog").showModal();
}

// "Farm" (the shortlist) first, then everything, then each source.
function renderSourceChips() {
  const chips = [["farm", t("source_farm")], ["all", t("source_all")],
    ...Object.keys(SOURCE_LABEL).map((k) => [k, t(`source_${k}`)])];
  $("sourceChips").innerHTML = `<div class="seg flex-wrap">${chips.map(([k, label]) =>
    `<button data-source="${k}" class="${sourceFilter === k ? "active" : ""}">${escapeHtml(label)}</button>`).join("")}</div>`;
}

function fieldEndpoints(field) {
  return field.source === "weather" ? field.apis : [field.source];
}

function coverageText(field) {
  if (field.source === "climate") return `${COVERAGE.climate}–${COVERAGE.climateEnd}`;
  const from = firstYear(field);
  return field.source === "weather" && !field.apis.includes("fc") ? t("coverage_archive", { from }) : t("coverage_forecast", { from });
}

function renderFieldList() {
  const terms = $("fieldSearch").value.toLowerCase().split(/\s+/).filter(Boolean);
  // Typing searches the whole catalog, whatever chip is on.
  const pool = sourceFilter === "farm" && !terms.length
    ? FARM_FIELDS.map(fieldById).filter(Boolean)
    : FIELDS.filter((f) => sourceFilter === "all" || sourceFilter === "farm" || f.source === sourceFilter);
  const matches = pool.filter((f) => terms.every((term) =>
    `${f.label} ${f.key} ${f.group} ${f.source}`.toLowerCase().replace(/_/g, " ").includes(term.replace(/_/g, " "))));
  if (!matches.length) { $("fieldList").innerHTML = `<div class="text-sm text-slate-500 p-3">${escapeHtml(t("no_fields"))}</div>`; return; }
  const groups = new Map();
  for (const f of matches) {
    const g = `${t(`source_${f.source}`)} · ${f.group}${f.res === "daily" ? ` (${t("daily_values")})` : ""}`;
    if (!groups.has(g)) groups.set(g, []);
    groups.get(g).push(f);
  }
  $("fieldList").innerHTML = [...groups].map(([g, fs]) => `
    <div class="mt-2">
      <div class="text-xs font-semibold text-slate-500 uppercase tracking-wide px-2 py-1 sticky top-0 bg-white">${escapeHtml(g)}</div>
      ${fs.map((f) => {
        const off = fieldEndpoints(f).every((ep) => isUnavailable(ep, f.key));
        return `<button class="field-row ${off ? "unavailable" : ""}" data-id="${f.id}" ${off ? `title="${escapeHtml(t("field_unavailable"))}"` : ""}>
          <span><span class="text-sm">${escapeHtml(f.label)}</span>
            <span class="block text-[11px] text-slate-400 font-mono">${f.key}</span></span>
          <span class="flex flex-col items-end gap-0.5">
            <span class="badge">${escapeHtml(unitLabel(f, state.units) || "–")}</span>
            <span class="text-[10px] text-slate-400">${escapeHtml(coverageText(f))}</span></span>
        </button>`;
      }).join("")}
    </div>`).join("");
}

function bindFieldDialog() {
  $("fieldSearch").addEventListener("input", renderFieldList);
  $("sourceChips").addEventListener("click", (e) => {
    const b = e.target.closest("[data-source]");
    if (!b) return;
    sourceFilter = b.dataset.source;
    renderSourceChips();
    renderFieldList();
  });
  $("fieldList").addEventListener("click", (e) => {
    const row = e.target.closest("[data-id]");
    if (!row) return;
    const field = fieldById(row.dataset.id);
    state.fields[fieldSlot] = { id: field.id, agg: defaultAgg(field) };
    state.fields = state.fields.slice(0, MAX_FIELDS);
    // A field whose years don't overlap the current selection would open onto
    // an empty chart; fall back to the most recent year it has.
    if (!chartYears().length) state.years = [Math.min(yearRange().max, today.year)];
    $("fieldDialog").close();
    update();
  });
  document.querySelectorAll("dialog [data-close]").forEach((b) =>
    b.addEventListener("click", () => b.closest("dialog").close()));
}

// ---------------------------------------------------------------- options & years

function renderOptions() {
  $("forecastCb").checked = state.showForecast;
  const climate = specs().some((s) => s.field.source === "climate");
  $("climateModelWrap").classList.toggle("hidden", !climate);
  if (!$("climateModel").options.length) {
    $("climateModel").innerHTML = CLIMATE_MODELS.map(([k, label]) => `<option value="${k}">${escapeHtml(label)}</option>`).join("");
  }
  $("climateModel").value = state.climateModel;
}

function renderYears() {
  const { min, max } = yearRange();
  const selected = new Set(state.years);
  const decades = [];
  for (let d = Math.floor(min / 10) * 10; d <= max; d += 10) decades.push(d);
  // Collapsed, the picker shows the last two decades plus any decade holding a
  // ticked year; "Show all years" opens the whole 1940-2050 range.
  const recent = (d) => d + 9 >= today.year - 19 && d <= today.year;
  const shown = decades.filter((d) => showAllYears || recent(d) || state.years.some((y) => y >= d && y < d + 10));
  $("yearPicker").innerHTML = shown.map((d) => {
    const years = [];
    for (let y = Math.max(d, min); y <= Math.min(d + 9, max); y++) years.push(y);
    return `<div class="flex items-center gap-1 flex-wrap">
      <span class="text-[11px] text-slate-400 w-10">${d}s</span>
      ${years.map((y) => `<button class="year-chip ${selected.has(y) ? "active" : ""} ${y === today.year ? "current" : ""}" data-year="${y}">${y}</button>`).join("")}
    </div>`;
  }).join("");
  $("yearsToggle").textContent = showAllYears ? t("show_recent_years") : t("show_all_years", { min, max });
  $("yearsToggle").classList.toggle("hidden", shown.length === decades.length && !showAllYears);
}

function bindOptions() {
  $("forecastCb").addEventListener("change", (e) => { state.showForecast = e.target.checked; update(); });
  $("climateModel").addEventListener("change", (e) => { state.climateModel = e.target.value; update(); });
  $("yearPicker").addEventListener("click", (e) => {
    const chip = e.target.closest("[data-year]");
    if (!chip) return;
    const y = Number(chip.dataset.year);
    state.years = state.years.includes(y) ? state.years.filter((v) => v !== y) : [...state.years, y].sort((a, b) => a - b);
    update();
  });
  document.querySelectorAll("[data-years]").forEach((b) => b.addEventListener("click", () => {
    const y = today.year;
    state.years = { this: [y], last: [y - 1], last5: [y - 4, y - 3, y - 2, y - 1, y], clear: [] }[b.dataset.years];
    update();
  }));
  $("yearsToggle").addEventListener("click", () => { showAllYears = !showAllYears; renderYears(); });
}

// ---------------------------------------------------------------- loading & drawing

function setStatus(html) { $("loadStatus").innerHTML = html; }

function showEmpty(html) {
  lastDrawn = null;
  $("chart").innerHTML = `<div class="text-sm text-slate-400 p-8 text-center">${html}</div>`;
  $("legend").innerHTML = ""; $("summary").innerHTML = ""; $("chartSubtitle").textContent = "";
  $("quickRead").innerHTML = "";
  setStatus("");
}

async function refresh() {
  const seq = ++refreshSeq;
  const ss = specs();
  const locs = locList();
  const years = chartYears();
  $("warnings").innerHTML = "";

  if (!locs.length) return showEmpty(escapeHtml(t("no_position")));
  if (!years.length) return showEmpty(escapeHtml(t("pick_a_year")));

  let progress = [0, 0];
  setStatus(`<i class="fa-solid fa-spinner fa-spin"></i> ${escapeHtml(t("loading"))}`);

  const result = await loadLocation({
    loc: locs[0], specs: ss, years, units: state.units, model: state.climateModel, today: today.str,
    onProgress: (d, total) => {
      if (seq !== refreshSeq) return;
      progress = [d, total];
      if (total) setStatus(`<i class="fa-solid fa-spinner fa-spin"></i> ${escapeHtml(t("downloading", { done: d, total }))}`);
    },
  });
  if (seq !== refreshSeq) return;   // superseded by a newer change

  const data = { A: result.points };
  lastDrawn = { specs: ss, years, locs, data };
  draw();

  // What could not be shown, and why - said rather than left as a blank line.
  const notes = [];
  const errors = result.errors;
  if (errors.some((e) => isNetworkError(e))) {
    setOffline(true);
    notes.push(["amber", t("offline_chart")]);
  } else if (progress[1] > 0) {
    setOffline(false);
  }
  if (errors.some((e) => e instanceof RateLimitError)) notes.push(["amber", t("rate_limited")]);
  [...new Set(errors.filter((e) => !isNetworkError(e) && !(e instanceof RateLimitError)).map((e) => e.message))]
    .forEach((m) => notes.push(["red", m]));
  result.dropped.forEach((ep, id) => notes.push(["amber", t("field_not_in_api", { field: fieldById(id).label })]));
  for (const spec of ss) {
    const empty = years.filter((y) => !(data.A.get(spec.key)?.get(y) || []).length);
    if (empty.length && !errors.length) notes.push(["slate", t("no_data_in", { field: spec.field.label, years: yearsText(empty) })]);
  }
  const colors = { amber: "text-amber-700", red: "text-red-700", slate: "text-slate-500" };
  $("warnings").innerHTML = notes.map(([c, m]) => `<div class="text-xs ${colors[c]}">${escapeHtml(m)}</div>`).join("");
  setStatus(escapeHtml(t("updated", { time: new Date().toLocaleTimeString(LOCALE, { hour: "2-digit", minute: "2-digit" }) })));
}

function draw() {
  if (!lastDrawn) return;
  const { specs: ss, years, locs, data } = lastDrawn;
  const { series, axisLabels, refLines, legendItems } = buildSeries({
    specs: ss, years, locs, data, mode: "overlay", today, showForecast: state.showForecast, units: state.units,
  });
  const pointEvery = Math.max(1, Math.ceil(Math.max(1, ...series.map((s) => s.points.length)) / 120));
  dualAxisLineChart($("chart"), { series, xLabel, xMin: 1, xMax: 366, pointEvery, axisLabels, refLines });
  legend($("legend"), legendItems);
  $("chartSubtitle").textContent = `${FARM.name} · ${yearsText(years)}`;
  renderSummary(summaryRows({ specs: ss, years, locs, data, today, units: state.units }));
  renderQuickRead();
}

function renderSummary(rows) {
  if (!rows.length) { $("summary").innerHTML = `<div class="text-slate-400 text-sm">${escapeHtml(t("nothing_to_summarise"))}</div>`; return; }
  const anyTotal = rows.some((r) => r.total);
  const cols = [t("mean"), t("min"), t("max"), ...(anyTotal ? [t("total")] : [])];
  const head = `<tr><th>${escapeHtml(t("measurement"))}</th><th>${escapeHtml(t("year"))}</th>${cols.map((c) => `<th>${escapeHtml(c)}</th>`).join("")}</tr>`;
  const body = rows.map((r) => {
    const u = r.unit ? ` ${escapeHtml(r.unit)}` : "";
    const s = r.stats[0];
    const cells = [s?.mean, s?.min, s?.max, ...(anyTotal ? [s?.total] : [])].map((v) => `<td>${fmt(v, r.decimals)}</td>`).join("");
    return `<tr><td>${escapeHtml(r.label)}<span class="text-slate-400">${u}</span></td><td>${r.year}${r.partial ? "*" : ""}</td>${cells}</tr>`;
  }).join("");
  const partial = rows.some((r) => r.partial)
    ? `<div class="text-xs text-slate-400 mt-1">* ${escapeHtml(t("so_far", { year: today.year }))}</div>` : "";
  $("summary").innerHTML = `<table class="summary w-full"><thead>${head}</thead><tbody>${body}</tbody></table>${partial}`;
}

// The quick read: what the summary says in plain sentences, no model involved.
// insights.js writes them in English; an Afrikaans farm sees them in English
// until they are translated.
function renderQuickRead() {
  const lines = lastDrawn ? quickSummary(buildSummary({ ...lastDrawn, mode: "overlay", today, units: state.units })) : [];
  $("quickRead").innerHTML = lines.length
    ? `<div class="ask-quick text-sm"><div class="ask-quick-title"><i class="fa-solid fa-bolt"></i> ${escapeHtml(t("quick_read"))}</div>
        ${lines.map((l) => `<div>${escapeHtml(l)}</div>`).join("")}</div>`
    : "";
}

// ---------------------------------------------------------------- header, settings, PDF

async function renderHeaderWeather() {
  const loc = locList()[0];
  if (!loc) return;
  try {
    const c = await fetchCurrent({ lat: loc.lat, lon: loc.lon, units: state.units });
    const w = describeWeather(c.weather_code, c.is_day);
    $("headerWeather").innerHTML = `<div class="flex items-center justify-end gap-1.5 whitespace-nowrap">
      <i class="fa-solid ${w.icon}"></i> ${Math.round(c.temperature_2m)}${escapeHtml(c.units?.temperature_2m || "°")}
      <span class="hidden sm:inline">· ${escapeHtml(t(w.text))} · ${c.relative_humidity_2m}%</span></div>`;
  } catch {
    /* the header strip is a nicety; the farm panel says what failed */
  }
}

async function openSettings() {
  $("unitTemp").value = state.units.temperature;
  $("unitWind").value = state.units.wind;
  $("unitPrecip").value = state.units.precipitation;
  $("cacheInfo").textContent = t("cache_count", { n: await cacheCount() });
  $("settingsDialog").showModal();
}

function bindSettings() {
  $("settingsBtn").addEventListener("click", openSettings);
  const onUnits = () => {
    state.units = { temperature: $("unitTemp").value, wind: $("unitWind").value, precipitation: $("unitPrecip").value };
    update();
    renderFarm();
    renderHeaderWeather();
  };
  ["unitTemp", "unitWind", "unitPrecip"].forEach((id) => $(id).addEventListener("change", onUnits));
  $("clearCacheBtn").addEventListener("click", async () => {
    await cacheClear();
    try { localStorage.removeItem(FORECAST_KEY); localStorage.removeItem(HISTORY_KEY); } catch { /* ignore */ }
    $("cacheInfo").textContent = t("cleared");
    refresh();
  });
  $("refreshBtn").addEventListener("click", () => { refresh(); renderFarm(); renderHeaderWeather(); });
  $("pdfBtn").addEventListener("click", async () => {
    if (!lastDrawn) return;
    const icon = $("pdfBtn").querySelector("i");
    icon.className = "fa-solid fa-spinner fa-spin";
    try {
      const title = lastDrawn.specs.map((s) => s.field.label).join(" & ");
      const subtitle = `${FARM.name} · ${yearsText(lastDrawn.years)}`;
      await exportPDF($("chartExport"), { title, subtitle, filename: `${`${title} ${subtitle}`.replace(/[^a-zA-Z0-9]+/g, "_")}.pdf` });
    } catch (err) {
      console.error("PDF export failed:", err);
      toast(t("pdf_failed"));
    } finally {
      icon.className = "fa-solid fa-file-pdf";
    }
  });
}

// ---------------------------------------------------------------- startup

function start() {
  $("headerDate").textContent = new Date().toLocaleDateString(LOCALE, { weekday: "long", year: "numeric", month: "long", day: "numeric" });
  $("appVersion").textContent = `v${VERSION}`;
  bindOffline();
  bindSlots();
  bindFieldDialog();
  bindOptions();
  bindSettings();

  update();
  renderFarm();
  renderHeaderWeather();
  setInterval(renderHeaderWeather, 10 * 60 * 1000);

  window.addEventListener("hashchange", () => {
    state = loadState(today.year);
    update();
  });

  if ("serviceWorker" in navigator && PAGE.serviceWorker) {
    navigator.serviceWorker.register(PAGE.serviceWorker, { scope: PAGE.scope }).catch(() => { /* offline shell is a nice-to-have */ });
  }
}

start();
