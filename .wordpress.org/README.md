# WordPress.org listing assets

These files are for the WordPress.org plugin directory's SVN `assets/` folder
(the sibling of `trunk/`) — they are NOT shipped inside the plugin zip
(excluded in Gruntfile.js).

- `icon.svg`: the white brand mark (`assets/brand/mark.svg`) on Saddle magenta
  `#d000c4`, full bleed (2026-10-23; was ink on transparent).
- `icon-128x128.png` / `icon-256x256.png`: rendered from `icon.svg`:

      rsvg-convert -w 256 -h 256 icon.svg -o icon-256x256.png
      rsvg-convert -w 128 -h 128 icon.svg -o icon-128x128.png

- `banner-772x250.png` / `banner-1544x500.png`: a light, minimal banner
  (2026-10-23): the magenta mark and "Saddle", one line on what it does, and
  one "Needs your OK" card with Reject and Approve. No app logos. **Generated,
  do not hand-edit the PNGs.** Source is `src/banner.svg`; re-render both
  sizes with:

      cd .wordpress.org
      rsvg-convert -w 1544 -h 500 src/banner.svg -o banner-1544x500.png
      rsvg-convert -w  772 -h 250 src/banner.svg -o banner-772x250.png

  (`brew install librsvg`. The first cut of this art was rendered from a
  scratchpad SVG that was never committed and had to be rebuilt from the PNG
  during the WP.org round-1 fixes — hence the source lives here now.)

⚠️ The banner tagline must not contain "WordPress". Listing graphics fall under
the same trademark rule as the plugin name — the round-1 review called out
"graphic resources such as this plugin icons and banners" explicitly. It now
reads "Let your AI work on your live site without handing it the keys."

After the plugin is approved these are synced to SVN `assets/` automatically by
`.github/workflows/release.yml` on every real release tag (#176). Only
`icon*.png`, `icon.svg`, `banner-*.png` and `screenshot-*.{png,jpg}` are copied;
this README and `src/` never leave the repo.

Manual fallback, if ever needed:

    svn co https://plugins.svn.wordpress.org/saddle
    cp .wordpress.org/icon* .wordpress.org/banner* .wordpress.org/screenshot-* saddle/assets/
    svn add saddle/assets/* && svn ci -m "Listing assets"

Screenshots (`screenshot-N.png` + captions in readme.txt) were retaken on
2026-10-23 for the release after 1.6.1, from the built zip on WordPress
Playground: a demo site ("Northwind Studio") with Saddle Analytics, a week of
seeded log entries, three apps connected through keys (Claude Code, ChatGPT,
Cursor) and two deletes waiting under Needs your OK; 1280×900 at 2x
(2560×1800). Retake them when a page they show changes; they ship with the
next release.
