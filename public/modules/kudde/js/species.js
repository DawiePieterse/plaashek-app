// Which species this phone is working with (PLAASHEK phase 4b). A farm with
// cattle and sheep keeps two herd books, and tags are unique only within a
// species: sheep 001 and cow 001 may both exist. So the phone works in one
// species at a time, picked from a row of chips that only shows on a farm
// keeping more than one, and remembered per device: the person at the sheep
// kraal sets it once.
//
// Every write names the species (api.js adds it to the body, idb.js stamps it
// on an outbox entry when it is made, so a record captured among the sheep
// still lands among the sheep if the phone is switched before it syncs).
const KuddeSpecies = (() => {
  const KEY = "kudde_field_species";
  const list = (window.PLAASHEK && Array.isArray(window.PLAASHEK.species) && window.PLAASHEK.species.length)
    ? window.PLAASHEK.species
    : [{ code: "cattle", name: "Cattle", birthWord: "calving", youngUntilDays: 365, taggedAtWeaning: false,
         weighEveryDays: (window.PLAASHEK && window.PLAASHEK.weighEveryDays) || 182,
         classNames: { mother: "cow", female: "heifer", male: "bull", castrated: "steer",
                       young_female: "heifer calf", young_male: "bull calf", young_castrated: "steer calf" } }];
  const listeners = [];

  let code = list[0].code;
  try {
    const saved = localStorage.getItem(KEY);
    if (list.some((s) => s.code === saved)) code = saved;
  } catch (_) { /* private mode: the first species */ }

  function get(c) { return list.find((s) => s.code === c) || list[0]; }

  return {
    list,
    mixed: () => list.length > 1,
    current: () => code,
    get,
    info: () => get(code),
    // An animal from before species, or cached by an older page, is cattle:
    // what every animal was until then.
    of: (a) => (a && a.species) || "cattle",
    set(c) {
      if (c === code || !list.some((s) => s.code === c)) return;
      code = c;
      try { localStorage.setItem(KEY, c); } catch (_) { /* fine */ }
      listeners.forEach((fn) => fn(c));
    },
    onChange(fn) { listeners.push(fn); },
    // The chip row, in the farm's order; hidden on a farm with one species.
    render(el) {
      if (!el) return;
      el.classList.toggle("hidden", list.length < 2);
      el.innerHTML = list.map((s) => `<button type="button" data-species="${s.code}"
        class="seg-btn flex-1${s.code === code ? " active" : ""}">${String(s.name).replace(/[&<>"]/g, "")}</button>`).join("");
      el.querySelectorAll("[data-species]").forEach((b) => b.addEventListener("click", () => {
        KuddeSpecies.set(b.dataset.species);
        KuddeSpecies.render(el);
      }));
    },
  };
})();
