# SaddleWP brand kit

One look everywhere: ink `#14121a`, white text, the magenta mark `#d000c4`,
sun yellow `#ffcf33` as the only second colour. Flat, no gradients. Helvetica
Neue bold for the name and headline. The brand line is "Build, rank and grow
your website with AI."

| File | Use |
|---|---|
| `logo-mark` (svg, png) | The mark alone, magenta, transparent. The disc's cut-out shows the background. |
| `logo-on-dark`, `logo-on-light` | Mark and name, transparent, for dark and light backgrounds. |
| `avatar-1024` | Profile picture for every network: white mark on magenta, full bleed. |
| `social-x-header-1500x500` | X and Bluesky header. |
| `social-linkedin-cover-1584x396` | LinkedIn and Facebook cover. |
| `social-youtube-banner-2560x1440` | YouTube banner. Keep text in the centre 1546 x 423. |
| `social-share-1200x630` | Link preview image (Open Graph). |

The WordPress.org banner (`.wordpress.org/banner-*.png`) comes from the same
script. Do not edit the PNGs. Change `build.py` and rebuild from the repo root:

    python3 docs/brand/build.py     # needs: brew install librsvg

Keep the word "WordPress" out of listing art (WordPress.org trademark rule).
