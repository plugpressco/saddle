# WordPress.org listing assets

These files are for the WordPress.org plugin directory's SVN `assets/` folder
(the sibling of `trunk/`) — they are NOT shipped inside the plugin zip
(excluded in Gruntfile.js).

- `icon.svg` — the brand mark (draped-saddle glyph knocked out of a disc),
  ink `#111113` on transparent. Same art as `assets/brand/mark.svg`, with the
  `currentColor` fill made literal.
- `icon-128x128.png` / `icon-256x256.png` — raster fallbacks rendered from
  `icon.svg`.
- `banner-772x250.png` / `banner-1544x500.png` — listing banner: mark +
  wordmark + tagline on ink `#131316`. **Generated — do not hand-edit the
  PNGs.** Source is `src/banner.svg`; re-render both sizes with:

      cd .wordpress.org
      rsvg-convert -w 1544 -h 500 src/banner.svg -o banner-1544x500.png
      rsvg-convert -w  772 -h 250 src/banner.svg -o banner-772x250.png

  (`brew install librsvg`. The first cut of this art was rendered from a
  scratchpad SVG that was never committed and had to be rebuilt from the PNG
  during the WP.org round-1 fixes — hence the source lives here now.)

⚠️ The banner tagline must not contain "WordPress". Listing graphics fall under
the same trademark rule as the plugin name — the round-1 review called out
"graphic resources such as this plugin icons and banners" explicitly. It now
reads "Control your site with AI — safely."

After the plugin is approved, copy these into the SVN repo:

    svn co https://plugins.svn.wordpress.org/saddle
    cp .wordpress.org/icon* .wordpress.org/banner* saddle/assets/
    svn add saddle/assets/* && svn ci -m "Listing assets"

Screenshots (`screenshot-N.png` + captions in readme.txt) are still to be
captured from a live wp-admin before or after approval.
