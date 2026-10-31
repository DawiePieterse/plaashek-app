"""Assemble the complete Plaashek overview: front, summary, contents, the six
module sections, the other-modules page and the contact page, stamped with
continuous page numbers."""
import subprocess, io
from pypdf import PdfReader, PdfWriter
from fpdf import FPDF
B = 'build/'
SECTIONS = [('Harvest', 'overview'), ('Packhouse', 'packhouse'), ('Owner', 'owner'), ('Labour', 'labour'), ('Notes', 'notes'), ('Kudde', 'kudde'), ('Other modules', 'others'), ('Speak to Plaashek', 'back')]
counts = {k: len(PdfReader(B + f + '.pdf').pages) for k, f in SECTIONS}
# Physical pages: front 1, summary 2, contents 3, then sections.
page = 4
starts = {}
for k, f in SECTIONS:
    starts[k] = page; page += counts[k]
# Contents page.
rows = ''.join(f'<div class="row"><span class="n">{i+1}</span><span class="t">{k}<small>{desc}</small></span><span class="dots"></span><span class="p">{starts[k]}</span></div>'
    for i, (k, desc) in enumerate([
        ('Harvest', 'Field capture, receiving, dashboard, picker pay, reports, planner, master data'),
        ('Packhouse', 'Sulfur and acid timers, pallet stack, delivery notes and export papers, juice'),
        ('Owner', 'Season analysis, crop estimate, weather record, risk score, market prices'),
        ('Labour', 'The pack-house time clock: badge in and out, fix, pay, settings'),
        ('Notes', 'The farm notebook: capture, work orders, history, dashboard'),
        ('Kudde', 'The herd book: field app, events, admin dashboard, herd and settings'),
        ('Other modules', 'Weer, Eienaar, Werkers, Stoor, Water, Bespuitings, Werkswinkel, Oudit'),
        ('Speak to Plaashek', 'Contact, what you get, references')]))
html = f'''<!doctype html><html><head><meta charset="utf-8"><title>Contents</title><style>
@page {{ size: A4; margin: 14mm 14mm 16mm 14mm; }}
body {{ font-family: -apple-system, "Segoe UI", Inter, Helvetica, Arial, sans-serif; color:#1e293b; margin:0; }}
.kicker {{ text-transform: uppercase; letter-spacing: 1.5px; font-size: 8.5pt; color:#64748b; font-weight:600; }}
h2 {{ color:#0b2e63; font-size: 22pt; margin: 2px 0 14px; }}
.row {{ display:flex; align-items:flex-end; gap:10px; padding: 9px 0; border-bottom: 1px solid #e2e8f0; }}
.n {{ color:#0b2e63; font-weight:800; font-size: 14pt; width: 9mm; }}
.t {{ font-weight:700; font-size: 12.5pt; color:#0b2e63; }}
.t small {{ display:block; font-weight:400; font-size: 9pt; color:#64748b; margin-top:2px; }}
.dots {{ flex:1; border-bottom: 1px dotted #94a3b8; margin: 0 4px 6px; }}
.p {{ font-weight:700; font-size: 12pt; width: 10mm; text-align:right; }}
.pre {{ margin-bottom: 10px; }}
.note {{ margin-top: 18px; background:#f1f5f9; border-left: 4px solid #0b2e63; padding: 8px 12px; border-radius:6px; font-size: 9.5pt; }}
</style></head><body>
<div class="kicker">Plaashek product overview</div><h2>Contents</h2>
<div class="pre"><div class="row"><span class="n"></span><span class="t">The suite at a glance<small>One page: the fruit's path, the five farm modules, and how they fit</small></span><span class="dots"></span><span class="p">2</span></div></div>
{rows}
<div class="note">Each module section opens with its own cover and can be read on its own. All screens show demonstration data on a fictitious farm. Plaashek · WhatsApp 083 456 5646 · info@plaashek.co.za · www.plaashek.co.za</div>
</body></html>'''
open('contents.html', 'w').write(html)
subprocess.run(['node', 'render_nofoot.js', 'contents', 'contents', '0'], check=True)

_stamps = []   # (label, number, landscape) in page order; drawn into one PDF so pypdf keeps every page alive
def stamp(label, number, landscape):
    _stamps.append((label, number, landscape))
    return len(_stamps) - 1
def build_stamps():
    pdf = FPDF(unit='mm', format='A4'); pdf.set_auto_page_break(False)
    for label, number, landscape in _stamps:
        pdf.add_page(orientation='L' if landscape else 'P'); pdf.set_font('Helvetica', '', 7.5); pdf.set_text_color(100, 116, 139)
        w = 297 if landscape else 210; h = 210 if landscape else 297
        pdf.set_xy(14, h - 9); pdf.cell(100, 4, f'Plaashek  |  {label}')
        pdf.set_xy(w - 34, h - 9); pdf.cell(20, 4, str(number), align='R')
    open(B + 'stamps.pdf', 'wb').write(bytes(pdf.output()))
    return PdfReader(B + 'stamps.pdf')

pages = []   # (page, stamp index or None); readers kept in this list so their pages stay readable
readers = {f: PdfReader(B + f + '.pdf') for f in ['front', 'ecosystem', 'contents'] + [f for _, f in SECTIONS]}
for p in readers['front'].pages: pages.append((p, None))
n = 2
for p in readers['ecosystem'].pages: pages.append((p, stamp('The suite at a glance', n, True))); n += 1
for p in readers['contents'].pages: pages.append((p, stamp('Contents', n, False))); n += 1
for k, f in SECTIONS:
    for p in readers[f].pages: pages.append((p, stamp(k, n, False))); n += 1
stamps = build_stamps()
out = PdfWriter()
for p, i in pages:
    if i is not None: p.merge_page(stamps.pages[i])
    out.add_page(p)
out.add_outline_item('The suite at a glance', 1); out.add_outline_item('Contents', 2)
for k, f in SECTIONS: out.add_outline_item(k, starts[k] - 1)
out.write('Plaashek_Product_Overview.pdf')
print('pages', n - 1, starts)
