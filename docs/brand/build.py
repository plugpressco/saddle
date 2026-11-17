#!/usr/bin/env python3
"""Builds SaddleWP's brand kit and WordPress.org banner from one place.

Run from the repo root:  python3 docs/brand/build.py   (needs rsvg-convert)
Outputs: docs/brand/*.svg and *.png, and .wordpress.org/src/banner.svg + PNGs.
Flat only: ink, white, magenta (the mark), sun yellow (the second colour).
Never put the word that WordPress.org's trademark rule bans in listing art.
"""
import re, subprocess, pathlib

ROOT = pathlib.Path(__file__).resolve().parents[2]
OUT = ROOT / "docs/brand"
WPO = ROOT / ".wordpress.org"
D = re.search(r'\sd="([^"]+)"', (ROOT / "assets/brand/mark.svg").read_text()).group(1)
INK, PANEL, MUTED, MAG, SUN, WHITE = "#14121a", "#1d1a24", "#a9a4b5", "#d000c4", "#ffcf33", "#ffffff"
FONT = "Helvetica Neue, Helvetica, Arial, sans-serif"
HEAD = ("Build, rank and grow", "your website with AI.")
TAG = "Your AI agent does the work. You stay in control."


def mark(x, y, s, fill=MAG):
    """The mark: a disc with the knockout cut out, so the background shows through."""
    return (f'<g transform="translate({x:.1f} {y:.1f}) scale({s/280:.5f})">'
            f'<path fill="{fill}" fill-rule="evenodd" clip-rule="evenodd" d="{D}"/></g>')


def lockup(x, y, s, text=WHITE):
    """Mark and name, s = mark size, (x, y) = top left. Width is about 5 * s."""
    fs = s * 0.773
    return (mark(x, y, s) +
            f'<text x="{x + s * 1.38:.1f}" y="{y + s * 0.73:.1f}" font-family="{FONT}" '
            f'font-size="{fs:.1f}" font-weight="700" letter-spacing="{-fs*0.03:.1f}" fill="{text}">SaddleWP</text>')


def svg(w, h, body, bg=INK):
    bgrect = f'<rect width="{w}" height="{h}" fill="{bg}"/>' if bg else ""
    return (f'<svg xmlns="http://www.w3.org/2000/svg" width="{w}" height="{h}" viewBox="0 0 {w} {h}">\n'
            f'  {bgrect}\n  {body}\n</svg>\n')


def centered(w, h, scale):
    """Social cover: lockup, brand line and tagline, centred."""
    s = 88 * scale
    cx = w / 2
    y0 = h / 2 - 150 * scale
    t = lambda y, fs, wt, fill, txt: (f'<text x="{cx}" y="{y:.1f}" text-anchor="middle" font-family="{FONT}" '
                                       f'font-size="{fs:.1f}" font-weight="{wt}" fill="{fill}">{txt}</text>')
    return (lockup(cx - 2.5 * s, y0, s) +
            t(y0 + 205 * scale, 46 * scale, 700, WHITE, HEAD[0]) +
            t(y0 + 263 * scale, 46 * scale, 700, WHITE, HEAD[1]) +
            t(y0 + 322 * scale, 24 * scale, 400, MUTED, TAG))


def pillar(y, label, text):
    return (f'<g transform="translate(900 {y})"><rect width="532" height="86" rx="14" fill="{PANEL}"/>'
            f'<circle cx="44" cy="43" r="9" fill="{SUN}"/>'
            f'<text x="78" y="39" font-family="{FONT}" font-size="27" font-weight="700" fill="{WHITE}">{label}</text>'
            f'<text x="78" y="68" font-family="{FONT}" font-size="20" fill="{MUTED}">{text}</text></g>')


PILLARS = (("Build", "Pages, menus and plugin updates"),
           ("Rank", "SEO titles and search visibility"),
           ("Grow", "Visitors and traffic from AI answers"))


def wp_banner():
    body = (lockup(112, 96, 88) +
            f'<text font-family="{FONT}" font-size="46" font-weight="700" letter-spacing="-1" fill="{WHITE}">'
            f'<tspan x="112" y="290">{HEAD[0]}</tspan><tspan x="112" y="348">{HEAD[1]}</tspan></text>'
            f'<text x="112" y="410" font-family="{FONT}" font-size="24" fill="{MUTED}">{TAG}</text>' +
            "".join(pillar(92 + 115 * i, *p) for i, p in enumerate(PILLARS)))
    return svg(1544, 500, body)


def og():
    body = (lockup(80, 72, 72) +
            f'<text font-family="{FONT}" font-size="76" font-weight="700" letter-spacing="-2" fill="{WHITE}">'
            f'<tspan x="80" y="318">{HEAD[0]}</tspan><tspan x="80" y="404">{HEAD[1]}</tspan></text>' +
            "".join(f'<g transform="translate({80 + i*350} 480)"><circle cx="9" cy="-8" r="9" fill="{SUN}"/>'
                    f'<text x="30" y="0" font-family="{FONT}" font-size="30" font-weight="700" fill="{WHITE}">{p[0]}</text></g>'
                    for i, p in enumerate(PILLARS)))
    return svg(1200, 630, body)


def icon(size=1024):
    return svg(size, size, mark(size * 0.1875, size * 0.1875, size * 0.625, WHITE), bg=MAG)


def render(name, w, doc, out_dir=OUT):
    p = out_dir / f"{name}.svg"
    p.write_text(doc)
    subprocess.run(["rsvg-convert", "-w", str(w), str(p), "-o", str(out_dir / f"{name}.png")], check=True)


if __name__ == "__main__":
    render("logo-mark", 1024, svg(280, 280, mark(0, 0, 280), bg=None))
    render("logo-on-dark", 1500, svg(500, 88, lockup(0, 0, 88), bg=None))
    render("logo-on-light", 1500, svg(500, 88, lockup(0, 0, 88, INK), bg=None))
    render("avatar-1024", 1024, icon())
    render("social-x-header-1500x500", 1500, svg(1500, 500, centered(1500, 500, 1.0)))
    render("social-linkedin-cover-1584x396", 1584, svg(1584, 396, centered(1584, 396, 0.8)))
    render("social-youtube-banner-2560x1440", 2560, svg(2560, 1440, centered(2560, 1440, 1.45)))
    render("social-share-1200x630", 1200, og())
    (WPO / "src/banner.svg").write_text(wp_banner())
    for w in (1544, 772):
        subprocess.run(["rsvg-convert", "-w", str(w), str(WPO / "src/banner.svg"), "-o",
                        str(WPO / f"banner-{w}x{w*500//1544}.png")], check=True)
