"""Draw the ack timeout timeline (1080x1350) from the laravel-queue-lab event logs.

Data: docs/data/ack-timeout/*.jsonl, one local run of the experiment in
https://github.com/skybleak333/laravel-queue-lab on 16 Oct 2026.
Output: docs/ack-timeout.svg and .png (rasterized with rsvg-convert).
"""

import json
import subprocess
from pathlib import Path

HERE = Path(__file__).parent
DATA = HERE.parent / "data" / "ack-timeout"
OUT = HERE.parent / "ack-timeout"

W, H = 1080, 1350
LEFT, RIGHT = 280, 1010          # timeline area
SECONDS = 160
FONT = "Helvetica, Arial, sans-serif"
INK, GREY, BLUE, AMBER, RED = "#111827", "#6B7280", "#2563EB", "#F59E0B", "#DC2626"


def events(name):
    lines = (DATA / f"{name}.worker.jsonl").read_text().splitlines()
    log = [json.loads(line) for line in lines if line.strip()]
    t0 = log[0]["time"]
    return [dict(e, t=e["time"] - t0) for e in log]


def at(log, event, **match):
    for e in log:
        if e["event"] == event and all(e.get(k) == v for k, v in match.items()):
            return e["t"]
    raise KeyError((event, match))


def x(seconds):
    return LEFT + (RIGHT - LEFT) * seconds / SECONDS


def text(x_, y, s, size=30, weight="normal", fill=INK, anchor="start"):
    s = s.replace("&", "&amp;").replace("<", "&lt;")
    return (f'<text x="{x_:.1f}" y="{y:.1f}" font-family="{FONT}" font-size="{size}" '
            f'font-weight="{weight}" fill="{fill}" text-anchor="{anchor}">{s}</text>')


def axis(y):
    parts = [f'<line x1="{LEFT}" y1="{y}" x2="{RIGHT}" y2="{y}" stroke="{GREY}" stroke-width="2"/>']
    for s in range(0, SECONDS + 1, 30):
        parts.append(f'<line x1="{x(s):.1f}" y1="{y}" x2="{x(s):.1f}" y2="{y + 10}" stroke="{GREY}" stroke-width="2"/>')
        parts.append(text(x(s), y + 42, f"{s} s", 24, fill=GREY, anchor="middle"))
    return parts


def bar(y, start, end, color, height=34):
    return f'<rect x="{x(start):.1f}" y="{y - height / 2:.1f}" width="{x(end) - x(start):.1f}" height="{height}" rx="6" fill="{color}"/>'


def tick(y, at_s, color, height=46):
    return f'<rect x="{x(at_s) - 5:.1f}" y="{y - height / 2:.1f}" width="10" height="{height}" rx="3" fill="{color}"/>'


consume, work = events("consume"), events("work")

c_a_end = at(consume, "job.end", job="A")
c_b = at(consume, "job.start", job="B")
c_exit = at(consume, "process.exit")
w_a_end = at(work, "job.end", job="A")
w_406 = at(work, "exception", code=406)
w_exit = at(work, "process.exit")

svg = [f'<svg xmlns="http://www.w3.org/2000/svg" width="{W}" height="{H}" viewBox="0 0 {W} {H}">',
       f'<rect width="{W}" height="{H}" fill="#FFFFFF"/>',
       f'<rect x="72" y="62" width="420" height="50" rx="25" fill="#EEF2FF"/>',
       text(96, 96, "TESTED ON A REAL BROKER", 24, "bold", BLUE),
       text(72, 200, "A job longer than RabbitMQ's", 60, "bold"),
       text(72, 274, "ack timeout runs twice", 60, "bold"),
       text(72, 334, "RabbitMQ 3.13.3, consumer_timeout = 60 s, Laravel 13.37", 28, fill=GREY)]

# Panel 1: rabbitmq:consume with the default prefetch.
svg.append(text(72, 440, "php artisan rabbitmq:consume", 34, "bold"))
svg.append(f'<rect x="{x(60):.1f}" y="470" width="{x(120) - x(60):.1f}" height="270" fill="{AMBER}" fill-opacity="0.18"/>')
svg.append(text(x(90), 500, "broker closes the channel", 24, "bold", "#B45309", "middle"))
svg.append(text(x(90), 530, "and puts A, B, C back", 24, fill="#B45309", anchor="middle"))
svg.append(text(72, 590, "Job A", 28, "bold"))
svg.append(bar(580, 0, c_a_end, BLUE))
svg.append(text(72, 660, "Jobs B, C", 28, "bold"))
svg.append(tick(650, c_b, RED))
svg.append(text(x(c_b) - 18, 660, "run on the closed channel", 24, fill=RED, anchor="end"))
svg.append(text(72, 730, "Worker", 28, "bold"))
svg.append(tick(720, c_exit, INK))
svg.append(text(x(c_exit) - 18, 730, "406, exits with code 0", 24, anchor="end"))
svg.extend(axis(770))
svg.append(text(72, 880, "Next start: A, B and C are delivered again.", 30, "bold", RED))

# Panel 2: queue:work.
svg.append(text(72, 980, "php artisan queue:work", 34, "bold"))
svg.append(f'<rect x="{x(60):.1f}" y="1005" width="{x(120) - x(60):.1f}" height="140" fill="{AMBER}" fill-opacity="0.18"/>')
svg.append(text(72, 1050, "Job A", 28, "bold"))
svg.append(bar(1040, 0, w_a_end, BLUE))
svg.append(text(72, 1120, "Worker", 28, "bold"))
svg.append(tick(1110, w_406, INK))
svg.append(tick(1110, w_exit, INK))
svg.append(text(x(w_406) - 18, 1120, "406, lost connection, code 0", 24, anchor="end"))
svg.extend(axis(1160))
svg.append(text(72, 1262, "Next start: A is delivered again.", 30, "bold", RED))

svg.append(text(72, 1318, "One local run of github.com/skybleak333/laravel-queue-lab, 16 Oct 2026", 22, fill=GREY))
svg.append("</svg>")

OUT.with_suffix(".svg").write_text("\n".join(svg) + "\n")
subprocess.run(["rsvg-convert", "-w", str(W), "-h", str(H), "-o", str(OUT.with_suffix(".png")), str(OUT.with_suffix(".svg"))], check=True)
print(OUT.with_suffix(".png"))
