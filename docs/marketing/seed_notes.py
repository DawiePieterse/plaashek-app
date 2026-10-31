"""Demo notebook for Boord Notes: blocks, notes with actions, tags, weather
and a few generated photos, posted through the normal API on :8020."""
import io, json, random, urllib.request, uuid
from datetime import datetime, timedelta, timezone
from PIL import Image, ImageDraw, ImageFilter
random.seed(3)
BASE = "http://localhost:8020"
def api(path, body=None, method=None):
    req = urllib.request.Request(BASE + path, data=json.dumps(body).encode() if body is not None else None,
                                 headers={"Content-Type": "application/json"}, method=method or ("POST" if body is not None else "GET"))
    return json.loads(urllib.request.urlopen(req, timeout=30).read() or "null")
def upload(entry_id, img, caption=""):
    buf = io.BytesIO(); img.save(buf, "JPEG", quality=85); data = buf.getvalue()
    b = b"--XX\r\nContent-Disposition: form-data; name=\"file\"; filename=\"p.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n" + data + b"\r\n--XX--\r\n"
    req = urllib.request.Request(f"{BASE}/api/entries/{entry_id}/photos?caption={urllib.parse.quote(caption)}", data=b, headers={"Content-Type": "multipart/form-data; boundary=XX"})
    return urllib.request.urlopen(req, timeout=30).read()
import urllib.parse

for b in api("/api/blocks")["blocks"]:
    api(f"/api/blocks/{b['id']}", method="DELETE")
for name, var in [("Block 7", "Mauritius"), ("Block 8a", "Mauritius"), ("Block 8b", "McLean's Red"), ("Block 9", "Mauritius"), ("Block 10", "McLean's Red"), ("Pump station", ""), ("Pack house", "")]:
    api("/api/blocks", {"name": name, "variety": var})

NOW = datetime(2026, 10, 31, 12, 44)
def at(days_ago, h, m=0): return (NOW - timedelta(days=days_ago)).replace(hour=h, minute=m) - timedelta(hours=2)
def act(kind, detail="", status="todo", done=None, due=None):
    a = {"id": uuid.uuid4().hex[:12], "kind": kind, "detail": detail, "status": status}
    if done is not None: a["done_at"] = done.isoformat(); a["status"] = "done"
    if due: a["due_on"] = due
    return a
def wx(t, h, c): return {"weather_temp": t, "weather_humidity": h, "weather_condition": c}
gps = lambda: {"latitude": -25.45 + random.uniform(-0.01, 0.01), "longitude": 30.95 + random.uniform(-0.01, 0.01), "location_accuracy_m": random.choice([5, 8, 12])}

NOTES = [
 dict(title="Flush on 8a needs pruning", block="Block 8a", days=1, h=7, m=20, tags=["Block Maintenance", "Fruit Development & Ripening"],
      body="Walked 8a this morning. Strong vegetative flush on the north rows, about 20 cm, coming through the fruit. If we leave it the fruit will stay small. Prune the flush out this week before the heat. Fruit on the south side is colouring nicely already.",
      actions=[act("Prune", "flush on north rows, hand pruners"), act("Scout", "check south rows Friday for colour", due="2026-11-06")], weather=wx(21.5, 71, "Partly Cloudy"), photo=("flush", "Flush on the north rows")),
 dict(title="Fruit fly bait station refill", block="Block 9", days=2, h=9, m=5, tags=["Crop Health & Pests"],
      body="Bait stations on the 9 boundary are dry. Refilled 14 of them. Counted 3 flies in the trap by the dam side, none on the road side. Keep an eye on it, two weeks from picking.",
      actions=[act("Scout", "count traps again Monday", due="2026-11-02")], weather=wx(24.0, 58, "Clear")),
 dict(title="Pump station pressure drop", block="Pump station", days=3, h=16, m=40, tags=["Equipment & Maintenance"],
      body="Pressure dropped to 2.8 bar on the main line at about four this afternoon. Found the filter on pump 2 half blocked with algae. Cleaned it, back to 3.6. The filter needs cleaning every week now that the dam is low.",
      actions=[act("Water", "clean pump 2 filter weekly", due="2026-11-05")], weather=wx(27.0, 44, "Clear"), photo=("pump", "Pump 2 filter before cleaning")),
 dict(title="Irrigation 8b and 10 after the rain", block="Block 8b", days=4, h=6, m=50, tags=["Weather & Climate Impact"],
      body="22 mm last night. Skip irrigation on 8b and 10 for two days, soil is wet to 30 cm. Check 7 on Wednesday, it drains faster.",
      actions=[act("Water", "resume Thursday if dry", done=at(2, 6, 30))], weather=wx(18.0, 92, "Rain")),
 dict(title="Picking start estimate", block="Block 8a", days=6, h=10, m=15, tags=["Harvest Observations", "Fruit Development & Ripening"],
      body="Brix on 8a south side 17 to 18 on the ten fruit I tested. Colour 70 percent. We can start a light pick on the south rows in about 10 days. The north rows are a week behind.",
      actions=[act("Pick", "light pick south rows first", due="2026-11-09")], weather=wx(25.5, 52, "Clear"), photo=("fruit", "8a south side, colouring")),
 dict(title="Fertilise 7 after picking last year worked", block="Block 7", days=8, h=14, tags=["Ideas & Improvements", "Block Maintenance"],
      body="The potassium after picking last December made a visible difference on 7 this year, bigger fruit and less drop. Do the same on 9 this year. 300 g per tree.",
      actions=[act("Fertilise", "potassium sulphate 300 g/tree on 9 after picking", due="2026-12-20")], weather=wx(29.0, 40, "Clear")),
 dict(title="Spray record 8b", block="Block 8b", days=11, h=6, m=30, tags=["Crop Health & Pests"],
      body="Copper oxychloride on 8b for the anthracnose spots we saw last week, 2 kg per ha, 1500 l per ha. Wind was calm, finished by nine. Withholding 14 days.",
      actions=[act("Spray", "copper oxychloride 2 kg/ha", done=at(11, 9, 0))], weather=wx(16.0, 78, "Partly Cloudy")),
 dict(title="Worker team on 9 picking slow", block="Block 9", days=12, h=15, m=10, tags=["Worker & Team Notes"],
      body="Span B picked 9 at 30 crates per person today against 45 on 8a. The crates on 9 are a long carry to the road. Put the trailer at the middle break next time.",
      actions=[act("Scout", "trailer at middle break for 9", done=at(10, 7, 0))], weather=wx(30.0, 38, "Clear")),
 dict(title="Hail on 10 west rows", block="Block 10", days=15, h=17, m=25, tags=["Weather & Climate Impact", "Safety & Incidents"],
      body="Short hail storm at five, pea size, about ten minutes. West four rows of 10 have marked fruit, maybe 15 percent. Rest of the farm untouched. Photo of the worst tree.",
      actions=[act("Scout", "count marked fruit on 10 in a week", done=at(8, 8, 0), ), act("Spray", "copper on 10 west rows for wound protection", done=at(14, 7, 0))], weather=wx(22.0, 85, "Storm"), photo=("hail", "West row of 10 after the hail")),
 dict(title="Sulfur tent timing", block="Pack house", days=18, h=11, tags=["Quality & Post-Harvest"],
      body="Tent A at 60 minutes on 425 g gave good colour hold on the XL fruit. Tent B at 55 was slightly pale after three days in the cold room. Keep 60 for XL.",
      actions=[], weather=wx(26.0, 55, "Partly Cloudy")),
 dict(title="New windbreak for 8b", block="Block 8b", days=24, h=8, tags=["Ideas & Improvements"],
      body="The east side of 8b takes the wind every October. Plant casuarina along the road fence this winter. About 40 trees.",
      actions=[act("Water", "casuarina seedlings, order 40", due="2027-05-15")], weather=wx(20.0, 60, "Clear")),
 dict(title="Dam level", block="Pump station", days=30, h=7, m=45, tags=["General Observations", "Weather & Climate Impact"],
      body="Dam at 62 percent, down from 71 a month ago. If November is dry we cut the night cycle on 7 first.",
      actions=[], weather=wx(17.5, 66, "Partly Cloudy")),
 dict(title="Scale on 7 road side", block="Block 7", days=38, h=9, m=30, tags=["Crop Health & Pests"],
      body="Soft scale on about ten trees on the road side of 7, ants farming it. Oil spray and ant bait at the trunks.",
      actions=[act("Spray", "mineral oil 1 percent, road side trees only", done=at(35, 7, 0)), act("Scout", "ant bait at trunks", done=at(35, 9, 0))], weather=wx(23.0, 50, "Clear")),
 dict(title="Pruning done on 9", block="Block 9", days=60, h=16, tags=["Block Maintenance"],
      body="Finished skirting and opening the centres on 9 today, four days with six people. Rows 1 to 12 heavier, the rest light.",
      actions=[act("Prune", "skirt and open centres", done=at(60, 16, 0))], weather=wx(21.0, 48, "Clear")),
 dict(title="Flowering on 8a and 8b", block="Block 8a", days=85, h=8, m=10, tags=["Fruit Development & Ripening"],
      body="Full flower on 8a, bees working well. 8b about a week behind. Good set if the wind stays down.",
      actions=[], weather=wx(19.0, 55, "Clear"), photo=("flower", "8a in full flower")),
]

def picture(kind, w=900, h=675):
    im = Image.new("RGB", (w, h))
    d = ImageDraw.Draw(im)
    sky = {"flush": (170, 200, 230), "fruit": (190, 210, 230), "pump": (120, 120, 125), "hail": (140, 150, 165), "flower": (185, 205, 235)}[kind]
    for y in range(h):
        t = y / h
        d.line([(0, y), (w, y)], fill=tuple(int(sky[i] * (1 - t) + (60, 90, 40)[i] * t) for i in range(3)))
    for _ in range(90):
        x, y, r = random.randint(0, w), random.randint(h // 3, h), random.randint(20, 70)
        g = {"flush": (120, 170, 60), "fruit": (40, 95, 35), "pump": (70, 75, 80), "hail": (50, 100, 45), "flower": (60, 110, 50)}[kind]
        d.ellipse([x - r, y - r, x + r, y + r], fill=tuple(c + random.randint(-20, 20) for c in g))
    if kind in ("fruit", "hail"):
        for _ in range(60):
            x, y, r = random.randint(50, w - 50), random.randint(h // 2, h - 40), random.randint(6, 12)
            d.ellipse([x - r, y - r, x + r, y + r], fill=(200 + random.randint(-30, 20), 40 + random.randint(0, 40), 50))
    if kind == "flower":
        for _ in range(300):
            x, y = random.randint(0, w), random.randint(h // 3, h - 60)
            d.ellipse([x - 3, y - 3, x + 3, y + 3], fill=(235, 225, 170))
    if kind == "pump":
        d.rectangle([w // 3, h // 2, 2 * w // 3, h - 30], fill=(60, 80, 150)); d.ellipse([w // 2 - 60, h // 2 - 120, w // 2 + 60, h // 2], fill=(90, 90, 95))
    return im.filter(ImageFilter.GaussianBlur(1.2))

for n in NOTES:
    created = at(n["days"], n["h"], n.get("m", 0))
    eid = uuid.uuid4().hex[:16]
    api("/api/entries", {"id": eid, "title": n["title"], "body": n["body"], "block": n["block"], "tags": n["tags"],
                         "actions": n["actions"], "created_at": created.isoformat(), **gps(), **n["weather"]})
    if n.get("photo"):
        upload(eid, picture(n["photo"][0]), n["photo"][1])
print("notes", len(NOTES), api("/api/entries/stats")["total"])
