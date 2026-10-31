"""Demo data for Boord Owner's owner.db: the seasons before this app, hourly
weather since 2012, a saved estimate for the running season, forecast
snapshots and market prices. Invented, deterministic, written straight into
owner.db (the app only ever reads most of these tables)."""
import math, random, sqlite3, json, urllib.request
from datetime import date, datetime, timedelta

DB = "/home/user/boordowner/data/owner.db"
random.seed(7)
con = sqlite3.connect(DB)
cur = con.cursor()
LAT, LON = -25.45, 30.95
TODAY = date(2026, 10, 31)

# --- Past seasons, per block per day (2020-2025) --------------------------
BLOCKS = {"7": 420, "8a": 380, "8b": 350, "9": 460, "10": 300}
# kg per tree per season: a good year, a poor dry year (2022), a bumper 2024.
YEAR_FACTOR = {2020: 0.88, 2021: 1.02, 2022: 0.71, 2023: 0.97, 2024: 1.18, 2025: 1.05}
BLOCK_BASE = {"7": 34.0, "8a": 42.0, "8b": 31.0, "9": 38.0, "10": 27.0}
cur.execute("DELETE FROM historicalharvest")
rows = []
for year, f in YEAR_FACTOR.items():
    start = date(year, 10, 12) + timedelta(days=random.randint(-6, 8))
    length = random.randint(38, 52)
    for bid, trees in BLOCKS.items():
        total = trees * BLOCK_BASE[bid] * f * random.uniform(0.92, 1.08)
        # A bell-shaped picking curve over the season, skipping Sundays.
        days = [start + timedelta(days=i) for i in range(length)]
        weights = [math.exp(-((i - length / 2) / (length / 3.2)) ** 2) * (0 if d.weekday() == 6 else 1) * random.uniform(0.6, 1.4)
                   for i, d in enumerate(days)]
        wsum = sum(weights)
        for d, w in zip(days, weights):
            if w > 0:
                rows.append((bid, d.isoformat(), year, round(total * w / wsum, 1), 0))
# This season up to the day before the field tablets' demo crates begin (28 Oct):
# about a third of a normal crop, picked 10-27 Oct.
for bid, trees in BLOCKS.items():
    total = trees * BLOCK_BASE[bid] * 1.05 * 0.34
    days = [date(2026, 10, 10) + timedelta(days=i) for i in range(18)]
    weights = [(0 if d.weekday() == 6 else 1) * (0.5 + i / 18) * random.uniform(0.7, 1.3) for i, d in enumerate(days)]
    wsum = sum(weights)
    for d, w in zip(days, weights):
        if w > 0:
            rows.append((bid, d.isoformat(), 2026, round(total * w / wsum, 1), 0))
cur.executemany("INSERT INTO historicalharvest (block_id, harvest_date, season_year, kg, estimated) VALUES (?,?,?,?,?)", rows)
print("historical harvest rows", len(rows))

# --- Annual totals before daily records (2012-2019), whole farm -----------
cur.execute("DELETE FROM historicalannualyield")
annual = {2012: 41200, 2013: 48900, 2014: 44100, 2015: 52300, 2016: 47800, 2017: 39600, 2018: 55100, 2019: 50400}
cur.executemany("INSERT INTO historicalannualyield (block_id, season_year, kg, estimated) VALUES (NULL,?,?,0)", list(annual.items()))

# --- Hourly weather 2012-01-01 .. today, farm-local naive timestamps --------
cur.execute("DELETE FROM weatherhistory")
# Year flavour: a dry spring makes a small crop. 2022 dry and hot, 2024 mild and moist.
_crop = {**annual, **{y: 62000 * f for y, f in YEAR_FACTOR.items()}}
_avg = sum(_crop.values()) / len(_crop)
SPRING = {y: max(-2.2, min(2.2, (kg / _avg - 1) * 6.5 + random.gauss(0, 0.55))) for y, kg in _crop.items()}
SPRING[2026] = 0.9
wrows = []
t = datetime(2012, 1, 1)
end = datetime(2026, 10, 31, 23)
day_rain = 0.0
while t <= end:
    doy = t.timetuple().tm_yday
    season = math.cos((doy - 20) / 365 * 2 * math.pi)       # +1 mid-Jan (summer), -1 mid-July
    spring = SPRING[t.year] if 8 <= t.month <= 11 else 0.0
    flowering = 8 <= t.month <= 9      # Aug - mid Sep: sunshine matters
    sizing = 10 <= t.month <= 11       # Oct - Nov: rain matters
    tmean = 22.5 + 5.5 * season - 0.7 * spring
    diurnal = 6.0 * math.cos((t.hour - 15) / 24 * 2 * math.pi)
    if t.hour == 0:
        wet = random.random() < (0.18 + 0.12 * season + (0.07 * spring if sizing else 0))
        day_rain = random.expovariate(1 / (9.0 + (2.5 * spring if sizing else 0))) if wet else 0.0
        day_cloud = random.uniform(0.5, 1.0) if wet else random.uniform(0.0, 0.5)
        if flowering: day_cloud = max(0.0, min(1.0, day_cloud - 0.12 * spring))
    temp = tmean + diurnal + random.gauss(0, 1.2) - (2.5 if day_rain else 0)
    dew = temp - (6.5 + 4.0 * (0.5 - day_cloud) - 1.6 * spring) + random.gauss(0, 0.8)
    dew = min(dew, temp - 0.5)
    hum = max(25, min(99, 100 - 5 * (temp - dew)))
    rain = round(day_rain * max(0, math.cos((t.hour - 16) / 24 * 2 * math.pi)) / 4, 1) if day_rain else 0.0
    sun = 0.0
    if 6 <= t.hour <= 18:
        sun = 3600 * max(0, math.sin((t.hour - 6) / 12 * math.pi)) * (1 - day_cloud * 0.9)
    code = 61 if rain > 0.3 else (3 if day_cloud > 0.7 else (2 if day_cloud > 0.3 else 0))
    cond = {0: "Clear", 2: "Partly Cloudy", 3: "Overcast", 61: "Rain"}[code]
    wrows.append((t.isoformat(sep=" "), round(temp, 1), round(hum), round(dew, 1), rain, code, cond,
                  round(abs(random.gauss(9, 4)), 1), round(0 if not (8 <= t.hour <= 17) else max(0, 9 * math.sin((t.hour - 7) / 11 * math.pi) * (1 - day_cloud * 0.8)), 1),
                  round(sun), LAT, LON))
    t += timedelta(hours=1)
cur.executemany("INSERT INTO weatherhistory (timestamp, temp_c, humidity_pct, dew_point_c, precipitation_mm, weather_code, condition, wind_speed_kmh, uv_index, sunshine_duration_s, lat, lon) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)", wrows)
print("weather rows", len(wrows))

# --- Forecast snapshots: the Risk tab's Expected kg trend ------------------
cur.execute("DELETE FROM forecastsnapshot")
snap = []
for i in range(70, 0, -1):
    d = TODAY - timedelta(days=i)
    snap.append((d.isoformat(), 2026, round(62000 + 180 * (70 - i) + random.gauss(0, 900), 0), datetime(d.year, d.month, d.day, 4).isoformat(sep=" ")))
cur.executemany("INSERT INTO forecastsnapshot (snapshot_date, season_year, expected_kg, built_at) VALUES (?,?,?,?)", snap)

# --- Market prices: the farm's own statements and a daily reference ---------
cur.execute("DELETE FROM marketpackprice"); cur.execute("DELETE FROM marketdailysale"); cur.execute("DELETE FROM marketdailyprice")
BUYERS = ["Highveld Market Agents", "Coastal Fresh Exports", "Lowveld Juice Works"]
PACKS = {"1kg XXL": 68.0, "1kg XL": 61.0, "1kg L": 54.0, "Pre-pac": 29.0}
pp = []
for year in (2024, 2025):
    for buyer in BUYERS[:2]:
        for pack, price in PACKS.items():
            cartons = random.randint(1800, 6500)
            p = price * (1.0 if year == 2025 else 0.91) * random.uniform(0.94, 1.06)
            pp.append((year, buyer, pack, cartons, round(p, 2), round(cartons * p, 2)))
cur.executemany("INSERT INTO marketpackprice (season_year, buyer, pack_type, cartons_sold, price_per_carton, sub_total) VALUES (?,?,?,?,?,?)", pp)
ds = []
for year in (2025, 2026):
    first = date(year, 10, 14)
    last = min(date(year, 12, 10), TODAY) if year == 2026 else date(year, 12, 10)
    d = first
    note = 1200 if year == 2025 else 1330
    while d <= last:
        if d.weekday() < 5 and random.random() < 0.75:
            for buyer in BUYERS[:2]:
                for pack in random.sample(list(PACKS), 2):
                    note += 1
                    drift = 1 + 0.25 * math.sin((d - first).days / 60 * math.pi)  # higher early, soft mid-season
                    ds.append((year, d.isoformat(), buyer, str(note), pack, random.randint(120, 480), round(PACKS[pack] * drift * random.uniform(0.9, 1.1), 2)))
        d += timedelta(days=1)
cur.executemany("INSERT INTO marketdailysale (season_year, sale_date, receiver, note_no, pack_type, cartons_sold, price_per_carton) VALUES (?,?,?,?,?,?,?)", ds)
dp = []
for i in range(45, 0, -1):
    d = TODAY - timedelta(days=i)
    if d.weekday() < 5:
        kg = random.uniform(9000, 26000); rpk = random.uniform(24, 36)
        dp.append((d.isoformat(), round(kg * rpk, 2), round(kg / 2), round(kg, 1), round(rpk, 2), datetime(d.year, d.month, d.day, 13).isoformat(sep=" ")))
cur.executemany("INSERT INTO marketdailyprice (price_date, value_sold, qty_sold, kg_sold, rand_per_kg, fetched_at) VALUES (?,?,?,?,?,?)", dp)
con.commit(); con.close()
print("market rows", len(pp), len(ds), len(dp))

# --- The owner's estimate for 2026, through the API so it is shaped right ---
BASE = "http://localhost:8010"
def api(path, body=None, method=None):
    req = urllib.request.Request(BASE + path, data=json.dumps(body).encode() if body is not None else None,
                                 headers={"Content-Type": "application/json"}, method=method or ("POST" if body is not None else "GET"))
    return json.loads(urllib.request.urlopen(req, timeout=30).read() or "null")
view = api("/api/estimate?season=2026")
for e in view.get("estimates", []):
    api(f"/api/estimate/{e['id']}", method="DELETE")
forecast = {"season_year": 2026, "built_at": (datetime(2026, 10, 2, 4)).isoformat() + "Z", "favorable_kg": 71800, "expected_kg": 64900, "unfavorable_kg": 57200, "live": True, "settled": 2}
e1 = api("/api/estimate", {"season_year": 2026, "name": "Early look, 2 Oct", "notes": "Walked blocks 8a and 9 after the first set. Fruit count looks like last year, slightly smaller.", "forecast": forecast})
api(f"/api/estimate/{e1['id']}", {"lines": [{"block_id": b, "trees": t, "kg_per_tree": round(BLOCK_BASE[b] * 1.0, 1)} for b, t in BLOCKS.items()]}, method="PUT")
forecast2 = {**forecast, "built_at": datetime(2026, 10, 24, 4).isoformat() + "Z", "favorable_kg": 72600, "expected_kg": 66100, "unfavorable_kg": 58900, "settled": 3}
e2 = api("/api/estimate", {"season_year": 2026, "name": "After the October rain", "notes": "Sizing is ahead of 2025 on the Mauritius blocks. Holding 8b and 10 at last year.", "copy_from_id": e1["id"], "forecast": forecast2})
api(f"/api/estimate/{e2['id']}", {
    "lines": [{"block_id": "7", "trees": 420, "kg_per_tree": 36.0}, {"block_id": "8a", "trees": 380, "kg_per_tree": 45.5, "note": "Heavy set, good size"},
              {"block_id": "8b", "trees": 350, "kg_per_tree": 31.0}, {"block_id": "9", "trees": 460, "kg_per_tree": 40.0}, {"block_id": "10", "trees": 300, "kg_per_tree": 27.0, "note": "Hail damage on the west rows"}],
    "pack": [{"channel": "Export sea", "pack_type": "1kg XXL", "kg_per_carton": 1.1, "share_pct": 28}, {"channel": "Export sea", "pack_type": "1kg XL", "kg_per_carton": 1.1, "share_pct": 22},
             {"channel": "Export air", "pack_type": "1kg L", "kg_per_carton": 1.1, "share_pct": 10}, {"channel": "Local market", "pack_type": "Pre-pac", "kg_per_carton": 0.48, "share_pct": 25},
             {"channel": "Juice", "pack_type": "", "kg_per_carton": None, "share_pct": 12}, {"channel": "Rejects", "pack_type": "", "kg_per_carton": None, "share_pct": 3}]}, method="PUT")
print("estimates", e1["id"], e2["id"])
