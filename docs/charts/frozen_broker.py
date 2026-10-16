"""Draw the frozen broker timeline (1080x1350) from the laravel-queue-lab event logs.

Data: docs/data/frozen-broker/ (results.json and *.worker.jsonl), one local run
of experiment 2 in https://github.com/skybleak333/laravel-queue-lab on 16 Oct 2026.
Output: docs/frozen-broker.svg and .png (rasterized with rsvg-convert).
"""

import json
import subprocess
from pathlib import Path

HERE = Path(__file__).parent
DATA = HERE.parent / "data" / "frozen-broker"
OUT = HERE.parent / "frozen-broker"

W, H = 1080, 1350
LEFT, RIGHT = 72, 1008           # timeline area, 0..FREEZE seconds after the freeze
FONT = "Helvetica, Arial, sans-serif"
INK, GREY, BLUE, AMBER, RED = "#111827", "#6B7280", "#2563EB", "#F59E0B", "#DC2626"

ROWS = [
    ("work", "queue:work", ""),
    ("work-max-time", "queue:work", "--max-time=60"),
    ("work-rpc-timeout", "queue:work", "channel_rpc_timeout 10 s"),
    ("work-rpc-timeout-no-heartbeat", "queue:work", "channel_rpc_timeout 10 s, no heartbeat"),
    ("consume", "rabbitmq:consume", ""),
    ("consume-max-time", "rabbitmq:consume", "--max-time=60"),
    ("consume-max-time-rpc-timeout", "rabbitmq:consume", "--max-time=60, channel_rpc_timeout 10 s"),
]

results = json.loads((DATA / "results.json").read_text())
FREEZE = results["freeze"]
paused = results["paused"]


def events(name):
    lines = (DATA / f"{name}.worker.jsonl").read_text().splitlines()
    log = [json.loads(line) for line in lines if line.strip()]
    return [dict(e, t=e["time"] - paused) for e in log if 0 <= e["time"] - paused <= FREEZE]


def first(log, event):
    return next((e for e in log if e["event"] == event), None)


def x(seconds):
    return LEFT + (RIGHT - LEFT) * seconds / FREEZE


def text(x_, y, s, size=30, weight="normal", fill=INK, anchor="start"):
    s = s.replace("&", "&amp;").replace("<", "&lt;")
    return (f'<text x="{x_:.1f}" y="{y:.1f}" font-family="{FONT}" font-size="{size}" '
            f'font-weight="{weight}" fill="{fill}" text-anchor="{anchor}">{s}</text>')


def bar(y, start, end, color, height=26, opacity=1.0):
    return (f'<rect x="{x(start):.1f}" y="{y - height / 2:.1f}" width="{x(end) - x(start):.1f}" '
            f'height="{height}" rx="6" fill="{color}" fill-opacity="{opacity}"/>')


def tick(y, at_s, color, height=40):
    return f'<rect x="{x(at_s) - 5:.1f}" y="{y - height / 2:.1f}" width="10" height="{height}" rx="3" fill="{color}"/>'


svg = [f'<svg xmlns="http://www.w3.org/2000/svg" width="{W}" height="{H}" viewBox="0 0 {W} {H}">',
       f'<rect width="{W}" height="{H}" fill="#FFFFFF"/>',
       f'<rect x="72" y="62" width="420" height="50" rx="25" fill="#EEF2FF"/>',
       text(96, 96, "TESTED ON A REAL BROKER", 24, "bold", BLUE),
       text(72, 190, f"RabbitMQ froze for {FREEZE:.0f} s.", 60, "bold"),
       text(72, 262, "Only two of seven workers exited", 52, "bold"),
       text(72, 316, "Heartbeat 15 s, Laravel 13.37, php-amqplib 3.7.5, RabbitMQ 3.13.3", 26, fill=GREY)]

top = 392
step = 118
for i, (name, command, options) in enumerate(ROWS):
    y = top + i * step
    run = results["scenarios"][name]
    log = events(name)
    svg.append(text(72, y, command, 28, "bold"))
    if options:
        svg.append(text(72 + 16 * len(command) + 14, y, options, 24, fill=GREY))

    bar_y = y + 32
    stopping = first(log, "worker.stopping")
    timeouts = [e["t"] for e in log if e["event"] == "exception" and e.get("class", "").endswith("AMQPTimeoutException")]
    exited = run["exitedAfter"] is not None and run["exitedAfter"] < FREEZE

    if exited:
        svg.append(bar(bar_y, 0, run["exitedAfter"], BLUE, opacity=0.85))
    else:
        svg.append(bar(bar_y, 0, FREEZE, RED, opacity=0.22))
    for t in timeouts:
        svg.append(tick(bar_y, t, GREY, height=30))
    if stopping and stopping.get("reason") == "MaxTimeExceeded":
        svg.append(tick(bar_y, stopping["t"], AMBER))

    if exited:
        svg.append(tick(bar_y, run["exitedAfter"], INK))
        if stopping and stopping.get("reason") == "LostConnection":
            note = f"missed heartbeats: exited at {run['exitedAfter']:.0f} s, code {run['exitCode']}"
        else:
            note = f"--max-time at {stopping['t']:.0f} s, exited at {run['exitedAfter']:.0f} s with an error"
        svg.append(text(72, bar_y + 46, note, 22, "bold", BLUE))
    else:
        if stopping and stopping.get("reason") == "MaxTimeExceeded":
            note = f"--max-time at {stopping['t']:.0f} s, but the process didn't exit"
        elif timeouts:
            note = "a timeout every 14 s, kept running"
        elif name.startswith("work"):
            note = "waited until the broker was back"
        else:
            note = "kept running, nothing logged"
        svg.append(text(72, bar_y + 46, note, 22, "bold", RED))

axis_y = top + (len(ROWS) - 1) * step + 32 + 46 + 34   # below the last note
svg.append(f'<line x1="{LEFT}" y1="{axis_y}" x2="{RIGHT}" y2="{axis_y}" stroke="{GREY}" stroke-width="2"/>')
for s in range(0, 101, 20):
    svg.append(f'<line x1="{x(s):.1f}" y1="{axis_y}" x2="{x(s):.1f}" y2="{axis_y + 10}" stroke="{GREY}" stroke-width="2"/>')
    svg.append(text(x(s), axis_y + 38, f"{s} s", 22, fill=GREY, anchor="middle"))

svg.append(text(72, 1318, "One local run of github.com/skybleak333/laravel-queue-lab, 16 Oct 2026", 22, fill=GREY))
svg.append("</svg>")

OUT.with_suffix(".svg").write_text("\n".join(svg) + "\n")
subprocess.run(["rsvg-convert", "-w", str(W), "-h", str(H), "-o", str(OUT.with_suffix(".png")), str(OUT.with_suffix(".svg"))], check=True)
print(OUT.with_suffix(".png"))
