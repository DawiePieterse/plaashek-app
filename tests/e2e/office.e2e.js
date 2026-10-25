// The office's whole day in a real browser (PLAN.md phase 5): sign in, look at the herd, find an animal, put the
// day's captures right, take a report away, and see what a viewer and a suspended licence leave possible.
// The pieces are tested in Pest through Livewire; this proves the pages render, link up and work by clicking,
// Filament's JavaScript, modals and uploads included.
//
// Runs against a fresh farm in the e2e database (scripts/e2e-serve.sh, Database\Seeders\OfficeDaySeeder):
//   npm run e2e
// Needs PHP, the e2e database and Playwright's Chromium (pre-installed in Claude's sandbox; `npx playwright
// install chromium` elsewhere).

import { after, before, describe, test } from "node:test";
import assert from "node:assert/strict";
import { spawn, execFileSync } from "node:child_process";
import { createRequire } from "node:module";
import { mkdirSync } from "node:fs";
import { fileURLToPath } from "node:url";
import path from "node:path";

// Playwright from the project if it is installed there, else from wherever Node finds it (the sandbox's copy).
const { chromium } = createRequire(import.meta.url)("playwright");

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");
const PORT = process.env.E2E_PORT || "8123";
const BASE = `http://127.0.0.1:${PORT}`;
const OUT = path.join(ROOT, "test-results");
const OWNER = "andre@example.com";
const VIEWER = "kantoor@example.com";

let server;
let browser;

/** An artisan command against the e2e farm, with the same environment the server has. */
function artisan(...args) {
  return execFileSync("php", ["artisan", ...args], {
    cwd: ROOT,
    env: { ...process.env, APP_ENV: "e2e", DB_DATABASE: process.env.E2E_DB_DATABASE || "plaashek_e2e", CACHE_STORE: "array" },
    encoding: "utf8",
  });
}

async function waitForServer() {
  for (let i = 0; i < 120; i++) {
    try {
      if ((await fetch(`${BASE}/up`)).ok) return;
    } catch {
      // not listening yet
    }
    await new Promise((r) => setTimeout(r, 250));
  }
  throw new Error(`The e2e server did not answer on ${BASE}`);
}

/** A signed-in page for one person, at desktop width unless told otherwise. */
async function signIn(email, viewport = { width: 1280, height: 900 }) {
  const context = await browser.newContext({ viewport, acceptDownloads: true });
  const page = await context.newPage();
  page.setDefaultTimeout(15000);
  await page.goto(`${BASE}/admin/login`);
  await page.locator("#form\\.email").fill(email);
  await page.locator("#form\\.password").fill("password");
  await page.getByRole("button", { name: "Sign in" }).click();
  await page.waitForURL(`${BASE}/admin`);
  return page;
}

/** The row of a Filament table that holds this text. */
function row(page, text) {
  return page.locator("tr.fi-ta-row").filter({ hasText: text });
}

/** The rows with a cell reading exactly this ("Move", not the "Remove" beside it). */
function rowsWith(page, text) {
  return page.locator("tr.fi-ta-row").filter({ has: page.getByText(text, { exact: true }) });
}

/** The table's own count of what it shows ("Showing 1 to 4 of 4 results"). */
function results(page, n) {
  // Attached, not visible: the overview is only shown on wider screens, the count is the same.
  return {
    waitFor: () => page.locator(".fi-pagination-overview").filter({ hasText: `of ${n} results` }).waitFor({ state: "attached" }),
  };
}

/** Waits until the table's first column reads these tags, in this order. */
async function tagsInOrder(page, tags) {
  await page.waitForFunction(
    (want) => [...document.querySelectorAll("tr.fi-ta-row td:first-child")].map((td) => td.innerText.trim()).join(",") === want,
    tags.join(","),
  );
}

/** A screenshot named after the step, kept when a step fails (test-results/ is ignored by git). */
async function step(page, name, fn) {
  try {
    await fn();
  } catch (e) {
    mkdirSync(OUT, { recursive: true });
    await page.screenshot({ path: path.join(OUT, `office-${name}.png`), fullPage: true }).catch(() => {});
    throw e;
  }
}

before(async () => {
  server = spawn(path.join(ROOT, "scripts/e2e-serve.sh"), [PORT], { cwd: ROOT, stdio: ["ignore", "ignore", "inherit"] });
  await waitForServer();
  browser = await chromium.launch();
});

after(async () => {
  await browser?.close();
  server?.kill();
});

describe("the office's day", { concurrency: false }, () => {
  test("the owner sees the farm at a glance and finds the herd", async () => {
    const page = await signIn(OWNER);
    await step(page, "home", async () => {
      const tile = page.locator(".plaashek-tile").filter({ hasText: "Kudde" });
      await tile.getByText("4", { exact: true }).waitFor();
      await tile.click();
      await page.waitForURL(`${BASE}/admin/kudde`);
      // The cow in withdrawal is on the watch list, the double move among the recent records.
      await page.getByText("In withdrawal").first().waitFor();
      await page.locator(".plaashek-list-row").filter({ hasText: "A2" }).first().waitFor();
    });

    await step(page, "herd-list", async () => {
      await page.getByRole("link", { name: "Animals" }).first().click();
      await page.waitForURL(`${BASE}/admin/kudde/animals`);
      await results(page, 4).waitFor();
      // Tags as they are read: A1, A2, B10, C3.
      await tagsInOrder(page, ["A1", "A2", "B10", "C3"]);

      await page.getByPlaceholder("Tag, name, breed, dam or sire").fill("nguni");
      await results(page, 2).waitFor();
      await row(page, "A2").waitFor();
      await row(page, "C3").waitFor();
      assert.equal(await row(page, "Bessie").count(), 0, "A1's breed was typed Ngnui, so it is not found yet");
      await page.getByPlaceholder("Tag, name, breed, dam or sire").fill("");
      await results(page, 4).waitFor();
    });

    await step(page, "herd-sort", async () => {
      await page.getByRole("button", { name: "Last kg" }).click();
      // 41 kg, 160, 380, 690: waited for, as the table is redrawn when Livewire answers.
      await tagsInOrder(page, ["A1", "C3", "A2", "B10"]);
    });
    await page.context().close();
  });

  test("the owner puts the day's captures right", async () => {
    const page = await signIn(OWNER);

    await step(page, "correct-record", async () => {
      await page.goto(`${BASE}/admin/kudde/animals`);
      await row(page, "A1").click();
      await page.getByRole("heading", { name: "A1 Bessie" }).waitFor();
      await page.getByText("Family").waitFor();

      await page.getByRole("button", { name: "Correct record" }).click();
      const modal = page.locator(".fi-modal-window").filter({ hasText: "Breed" });
      await modal.getByLabel("Breed").fill("Nguni");
      await modal.getByRole("button", { name: "Submit" }).click();
      await modal.waitFor({ state: "hidden" });
      await page.locator(".fi-in-entry").filter({ hasText: "Breed" }).getByText("Nguni").waitFor();
    });

    await step(page, "correct-weight", async () => {
      const weighing = row(page, "41.0 kg");
      await weighing.getByRole("button", { name: "Correct" }).click();
      const modal = page.locator(".fi-modal-window").filter({ hasText: "Correct this record" });
      await modal.getByLabel("kg").fill("412");
      await modal.getByRole("button", { name: "Save" }).click();
      await modal.waitFor({ state: "hidden" });
      await row(page, "412.0 kg").waitFor();
      assert.equal(await row(page, "41.0 kg").count(), 0);
    });

    await step(page, "add-photo", async () => {
      await page.getByRole("tab", { name: "Photos" }).click();
      await page.getByText("No photos yet").waitFor();
      await page.getByRole("button", { name: "Add photo" }).click();
      const modal = page.locator(".fi-modal-window").filter({ hasText: "Add photo" });
      // Any real image will do: the page itself, as a PNG.
      const png = await page.screenshot({ clip: { x: 0, y: 0, width: 400, height: 300 } });
      await modal.locator('input[type="file"]').setInputFiles({ name: "a1.png", mimeType: "image/png", buffer: png });
      await modal.locator(".filepond--item[data-filepond-item-state='processing-complete']").waitFor({ timeout: 30000 });
      await modal.getByRole("button", { name: "Submit" }).click();
      await modal.waitFor({ state: "hidden" });
      await page.locator("tr.fi-ta-row img").first().waitFor();
    });

    await step(page, "remove-double-move", async () => {
      await page.goto(`${BASE}/admin/kudde/animals`);
      await row(page, "B10").click();
      await page.getByRole("heading", { name: "B10 Bul" }).waitFor();
      const moves = rowsWith(page, "Move");
      assert.equal(await moves.count(), 3, "placed in Bo-kamp, then the same move to Rivier twice");
      await moves.first().getByRole("button", { name: "Remove" }).click();
      await page.locator(".fi-modal-window").getByRole("button", { name: "Confirm" }).click();
      await page.waitForFunction(() => document.querySelectorAll("tr.fi-ta-row").length === 3);
      assert.equal(await rowsWith(page, "Move").count(), 2);
      // Still in Rivier: one of the two moves there remains.
      await page.locator(".fi-in-entry").filter({ hasText: "Camp" }).getByText("Rivier").waitFor();
    });
    await page.context().close();
  });

  test("the owner takes the herd register away and checks the licence", async () => {
    const page = await signIn(OWNER);

    await step(page, "report", async () => {
      await page.goto(`${BASE}/admin/kudde`);
      await page.getByRole("button", { name: "Reports" }).click();
      const modal = page.locator(".fi-modal-window").last();
      await modal.waitFor();
      await modal.getByText("Choose a report").click();
      await page.getByRole("option", { name: "Herd register" }).click();
      const [download] = await Promise.all([
        page.waitForEvent("download"),
        modal.getByRole("button", { name: "Download" }).click(),
      ]);
      assert.match(download.suggestedFilename(), /\.xlsx$/);
    });

    await step(page, "licence", async () => {
      // Through the menu, as the owner finds it.
      await page.getByRole("link", { name: "The farm" }).click();
      await page.waitForURL(`${BASE}/admin/settings/farm`);
      await page.getByText("Licences").waitFor();
      await page.locator(".plaashek-list-row").filter({ hasText: "Kudde" }).getByText("active").waitFor();
    });
    await page.context().close();
  });

  test("a viewer reads everything and corrects nothing", async () => {
    const page = await signIn(VIEWER);
    await step(page, "viewer", async () => {
      await page.goto(`${BASE}/admin/kudde/animals`);
      await row(page, "A1").click();
      await page.getByRole("heading", { name: "A1 Bessie" }).waitFor();
      await row(page, "412.0 kg").waitFor();
      assert.equal(await page.getByRole("button", { name: "Correct record" }).count(), 0);
      assert.equal(await page.getByRole("button", { name: "Correct", exact: true }).count(), 0);
      assert.equal(await page.getByRole("button", { name: "Remove" }).count(), 0);
    });
    await page.context().close();
  });

  test("a suspended licence leaves Kudde to read and export, saving nothing", async () => {
    artisan("farm:licence", "kudde", "suspended");
    try {
      const page = await signIn(OWNER);
      await step(page, "suspended", async () => {
        await page.goto(`${BASE}/admin/kudde`);
        await page.getByText("Kudde is read-only").waitFor();
        await page.goto(`${BASE}/admin/kudde/animals`);
        await row(page, "A1").click();
        await page.getByRole("heading", { name: "A1 Bessie" }).waitFor();
        assert.equal(await page.getByRole("button", { name: "Correct record" }).count(), 0);
        assert.equal(await page.getByRole("button", { name: "Remove" }).count(), 0);
      });
      await page.context().close();
    } finally {
      artisan("farm:licence", "kudde", "active");
    }
  });

  test("the same day fits a phone", async () => {
    const page = await signIn(OWNER, { width: 390, height: 844 });
    await step(page, "phone", async () => {
      await page.locator(".plaashek-tile").filter({ hasText: "Kudde" }).click();
      await page.waitForURL(`${BASE}/admin/kudde`);
      await page.goto(`${BASE}/admin/kudde/animals`);
      await row(page, "A1").click();
      await page.getByRole("heading", { name: "A1 Bessie" }).waitFor();
      // Nothing wider than the phone: the page itself never scrolls sideways.
      const wide = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      assert.ok(wide <= 1, `the page is ${wide}px wider than the phone`);
    });
    await page.context().close();
  });
});
