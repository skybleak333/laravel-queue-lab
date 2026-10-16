"""Draw the repository's social preview (1280x640) with the three findings of the experiments.

Output: docs/social-preview.svg and .png (rasterized with rsvg-convert). Upload the PNG in the
repository settings, under Social preview.
"""

import subprocess
from pathlib import Path

HERE = Path(__file__).parent
OUT = HERE.parent / "social-preview"

W, H = 1280, 640
FONT = "Helvetica, Arial, sans-serif"
INK, GREY, BLUE, RED = "#111827", "#6B7280", "#2563EB", "#DC2626"

FINDINGS = [
    ("A job outlives the ack timeout:", "the worker exits, and the job runs twice"),
    ("The broker freezes:", "with default timeouts, queue:work waits even with heartbeats on"),
    ("php artisan down:", "rabbitmq:consume ignores SIGTERM until artisan up"),
]


def text(x, y, s, size=30, weight="normal", fill=INK, anchor="start"):
    s = s.replace("&", "&amp;").replace("<", "&lt;")
    return (f'<text x="{x}" y="{y}" font-family="{FONT}" font-size="{size}" '
            f'font-weight="{weight}" fill="{fill}" text-anchor="{anchor}">{s}</text>')


svg = [f'<svg xmlns="http://www.w3.org/2000/svg" width="{W}" height="{H}" viewBox="0 0 {W} {H}">',
       f'<rect width="{W}" height="{H}" fill="#FFFFFF"/>',
       f'<rect x="80" y="64" width="330" height="48" rx="24" fill="#EEF2FF"/>',
       text(104, 97, "laravel-queue-lab", 26, "bold", BLUE),
       text(80, 196, "What Laravel queue workers do", 58, "bold"),
       text(80, 262, "when RabbitMQ misbehaves", 58, "bold")]

y = 334
for title, result in FINDINGS:
    svg.append(f'<rect x="80" y="{y - 26}" width="8" height="70" rx="3" fill="{RED}"/>')
    svg.append(text(108, y, title, 30, "bold"))
    svg.append(text(108, y + 38, result, 28, fill=GREY))
    y += 88

svg.append(text(80, 606, "Reproducible PHPUnit experiments on a real broker · CI on PHP 8.3–8.5", 24, fill=GREY))
svg.append("</svg>")

OUT.with_suffix(".svg").write_text("\n".join(svg) + "\n")
subprocess.run(["rsvg-convert", "-w", str(W), "-h", str(H), "-o", str(OUT.with_suffix(".png")), str(OUT.with_suffix(".svg"))], check=True)
print(OUT.with_suffix(".png"))
