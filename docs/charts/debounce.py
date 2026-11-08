"""Draw the debounce maxWait timeline (1080x680) from the laravel-queue-lab event logs.

Data: docs/data/debounce/*.jsonl, two local runs of experiment 4 on 8 Nov 2026: Laravel 13.37
as released, and the same run with the fix from
https://github.com/skybleak333/framework/tree/fix/debounce-forced-job applied to vendor/.
Output: docs/debounce.svg and .png (rasterized with rsvg-convert).
"""

import json
import subprocess
from pathlib import Path

HERE = Path(__file__).parent
DATA = HERE.parent / "data" / "debounce"
OUT = HERE.parent / "debounce"

W, H = 1080, 680
LEFT, RIGHT = 72, 1008
T1 = 66  # seconds shown
FONT = "Helvetica, Arial, sans-serif"
INK, GREY, LIGHT, BLUE, RED = "#111827", "#6B7280", "#D1D5DB", "#2563EB", "#DC2626"

ROWS = [
    ("laravel-13.37", "Laravel 13.37"),
    ("with-fix", "With the fix"),
]


def load(label, part):
    lines = (DATA / f"{label}.{part}.jsonl").read_text().splitlines()
    return [json.loads(line) for line in lines if line.strip()]


def x(seconds):
    return LEFT + (RIGHT - LEFT) * seconds / T1


def text(x_, y, s, size=26, weight="normal", fill=INK, anchor="start"):
    s = s.replace("&", "&amp;").replace("<", "&lt;")
    return (f'<text x="{x_:.1f}" y="{y:.1f}" font-family="{FONT}" font-size="{size}" '
            f'font-weight="{weight}" fill="{fill}" text-anchor="{anchor}">{s}</text>')


def cross(cx, cy, size=9, color=RED):
    return (f'<path d="M{cx - size:.1f},{cy - size:.1f} L{cx + size:.1f},{cy + size:.1f} '
            f'M{cx - size:.1f},{cy + size:.1f} L{cx + size:.1f},{cy - size:.1f}" '
            f'stroke="{color}" stroke-width="4" stroke-linecap="round"/>')


def dot(cx, cy, color=BLUE):
    return f'<circle cx="{cx:.1f}" cy="{cy:.1f}" r="10" fill="{color}" stroke="#FFFFFF" stroke-width="2"/>'


svg = [f'<svg xmlns="http://www.w3.org/2000/svg" width="{W}" height="{H}" viewBox="0 0 {W} {H}">',
       f'<rect width="{W}" height="{H}" fill="#FFFFFF"/>',
       text(72, 96, "Debounce with maxWait 6 s:", 48, "bold"),
       text(72, 152, "forced runs get discarded", 40, "bold"),
       text(72, 200, "Dispatch every 0.5 s for 60 s, #[DebounceFor(2, maxWait: 6)], queue:work --sleep=3", 22, fill=GREY)]

top = 300
step = 170
for i, (label, title) in enumerate(ROWS):
    y = top + i * step
    dispatches = [e for e in load(label, "dispatch") if e["event"] == "debounce.dispatch"]
    worker = load(label, "worker")
    t0 = dispatches[0]["time"]
    runs = [e["time"] - t0 for e in worker if e["event"] == "debounce.run"]
    discarded = [e["time"] - t0 for e in worker if e["event"] == "debounce.discarded" and e["forced"]]
    end = dispatches[-1]["time"] - t0
    during = [t for t in runs if t <= end]
    points = [0.0] + during + [end]
    longest = max(b - a for a, b in zip(points, points[1:]))

    summary = (f"{len(discarded)} forced runs discarded, {len(during)} runs while dispatching, "
               f"longest wait {longest:.0f} s")
    svg.append(text(72, y - 44, title, 28, "bold"))
    svg.append(text(72, y - 12, summary, 22, fill=GREY))
    y += 40
    svg.append(f'<line x1="{x(0):.1f}" y1="{y:.1f}" x2="{x(end):.1f}" y2="{y:.1f}" stroke="{LIGHT}" stroke-width="2"/>')
    for e in dispatches:
        t = e["time"] - t0
        svg.append(f'<rect x="{x(t) - 1:.1f}" y="{y - 9:.1f}" width="2" height="18" fill="{LIGHT}"/>')
    for t in discarded:
        if t <= T1:
            svg.append(cross(x(t), y))
    for t in runs:
        if t <= T1:
            svg.append(dot(x(t), y))

axis_y = top + (len(ROWS) - 1) * step + 40 + 56
svg.append(f'<line x1="{LEFT}" y1="{axis_y}" x2="{RIGHT}" y2="{axis_y}" stroke="{LIGHT}" stroke-width="2"/>')
for s in range(0, T1 + 1, 10):
    svg.append(f'<line x1="{x(s):.1f}" y1="{axis_y}" x2="{x(s):.1f}" y2="{axis_y + 8}" stroke="{GREY}" stroke-width="2"/>')
    svg.append(text(x(s), axis_y + 34, f"{s} s", 20, fill=GREY, anchor="middle"))

legend_y = axis_y + 80
svg.append(dot(LEFT + 10, legend_y - 8))
svg.append(text(LEFT + 32, legend_y, "job ran", 22, fill=INK))
svg.append(cross(LEFT + 190, legend_y - 8))
svg.append(text(LEFT + 212, legend_y, "forced job discarded as superseded", 22, fill=INK))
svg.append(f'<rect x="{LEFT + 619:.1f}" y="{legend_y - 17:.1f}" width="2" height="18" fill="{LIGHT}"/>')
svg.append(text(LEFT + 632, legend_y, "dispatch", 22, fill=INK))
svg.append("</svg>")

OUT.with_suffix(".svg").write_text("\n".join(svg) + "\n")
subprocess.run(["rsvg-convert", "-o", str(OUT.with_suffix(".png")), str(OUT.with_suffix(".svg"))], check=True)
print("wrote", OUT.with_suffix(".svg"), OUT.with_suffix(".png"))
