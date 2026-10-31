# Plaashek marketing material

Product overview PDFs, built from screenshots of the farm-PC Boord apps renamed to
Plaashek and running on invented demo data. Nothing here identifies a real farm.

| File | What it is |
|---|---|
| `Plaashek_Product_Overview.pdf` | The complete document: front page, one-page summary, contents, six module sections, other modules, contact and references |
| `Plaashek_Overview_One_Page.pdf` | The suite at a glance, one landscape page |
| `Plaashek_Harvest_Overview.pdf` | Boord (Harvest) module |
| `Plaashek_Packhouse_Overview.pdf` | Boord Packhouse |
| `Plaashek_Owner_Overview.pdf` | Boord Owner |
| `Plaashek_Labour_Overview.pdf` | Boord Labour |
| `Plaashek_Notes_Overview.pdf` | Boord Notes |
| `Plaashek_Kudde_Overview.pdf` | Kudde |

## Rebuilding

Each PDF is rendered from an HTML file next to it (`overview.html` is Harvest; `ecosystem.html`
the one-pager; `front.html`, `others.html`, `back.html` the combined document's extra pages).
Screenshots live in `shots*/`. The `capture_*.js` scripts retake them with Playwright against the
sibling apps' dev servers on their usual ports (Boord 8811, Packhouse 8060, Owner 8010, Labour
8040, Notes 8020, Kudde 8030), after the `seed_*.py` scripts have loaded the demo data; every
"Boord" and farm name on screen is replaced by `rename.js` before the shot is taken.

```sh
node render.js overview Plaashek_Harvest_Overview "Plaashek · Harvest"   # one module PDF
node render_nofoot.js overview overview 0                                 # the same, without a footer, into build/
python build_full.py                                                      # the combined document (needs pypdf and fpdf2)
```
