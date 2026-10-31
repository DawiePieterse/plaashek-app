"""Demo data for the Packhouse screenshots. Posts through the open floor sync
endpoints on :8060, and rewrites the seeded receivers (real names from the
paper notes) with invented ones straight in packhouse.db."""
import json, sqlite3, urllib.request, uuid as uuidlib
from datetime import datetime, timedelta, timezone, date

BASE = "http://localhost:8060"
DB = "/home/user/boord-packhouse/data/packhouse.db"
NOW = datetime.now(timezone.utc)
def iso(dt): return dt.astimezone(timezone.utc).isoformat().replace("+00:00", "Z")
def ago(**kw): return NOW - timedelta(**kw)
def post(path, body):
    req = urllib.request.Request(BASE + path, data=json.dumps(body).encode(), headers={"Content-Type": "application/json"})
    out = json.loads(urllib.request.urlopen(req, timeout=15).read())
    if out.get("rejected"): print(path, "REJECTED", out["rejected"])
    return out

# --- Settings lists: demo names in place of the seeded real ones ---------
con = sqlite3.connect(DB)
con.execute("UPDATE receiver SET name='Highveld Market Agents', market='Johannesburg Market', address_1='Stand 42, Fresh Produce Market', address_2='', city='Johannesburg' WHERE id=1")
con.execute("UPDATE receiver SET name='Lowveld Juice Works', market='', address_1='14 Industrial Rd', address_2='', city='Nelspruit' WHERE id=2")
con.execute("UPDATE transporter SET name='Swift Cold Chain' WHERE id=2")
con.execute("DELETE FROM targetreceiver")
con.execute("INSERT INTO targetreceiver (name, address, market, sort, active) VALUES ('Pacific Fresh Imports', '1200 Harbor Blvd, Los Angeles, CA', 'USA', 0, 1), ('Nordic Fruit BV', 'Fruitweg 8, Rotterdam, Netherlands', 'EU', 1, 1)")
con.execute("UPDATE deliverynotesettings SET last_no=1341 WHERE id=1")
con.commit(); con.close()
print("settings lists rewritten")

def dcode(d: date):
    wd = d.isoweekday(); wk = d.isocalendar()[1]
    return f"{wk % 10}{wd}0{wk // 10}"
TODAY = NOW.astimezone(timezone(timedelta(hours=2))).date()
YDAY = TODAY - timedelta(days=1)

W = {"003": "Maria van Wyk", "002": "Sipho Dlamini", "004": "Thabo Nkosi"}
B8A = dict(block_id="8a", block_name="Block 8a", variety="Mauritius")
B8B = dict(block_id="8b", block_name="Block 8b", variety="McLean's Red")
B10 = dict(block_id="10", block_name="Block 10", variety="McLean's Red")
JANSEN = dict(supplier_id=2, supplier_name="Jansen Boerdery")

# --- Sulfur runs ----------------------------------------------------------
def run(u, tent, started, minutes, src, ended=None, grams=425, crates=24, size="XL", by="003"):
    t = {1: "Tent A", 2: "Tent B", 3: "Tent C", 4: "Tent D"}[tent]
    r = dict(uuid=u, tent_id=tent, tent_name=t, grams=grams, minutes=minutes, crates=crates,
             started_at=iso(started), started_by=by, started_by_name=W[by], suggested_minutes=minutes,
             fruit_kg=crates * 20.0, suggestion_temp=27.0, suggestion_humidity=58.0, estimated_ppm=5.2,
             batch_no=f"T{tent}-{dcode(TODAY)}-{started.astimezone(timezone(timedelta(hours=2))).strftime('%H%M')}-B{src.get('block_id', 'S2')}-{u[-2:]}",
             size=size, **src)
    if ended: r.update(ended_at=iso(ended), ended_by=by, ended_by_name=W[by])
    return r
runs = [
    run("sr-a1", 1, ago(hours=5, minutes=10), 60, B8A, ended=ago(hours=4, minutes=8)),
    run("sr-b1", 2, ago(hours=4, minutes=40), 55, B8B, ended=ago(hours=3, minutes=44), size="L"),
    run("sr-c1", 3, ago(hours=3, minutes=5), 65, JANSEN, ended=ago(hours=1, minutes=58), size="XXL", by="002"),
    run("sr-a2", 1, ago(hours=2, minutes=15), 60, B8A, ended=ago(hours=1, minutes=12)),
    run("sr-b2", 2, ago(minutes=38), 60, B10, size="XL", by="004"),          # running
    run("sr-d1", 4, ago(minutes=12), 55, B8A, size="L"),                     # running
]
print("sulfur", post("/api/sulfur/sync", {"device": "tablet-1", "runs": runs})["synced"])

# --- Acid batches ---------------------------------------------------------
def batch(u, bath, started, src, run_uuid=None, route="export", dip_end=None, end=None, crates=24, by="003"):
    b = dict(uuid=u, bath_id=bath, bath_name=f"Bath {bath}", route=route, sulfur_run_uuid=run_uuid, crates=crates,
             dip_minutes=5, dry_minutes=30, started_at=iso(started), started_by=by, started_by_name=W[by], **src)
    if dip_end: b.update(dip_ended_at=iso(dip_end), dip_ended_by=by, dip_ended_by_name=W[by])
    if end: b.update(ended_at=iso(end), ended_by=by, ended_by_name=W[by])
    return b
batches = [
    batch("ab-1", 1, ago(hours=3, minutes=50), B8A, "sr-a1", dip_end=ago(hours=3, minutes=44), end=ago(hours=3, minutes=10)),
    batch("ab-2", 2, ago(hours=3, minutes=20), B8B, "sr-b1", dip_end=ago(hours=3, minutes=14), end=ago(hours=2, minutes=40)),
    batch("ab-3", 1, ago(minutes=22), B8A, "sr-a2", dip_end=ago(minutes=17)),       # drying
    batch("ab-4", 2, ago(minutes=3), JANSEN, "sr-c1", by="002"),                     # dipping
    batch("ab-5", 1, ago(hours=1, minutes=5), B10, route="local", dip_end=ago(hours=1), end=ago(minutes=30), crates=12),
]
changes = [dict(uuid="cc-1", bath_id=1, bath_name="Bath 1", changed_at=iso(ago(days=2, hours=3)), crates_since=488, by="003", by_name=W["003"]),
           dict(uuid="cc-2", bath_id=2, bath_name="Bath 2", changed_at=iso(ago(days=1, hours=2)), crates_since=512, by="002", by_name=W["002"])]
checks = [dict(uuid="bc-1", bath_id=1, bath_name="Bath 1", checked_at=iso(ago(hours=6)), value=2.1, note="", by="003", by_name=W["003"])]
print("acid", post("/api/acid/sync", {"device": "tablet-1", "batches": batches, "changes": changes, "checks": checks})["synced"])

# --- Pallets --------------------------------------------------------------
PACK = {1: ("Pre-pac", 0.48), 2: ("1kg XXL", 1.1), 3: ("1kg XL", 1.1), 4: ("1kg L", 1.1)}
MKT = {1: "USA Sea Freight", 2: "USA Air Freight", 3: "EU Sea Freight", 4: "EU Air Freight", 5: "Local"}
def line(pid, cartons, blk): return dict(packaging_id=pid, packaging_name=PACK[pid][0], carton_kg=PACK[pid][1], cartons=cartons, **blk)
def pallet(u, ref, scanned, market, lines, n, sup=(1, "Plaashek Demo Farm"), by="003"):
    return dict(uuid=u, consignment_uuid="cons-1", barcode=f"(00)0600990059630{n:06d}", scanned_at=iso(scanned), pallet_ref=ref,
                supplier_id=sup[0], supplier_name=sup[1], market_id=market, market_name=MKT[market],
                date_code=dcode(scanned.astimezone(timezone(timedelta(hours=2))).date()), scanned_by=by, scanned_by_name=W[by],
                changed_by=by, changed_by_name=W[by], lines=lines)
pallets = [
    # yesterday, on notes
    pallet("pl-101", "101", ago(days=1, hours=6), 5, [line(1, 240, B8A)], 101),
    pallet("pl-102", "102", ago(days=1, hours=5), 5, [line(1, 200, B8B), line(4, 40, B8B)], 102),
    pallet("pl-103", "103", ago(days=1, hours=4), 1, [line(2, 96, B8A), line(3, 24, B8A)], 103),
    pallet("pl-104", "104", ago(days=1, hours=3), 1, [line(2, 120, B8A)], 104),
    # today
    pallet("pl-105", "105", ago(hours=4), 1, [line(2, 96, B8A), line(3, 24, B8A)], 105),
    pallet("pl-106", "106", ago(hours=3, minutes=30), 1, [line(3, 120, B8A)], 106),
    pallet("pl-107", "107", ago(hours=2, minutes=50), 3, [line(2, 80, B8B), line(4, 40, B8B)], 107),
    pallet("pl-108", "108", ago(hours=2), 3, [line(2, 120, B8B)], 108, by="002"),
    pallet("pl-109", "109", ago(hours=1, minutes=20), 5, [line(1, 240, B10)], 109, by="002"),
    pallet("pl-110", "110", ago(minutes=25), 5, [line(1, 180, B10), line(4, 60, B10)], 110, sup=(2, "Jansen Boerdery")),
]
print("pallets", post("/api/consignment/sync", {"device": "phone-1", "consignments": [dict(uuid="cons-1", created_at=iso(ago(days=1, hours=6)))], "pallets": pallets})["synced"])

# --- Juice bins -----------------------------------------------------------
def jbin(u, no, when, empty, full, container="bin", count=1, by="004"):
    return dict(uuid=u, container=container, count=count, bin_no=no, weighed_at=iso(when), empty_kg=empty, full_kg=full,
                made_by=by, made_by_name=W[by], changed_by=by, changed_by_name=W[by])
bins = [
    jbin("jb-86", 86, ago(days=1, hours=5), 37, 503), jbin("jb-87", 87, ago(days=1, hours=4), 36, 488),
    jbin("jb-88", 88, ago(hours=3), 37, 511), jbin("jb-89", 89, ago(hours=2), 35, 496),
    jbin("jb-90", 90, ago(minutes=40), 36, None),
    jbin("jc-1", 1, ago(hours=1, minutes=30), None, 22.0, container="crate", count=14),
]
print("juice", post("/api/juice/sync", {"device": "phone-1", "bins": bins})["synced"])

# --- Delivery notes -------------------------------------------------------
def note(u, no, when, receiver, transporter, pallets=(), bins=(), target=None, seal="", by="003", **kw):
    r = {1: (1, "Highveld Market Agents\nJohannesburg Market\nStand 42, Fresh Produce Market\nJohannesburg"),
         2: (2, "Lowveld Juice Works\n14 Industrial Rd\nNelspruit")}[receiver]
    t = {1: (1, "Self"), 2: (2, "Swift Cold Chain")}[transporter]
    d = when.astimezone(timezone(timedelta(hours=2))).date()
    n = dict(uuid=u, note_no=no, created_at=iso(when), receiver_id=r[0], receiver=r[1], transporter_id=t[0], transporter=t[1],
             seal_no=seal, cooled_to="8", loaded_on=d.isoformat(), offloaded_on=(d + timedelta(days=1)).isoformat(),
             pallets=[{"uuid": p} for p in pallets], bins=[{"uuid": b} for b in bins], made_by=by, made_by_name=W[by], **kw)
    if target: n.update(target_receiver_id=target[0], target_receiver=target[1])
    return n
notes = [
    note("dn-1", 1340, ago(days=1, hours=2), 1, 2, pallets=("pl-101", "pl-102"), seal="SC 48812"),
    note("dn-2", 1341, ago(days=1, hours=1), 1, 2, pallets=("pl-103", "pl-104"), seal="SC 48813", target=(1, "Pacific Fresh Imports\n1200 Harbor Blvd, Los Angeles, CA")),
    note("dn-3", 1342, ago(minutes=50), 1, 2, pallets=("pl-105", "pl-106"), seal="SC 48820", target=(1, "Pacific Fresh Imports\n1200 Harbor Blvd, Los Angeles, CA")),
    note("dn-j1", 1, ago(days=1, hours=1), 2, 1, bins=("jb-86", "jb-87")),
]
print("notes", post("/api/deliverynote/sync", {"device": "phone-1", "notes": notes})["synced"])
