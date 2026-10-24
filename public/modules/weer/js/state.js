// What the screen is showing, and how that is saved: in the URL hash (so a
// chart can be bookmarked) and in localStorage (so the page reopens where it
// was left).
//
// Plaashek: there is one location, the farm, and it comes from Farm settings
// (window.PLAASHEK.farm), never from the hash. Weather Compare's second
// location, difference mode and AI question are gone (PLAN.md section 2.3).
//
// Hash format, every part optional:
//   #f=wd.temperature_2m_mean,wh.wind_gusts_10m:max&y=2024,2025&u=celsius,kmh,mm&cm=MRI_AGCM3_2_S&fc=0

import { UNIT_TOKENS, defaultAgg, fieldById } from "./fields.js";

export const MAX_FIELDS = 2;
const STORAGE_KEY = "plaashek_weer_state_v1";

export function defaultState(currentYear) {
  return {
    fields: [{ id: "wd.precipitation_sum", agg: null }],
    years: [currentYear - 1, currentYear],
    units: { temperature: "celsius", wind: "kmh", precipitation: "mm" },
    climateModel: "MRI_AGCM3_2_S",
    showForecast: true,
  };
}

// 4 decimals is ~11 m - Boord's COORD_TOLERANCE, and what Farm settings stores.
export function roundCoord(v) {
  return Math.round(v * 10000) / 10000;
}

export function encodeState(st) {
  const p = [];
  p.push(`f=${st.fields.map((f) => (f.agg ? `${f.id}:${f.agg}` : f.id)).join(",")}`);
  p.push(`y=${st.years.join(",")}`);
  const u = st.units;
  const d = defaultState(0).units;
  if (u.temperature !== d.temperature || u.wind !== d.wind || u.precipitation !== d.precipitation) {
    p.push(`u=${u.temperature},${u.wind},${u.precipitation}`);
  }
  if (st.fields.some((f) => fieldById(f.id)?.source === "climate")) p.push(`cm=${st.climateModel}`);
  if (!st.showForecast) p.push("fc=0");
  return p.join("&");
}

// Anything unrecognised is dropped rather than rejected: a link from an older
// version of the page should still open onto something sensible.
export function decodeState(hash, currentYear) {
  const st = defaultState(currentYear);
  const params = new URLSearchParams(hash.replace(/^#/, ""));
  if (!params.toString()) return null;
  if (params.has("f")) {
    const fields = params.get("f").split(",").map((s) => {
      const [id, agg] = s.split(":");
      const field = fieldById(id);
      if (!field) return null;
      return { id, agg: field.res === "hourly" ? (field.aggs.includes(agg) ? agg : defaultAgg(field)) : null };
    }).filter(Boolean).slice(0, MAX_FIELDS);
    if (fields.length) st.fields = fields;
  }
  if (params.has("y")) {
    const years = params.get("y").split(",").map(Number).filter((y) => Number.isInteger(y) && y >= 1900 && y <= 2100);
    if (years.length) st.years = [...new Set(years)].sort((a, b) => a - b);
  }
  if (params.has("u")) {
    const [t, w, p] = params.get("u").split(",");
    if (Object.hasOwn(UNIT_TOKENS.T, t)) st.units.temperature = t;
    if (Object.hasOwn(UNIT_TOKENS.W, w)) st.units.wind = w;
    if (Object.hasOwn(UNIT_TOKENS.P, p)) st.units.precipitation = p;
  }
  if (params.has("cm")) st.climateModel = params.get("cm");
  if (params.get("fc") === "0") st.showForecast = false;
  return st;
}

export function loadState(currentYear) {
  const fromHash = typeof location !== "undefined" && location.hash ? decodeState(location.hash, currentYear) : null;
  if (fromHash) return fromHash;
  try {
    const saved = localStorage.getItem(STORAGE_KEY);
    if (saved) return decodeState(saved, currentYear) || defaultState(currentYear);
  } catch { /* storage blocked */ }
  return defaultState(currentYear);
}

export function saveState(st) {
  const enc = encodeState(st);
  try { localStorage.setItem(STORAGE_KEY, enc); } catch { /* storage blocked */ }
  if (typeof history !== "undefined") history.replaceState(null, "", `#${enc}`);
}
