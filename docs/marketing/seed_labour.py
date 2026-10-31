"""Demo scans for Boord Labour: this week's badge scans at the door for the
eight demo workers, with one forgotten scan-out, plus a pay rate."""
import json, random, sqlite3, urllib.request, uuid
from datetime import datetime, timedelta, timezone, date
random.seed(11)
BASE = "http://localhost:8040"
SAST = timezone(timedelta(hours=2))
con = sqlite3.connect("/home/user/Boord-labour/data/labour.db")
con.execute("DELETE FROM payrate")
con.execute("INSERT INTO payrate (effective_date, hourly_rate, ot_multiplier, daily_ot_after_h, weekly_ot_after_h) VALUES ('2026-01-01', 32.5, 1.5, 9, 45)")
con.commit(); con.close()
WORKERS = ["001", "002", "003", "004", "005", "006", "007", "008"]
def ts(d, h, m): return datetime(d.year, d.month, d.day, h, m, random.randint(0, 59), tzinfo=SAST).astimezone(timezone.utc).replace(tzinfo=None).isoformat()
scans = []
def scan(w, d, h, m, direction, dev="Door phone"):
    scans.append({"uuid": f"demo-{w}-{d.isoformat()}-{direction}-{uuid.uuid4().hex[:6]}", "worker_id": w, "timestamp": ts(d, h, m), "direction": direction, "device_label": dev})
today = date(2026, 10, 31)
for i in range(5, 0, -1):          # Mon 26 .. Fri 30 Oct, and last week too
    d = today - timedelta(days=i)
    for w in WORKERS:
        if d.weekday() == 6: continue
        if w == "006" and d.weekday() == 2: continue           # Lindiwe off on Wednesday
        scan(w, d, 6, random.randint(48, 59) if random.random() < 0.7 else random.randint(0, 12), "in")
        late = d.weekday() in (1, 3) and w in ("001", "002", "004")   # packing late on Tue and Thu
        if w == "005" and d.weekday() == 2: continue             # Anna forgot to scan out on Wednesday
        scan(w, d, 18 if late else 16, random.randint(5, 40) if late else random.randint(28, 58), "out")
for i in range(12, 5, -1):          # last week
    d = today - timedelta(days=i)
    if d.weekday() == 6: continue
    for w in WORKERS:
        scan(w, d, 7, random.randint(0, 10), "in"); scan(w, d, 16, random.randint(30, 59), "out")
# Today (Saturday): a half day, most still inside, one already out.
for w in WORKERS[:6]:
    scan(w, today, 7, random.randint(0, 12), "in")
scan("003", today, 12, 5, "out")
req = urllib.request.Request(BASE + "/api/scans/sync", data=json.dumps({"scans": scans}).encode(), headers={"Content-Type": "application/json"})
print(len(scans), "scans;", urllib.request.urlopen(req, timeout=30).read()[:120])
