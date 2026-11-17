# WordPress.org listing assets

These files are for the WordPress.org plugin directory's SVN `assets/` folder
(the sibling of `trunk/`) — they are NOT shipped inside the plugin zip
(excluded in Gruntfile.js).

- `icon.svg`: the social profile icon (`docs/brand/saddle-social-icon.svg`,
  Fahim's set of 2026-11-13): the white arch of the mark on Saddle magenta
  `#d000c4`, full bleed. `icon-128x128.png` / `icon-256x256.png` are rendered
  from it:

      rsvg-convert -w 256 -h 256 icon.svg -o icon-256x256.png
      rsvg-convert -w 128 -h 128 icon.svg -o icon-128x128.png

- `banner-772x250.png` / `banner-1544x500.png`: cut from the brand kit's X
  cover (`docs/brand/saddle-cover-x-1500x500.png`): the SaddleWP name, the line
  "Build, rank and grow your website with AI." and the magenta sun over the
  pixel hills. The 1544 version scales the cover to width (nearest neighbour,
  so the dots stay crisp) and crops the height; the 772 version halves it.
  **Generated, do not hand-edit the PNGs.** Change the cover in `docs/brand/`
  and cut both again.

⚠️ The banner tagline must not contain "WordPress". Listing graphics fall under
the same trademark rule as the plugin name — the round-1 review called out
"graphic resources such as this plugin icons and banners" explicitly. The banner
text is "Build, rank and grow your website with AI."

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
