"""Draw the paused worker timeline (1080x1350) from the laravel-queue-lab event logs.

Data: docs/data/paused-worker/ (results.json and *.worker.jsonl), one local run of
experiment 3 in https://github.com/skybleak333/laravel-queue-lab on 16 Oct 2026.
Output: docs/paused-worker.svg and .png (rasterized with rsvg-convert).
"""

import json
import subprocess
from pathlib import Path

HERE = Path(__file__).parent
DATA = HERE.parent / "data" / "paused-worker"
OUT = HERE.parent / "paused-worker"

W, H = 1080, 1350
LEFT, RIGHT = 72, 1008
T0, T1 = -8, 26                  # seconds around the stop signal
FONT = "Helvetica, Arial, sans-serif"
INK, GREY, BLUE, AMBER, RED = "#111827", "#6B7280", "#2563EB", "#F59E0B", "#DC2626"

# name, command, situation label, situation key for the release time
ROWS = [
    ("work-down-sigterm", "queue:work", "artisan down, then SIGTERM", "down-sigterm"),
    ("consume-down-sigterm", "rabbitmq:consume", "artisan down, then SIGTERM", "down-sigterm"),
    ("consume-force-down-sigterm", "rabbitmq:consume --force", "artisan down, then SIGTERM", "down-sigterm"),
    ("work-sigusr2-sigterm", "queue:work", "SIGUSR2, then SIGTERM", "sigusr2-sigterm"),
    ("consume-sigusr2-sigterm", "rabbitmq:consume", "SIGUSR2, then SIGTERM", "sigusr2-sigterm"),
    ("work-down-restart", "queue:work", "artisan down, then queue:restart", "down-restart"),
    ("consume-down-restart", "rabbitmq:consume", "artisan down, then queue:restart", "down-restart"),
]

results = json.loads((DATA / "results.json").read_text())
signalled = results["signalledAt"]
held = results["held"]
RELEASE_LABEL = {"down-sigterm": "artisan up", "down-restart": "artisan up", "sigusr2-sigterm": "SIGCONT"}


def events(name):
    lines = (DATA / f"{name}.worker.jsonl").read_text().splitlines()
    return [dict(e, t=e["time"] - signalled) for e in (json.loads(line) for line in lines if line.strip())]


def x(seconds):
    return LEFT + (RIGHT - LEFT) * (seconds - T0) / (T1 - T0)


def text(x_, y, s, size=30, weight="normal", fill=INK, anchor="start"):
    s = s.replace("&", "&amp;").replace("<", "&lt;")
    return (f'<text x="{x_:.1f}" y="{y:.1f}" font-family="{FONT}" font-size="{size}" '
            f'font-weight="{weight}" fill="{fill}" text-anchor="{anchor}">{s}</text>')


def bar(y, start, end, color, height=24, opacity=1.0):
    return (f'<rect x="{x(start):.1f}" y="{y - height / 2:.1f}" width="{x(end) - x(start):.1f}" '
            f'height="{height}" rx="6" fill="{color}" fill-opacity="{opacity}"/>')


def tick(y, at_s, color, height=38):
    return f'<rect x="{x(at_s) - 5:.1f}" y="{y - height / 2:.1f}" width="10" height="{height}" rx="3" fill="{color}"/>'


svg = [f'<svg xmlns="http://www.w3.org/2000/svg" width="{W}" height="{H}" viewBox="0 0 {W} {H}">',
       f'<rect width="{W}" height="{H}" fill="#FFFFFF"/>',
       f'<rect x="72" y="62" width="420" height="50" rx="25" fill="#EEF2FF"/>',
       text(96, 96, "TESTED ON A REAL BROKER", 24, "bold", BLUE),
       text(72, 190, "SIGTERM during artisan down:", 56, "bold"),
       text(72, 254, "rabbitmq:consume waits for artisan up", 44, "bold"),
       text(72, 312, "Laravel 13.37, RabbitMQ 3.13.3, --sleep=3. 0 s = SIGTERM or queue:restart", 24, fill=GREY)]

top = 396
step = 116
axis_y = top + (len(ROWS) - 1) * step + 30 + 44 + 34


for i, (name, command, situation, key) in enumerate(ROWS):
    y = top + i * step
    log = events(name)
    run = results["workers"][name]
    release = results["released"][key]
    stopped = run["stoppedAfter"]

    svg.append(text(72, y, command, 26, "bold"))
    svg.append(text(72 + 15 * len(command) + 14, y, situation, 22, fill=GREY))

    bar_y = y + 30
    # the stop signal, at 0 s
    svg.append(f'<line x1="{x(0):.1f}" y1="{bar_y - 22:.1f}" x2="{x(0):.1f}" y2="{bar_y + 22:.1f}" stroke="{INK}" stroke-width="2" stroke-dasharray="4 4"/>')
    color = BLUE if stopped < release else RED
    svg.append(bar(bar_y, held, stopped, color, opacity=0.85 if color == BLUE else 0.25))
    svg.append(tick(bar_y, release, AMBER))
    svg.append(tick(bar_y, stopped, INK))

    jobs = [e for e in log if e["event"] == "job.start" and e["job"].startswith("Y")]
    for job in jobs:
        svg.append(f'<circle cx="{x(job["t"]):.1f}" cy="{bar_y:.1f}" r="9" fill="{RED}"/>')

    if stopped < release:
        note = "stops at once" if stopped < 0.5 else f"stops after {stopped:.0f} s, before the hold ends"
        if name == "work-down-restart":
            note = f"stops after {stopped:.0f} s and leaves Y1-Y3 in the queue"
        svg.append(text(72, bar_y + 44, note, 22, "bold", BLUE))
    else:
        note = f"stops {stopped - release:.0f} s after {RELEASE_LABEL[key]}"
        if jobs:
            note = f"runs Y1 after {RELEASE_LABEL[key]}, then stops; Y2, Y3 go back"
        svg.append(text(72, bar_y + 44, note, 22, "bold", RED))

svg.append(f'<line x1="{LEFT}" y1="{axis_y}" x2="{RIGHT}" y2="{axis_y}" stroke="{GREY}" stroke-width="2"/>')
for s in range(-5, T1 + 1, 5):
    svg.append(f'<line x1="{x(s):.1f}" y1="{axis_y}" x2="{x(s):.1f}" y2="{axis_y + 10}" stroke="{GREY}" stroke-width="2"/>')
    svg.append(text(x(s), axis_y + 38, f"{s} s", 22, fill=GREY, anchor="middle"))

svg.append(f'<rect x="{LEFT}" y="{axis_y + 62}" width="16" height="16" rx="3" fill="{AMBER}"/>')
svg.append(text(LEFT + 26, axis_y + 77, "artisan up or SIGCONT", 22, fill=GREY))
svg.append(f'<circle cx="{LEFT + 360:.1f}" cy="{axis_y + 70:.1f}" r="8" fill="{RED}"/>')
svg.append(text(LEFT + 376, axis_y + 77, "a job that came in during the deploy", 22, fill=GREY))

svg.append(text(72, 1318, "One local run of github.com/skybleak333/laravel-queue-lab, 16 Oct 2026", 22, fill=GREY))
svg.append("</svg>")

OUT.with_suffix(".svg").write_text("\n".join(svg) + "\n")
subprocess.run(["rsvg-convert", "-w", str(W), "-h", str(H), "-o", str(OUT.with_suffix(".png")), str(OUT.with_suffix(".svg"))], check=True)
print(OUT.with_suffix(".png"))
