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
// Kept in localStorage: { token, worker: {number, name}, day }. "day" is the
// phone's local date of the scan, so the morning after always asks again even
// if the server's token somehow outlived it.
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

  function showGate(message) {
    renderHeader();
    document.getElementById("signinScreen").classList.remove("hidden");
    document.getElementById("signinScreen").classList.add("flex");
    setMessage(message || "", !!message);
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
      const res = await Kudde._fetchWithTimeout(`${API_BASE}/api/sign-in`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ number }),
      });
      const body = await res.json().catch(() => ({}));
      if (!res.ok) { setMessage(body.message || `${res.status}`, true); return; }
      Kudde.setOffline(false);
      signedIn({ token: body.token, worker: body.worker, day: Kudde.localDateStr() });
      refreshWorkers().catch(() => {});
    } catch (e) {
      if (!Kudde.isNetworkError(e)) { setMessage(Kudde.errorDetail(e), true); return; }
      Kudde.setOffline(true);
      const known = cachedWorkers().find((w) => String(w.number).toLowerCase() === number.toLowerCase());
      if (!known) { setMessage(t("unknown_offline"), true); return; }
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
      const res = await Kudde._fetchWithTimeout(`${API_BASE}/api/sign-in`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ number: s.worker.number }),
      });
      const body = await res.json().catch(() => ({}));
      if (!res.ok) { write(null); showGate(body.message || ""); return false; }
      write({ ...s, token: body.token, worker: body.worker });
      renderHeader();
      return true;
    } catch (e) {
      return false;
    }
  }

  // Every active worker's number and name, for recognising a card offline.
  async function refreshWorkers() {
    const list = await Kudde.api("/api/workers");
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
      Kudde._fetchWithTimeout(`${API_BASE}/api/sign-in`, {
        method: "DELETE", headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
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

    // Resolves once a worker is signed in for today: at once if they are,
    // otherwise after the scan.
    ready() {
      renderHeader();
      if (current()) return Promise.resolve();
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
      document.getElementById("notYouBtn").textContent = t("not_you");
      document.getElementById("signinScanBtn").addEventListener("click", () => { openScanner("card").catch(() => {}); });
      document.getElementById("notYouBtn").addEventListener("click", () => { switchWorker().catch(() => {}); });
      document.getElementById("signinFarm").textContent = (window.PLAASHEK && window.PLAASHEK.farm) || "";
      renderHeader();
    },
  };
})();
