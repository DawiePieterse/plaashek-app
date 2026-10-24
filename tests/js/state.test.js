import { test } from "node:test";
import assert from "node:assert/strict";
import { decodeState, defaultState, encodeState, roundCoord } from "../../public/modules/weer/js/state.js";

test("roundCoord keeps ~11m precision", () => {
  assert.equal(roundCoord(-33.92493333), -33.9249);
});

test("encodeState / decodeState round-trip fields, years, units and forecast", () => {
  const st = defaultState(2026);
  st.fields = [{ id: "wd.temperature_2m_mean", agg: null }, { id: "wh.wind_gusts_10m", agg: "max" }];
  st.years = [2025, 2026];
  st.units = { temperature: "fahrenheit", wind: "mph", precipitation: "inch" };
  st.showForecast = false;

  const decoded = decodeState(`#${encodeState(st)}`, 2026);
  assert.deepEqual(decoded.fields, st.fields);
  assert.deepEqual(decoded.years, st.years);
  assert.deepEqual(decoded.units, st.units);
  assert.equal(decoded.showForecast, false);
});

test("the farm is never in the hash: no a=, b=, m= or q=", () => {
  const enc = encodeState(defaultState(2026));
  assert.ok(!/(^|&)(a|b|m|q)=/.test(enc), enc);
  // A Weather Compare link still opens: its locations, mode and question are ignored.
  const st = decodeState("#a=-33.9,18.4,Cape%20Town&b=51.5,-0.1,London&m=diff&q=Which&f=wd.precipitation_sum&y=2024", 2026);
  assert.deepEqual(st.years, [2024]);
  assert.equal(st.locs, undefined);
  assert.equal(st.mode, undefined);
});

test("decodeState drops an unknown field id instead of throwing", () => {
  const st = decodeState("#f=nope.nothing,wd.temperature_2m_max&y=2024", 2026);
  assert.deepEqual(st.fields, [{ id: "wd.temperature_2m_max", agg: null }]);
});

test("decodeState falls back to default fields when nothing survives", () => {
  const st = decodeState("#f=nope.nothing&y=2024", 2026);
  assert.deepEqual(st.fields, defaultState(2026).fields);
});

test("decodeState returns null for an empty hash (caller falls back to storage/default)", () => {
  assert.equal(decodeState("", 2026), null);
  assert.equal(decodeState("#", 2026), null);
});

test("the default chart is rain, last year against this year", () => {
  const st = defaultState(2026);
  assert.deepEqual(st.fields, [{ id: "wd.precipitation_sum", agg: null }]);
  assert.deepEqual(st.years, [2025, 2026]);
});
