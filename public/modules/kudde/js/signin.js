// The daily card scan that opens the Kudde field app (Plaashek, owner's call
// 25 October 2026). A worker scans their card once when the day's work
// starts; the phone stays theirs until midnight, so a busy day at the crush
// is never interrupted by another scan.
//
// The card's QR holds only the worker number (Plaashek's worker register, as
// Boord's badges). Online, the number is exchanged for a token the server
// accepts until the farm's midnight. With no signal the phone signs the worker
// in from its cached list of the farm's active workers and fetches the token
// once there is signal again, so a kraal out of reach still starts the day.
//
// Kept in localStorage: { token, worker: {number, name, modules}, day }. "day"
// is the phone's local date of the scan, so the morning after always asks
// again even if the server's token somehow outlived it.
//
// Before any of that the phone is paired with the farm (PLAN.md section 5.1):
// the office shows a code on its Phones page, the phone takes it once (from
// the QR's #pair= or typed) and keeps the token it gets for good. A card scan
// works only on a paired phone, so a printed card alone opens nothing.
const KuddeSignIn = (() => {
  const KEY = "kudde_field_signin";
  const WORKERS_KEY = "kudde_field_workers";
  const t = (key) => (window.PLAASHEK && window.PLAASHEK.t && window.PLAASHEK.t[key]) || key;
  let state = read();
  let waiting = [];          // ready() callers, released by the next sign-in
  let wasSignedIn = !!current();

  function read() {
    try { return JSON.parse(localStorage.getItem(KEY) || "null"); } catch (_) { return null; }
  }

  function write(value) {
    state = value;
    try {
      if (value) localStorage.setItem(KEY, JSON.stringify(value));
      else localStorage.removeItem(KEY);
    } catch (_) { /* private mode: signed in for this page only */ }
  }

  // Today's sign-in, or null once the day has turned.
  function current() {
    return state && state.worker && state.day === Kudde.localDateStr() ? state : null;
  }

  function cachedWorkers() {
    try {
      const list = JSON.parse(localStorage.getItem(WORKERS_KEY) || "[]");
      return Array.isArray(list) ? list : [];
    } catch (_) { return []; }
  }

  // What a card's code means: the worker number, as printed under the photo.
  function numberFromCode(text) {
    return String(text || "").trim().replace(/^#/, "");
  }

  function setMessage(text, warn) {
    const el = document.getElementById("signinMessage");
    el.textContent = text || "";
    el.classList.toggle("text-amber-300", !!warn);
  }

  // The gate has two faces: pair the phone (no device token yet), or scan a
  // card (paired, nobody signed in today).
  function showGate(message) {
    renderHeader();
    const pairing = !Kudde.device();
    document.getElementById("signinTitle").textContent = t(pairing ? "pair_title" : "scan_title");
    document.getElementById("signinHint").textContent = t(pairing ? "pair_hint" : "scan_hint");
    document.getElementById("signinScanBtn").classList.toggle("hidden", pairing);
    document.getElementById("pairForm").classList.toggle("hidden", !pairing);
    document.getElementById("pairForm").classList.toggle("flex", pairing);
    document.getElementById("signinScreen").classList.remove("hidden");
    document.getElementById("signinScreen").classList.add("flex");
    setMessage(message || "", !!message);
  }

  // The code from the office's Phones page, scanned (#pair=) or typed.
  async function pair(code) {
    code = String(code || "").trim();
    if (!code) return;
    setMessage(t("pairing"));
    try {
      const res = await Kudde._fetchWithTimeout(`${FIELD_API}/pair`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ code }),
      });
      const body = await res.json().catch(() => ({}));
      if (!res.ok) { showGate(body.message || `${res.status}`); return; }
      Kudde.setDevice({ token: body.token, label: body.device && body.device.label });
      document.getElementById("pairCode").value = "";
      showGate("");
      setMessage(t("paired"));
    } catch (e) {
      showGate(Kudde.isNetworkError(e) ? t("pair_needs_signal") : Kudde.errorDetail(e));
    }
  }

  // A worker may use Kudde on a phone only if the office ticked it for them.
  // An older cached list has no modules: the server decides then.
  function mayUseKudde(worker) {
    return !worker || !Array.isArray(worker.modules) || worker.modules.includes("kudde");
  }

  function hideGate() {
    document.getElementById("signinScreen").classList.add("hidden");
    document.getElementById("signinScreen").classList.remove("flex");
  }

  function renderHeader() {
    const s = current();
    const el = document.getElementById("workerChip");
    el.classList.toggle("hidden", !s);
    if (s) document.getElementById("workerName").textContent = s.worker.name;
  }

  function signedIn(value) {
    write(value);
    wasSignedIn = true;
    hideGate();
    renderHeader();
    const release = waiting;
    waiting = [];
    release.forEach((resolve) => resolve());
    if (typeof KuddeSignIn.onSignedIn === "function") KuddeSignIn.onSignedIn();
  }

  // The number from a scanned card: online the server decides; offline the
  // phone's copy of the register does, and the token follows with signal.
  async function fromCard(text) {
    const number = numberFromCode(text);
    if (!number) return;
    setMessage(t("signing_in"));
    try {
      const res = await Kudde._fetchWithTimeout(`${FIELD_API}/sign-in`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json", ...Kudde.authHeaders() },
        body: JSON.stringify({ number }),
      });
      const body = await res.json().catch(() => ({}));
      if (res.status === 401 && body.unpaired) { KuddeSignIn.unpaired(); return; }
      if (!res.ok) { setMessage(body.message || `${res.status}`, true); return; }
      Kudde.setOffline(false);
      const worker = { ...body.worker, modules: body.modules };
      if (!mayUseKudde(worker)) { setMessage(t("not_allowed"), true); return; }
      signedIn({ token: body.token, worker, day: Kudde.localDateStr() });
      refreshWorkers().catch(() => {});
    } catch (e) {
      if (!Kudde.isNetworkError(e)) { setMessage(Kudde.errorDetail(e), true); return; }
      Kudde.setOffline(true);
      const known = cachedWorkers().find((w) => String(w.number).toLowerCase() === number.toLowerCase());
      if (!known) { setMessage(t("unknown_offline"), true); return; }
      if (!mayUseKudde(known)) { setMessage(t("not_allowed"), true); return; }
      signedIn({ token: null, worker: known, day: Kudde.localDateStr() });
      Kudde.toast(t("signed_in_offline"));
    }
  }

  // A worker signed in with no signal has no token yet: get one now. False
  // when that is not possible yet (still offline) or the card was refused
  // since (the gate is then showing why).
  async function ensureToken() {
    const s = current();
    if (!s) return false;
    if (s.token) return true;
    try {
      const res = await Kudde._fetchWithTimeout(`${FIELD_API}/sign-in`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json", ...Kudde.authHeaders() },
        body: JSON.stringify({ number: s.worker.number }),
      });
      const body = await res.json().catch(() => ({}));
      if (res.status === 401 && body.unpaired) { KuddeSignIn.unpaired(); return false; }
      if (!res.ok) { write(null); showGate(body.message || ""); return false; }
      write({ ...s, token: body.token, worker: { ...body.worker, modules: body.modules } });
      renderHeader();
      return true;
    } catch (e) {
      return false;
    }
  }

  // Every active worker's number and name, for recognising a card offline.
  async function refreshWorkers() {
    const list = await Kudde.api("/workers", { base: FIELD_API });
    try { localStorage.setItem(WORKERS_KEY, JSON.stringify(list)); } catch (_) { /* full */ }
  }

  async function switchWorker() {
    const ok = await Kudde.confirm({
      title: t("switch_title"), message: t("switch_message"),
      confirmLabel: t("switch"), cancelLabel: t("cancel"),
    });
    if (!ok) return;
    const token = current() && current().token;
    write(null);
    wasSignedIn = false;
    showGate("");
    if (token) {
      // Ends the session on the server too; never waited for, and no signal
      // simply leaves it to expire at midnight.
      Kudde._fetchWithTimeout(`${FIELD_API}/sign-in`, {
        method: "DELETE", headers: { ...Kudde.authHeaders(), Authorization: `Bearer ${token}`, Accept: "application/json" },
      }).catch(() => {});
    }
  }

  return {
    onSignedIn: null,
    token() { const s = current(); return s ? s.token : null; },
    worker() { const s = current(); return s ? s.worker : null; },
    fromCard,
    ensureToken,
    refreshWorkers,

    // Resolves once a worker is signed in for today on a paired phone: at
    // once if they are, otherwise after the pairing and the scan.
    ready() {
      renderHeader();
      if (current() && Kudde.device()) return Promise.resolve();
      showGate("");
      return new Promise((resolve) => waiting.push(resolve));
    },

    // The server no longer knows this phone's token (a new day, the worker
    // switched off in the office): forget it and ask for a card again.
    lost() {
      if (!state) return;
      // Never had a token (scanned in offline): nothing was refused but the
      // missing token, which ensureToken fetches; not a reason to ask again.
      if (current() && !current().token) return;
      write(null);
      wasSignedIn = false;
      showGate(t("expired"));
    },

    // The farm no longer knows this phone (disabled in the office, or paired
    // again elsewhere): forget the phone, its workers and the day, and ask for
    // a new code. What is in the outbox stays, and is sent once paired again.
    unpaired() {
      Kudde.setDevice(null);
      try { localStorage.removeItem(WORKERS_KEY); } catch (_) { /* nothing cached */ }
      write(null);
      wasSignedIn = false;
      showGate(t("unpaired"));
    },

    // Called every minute: midnight ends the day's sign-in on its own.
    checkDay() {
      if (wasSignedIn && !current()) {
        wasSignedIn = false;
        write(null);
        showGate(t("expired"));
      }
    },

    init() {
      document.getElementById("signinTitle").textContent = t("scan_title");
      document.getElementById("signinHint").textContent = t("scan_hint");
      document.getElementById("signinScanLabel").textContent = t("scan_button");
      document.getElementById("pairCode").placeholder = t("pair_placeholder");
      document.getElementById("pairBtn").textContent = t("pair_button");
      document.getElementById("pairForm").addEventListener("submit", (e) => {
        e.preventDefault();
        pair(document.getElementById("pairCode").value).catch(() => {});
      });
      // Opened from the office's pairing QR: the code is in the address.
      const fromQr = /[#&]pair=([A-Za-z0-9 -]+)/.exec(location.hash);
      if (fromQr) {
        history.replaceState(null, "", location.pathname + location.search);
        pair(decodeURIComponent(fromQr[1])).catch(() => {});
      }
      document.getElementById("notYouBtn").textContent = t("not_you");
      document.getElementById("signinScanBtn").addEventListener("click", () => { openScanner("card").catch(() => {}); });
      document.getElementById("notYouBtn").addEventListener("click", () => { switchWorker().catch(() => {}); });
      document.getElementById("signinFarm").textContent = (window.PLAASHEK && window.PLAASHEK.farm) || "";
      renderHeader();
    },
  };
})();
