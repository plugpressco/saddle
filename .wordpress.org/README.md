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
  wordmark + tagline on ink `#131316`.

After the plugin is approved, copy these into the SVN repo:

    svn co https://plugins.svn.wordpress.org/saddle
    cp .wordpress.org/icon* .wordpress.org/banner* saddle/assets/
    svn add saddle/assets/* && svn ci -m "Listing assets"

Screenshots (`screenshot-N.png` + captions in readme.txt) are still to be
captured from a live wp-admin before or after approval.
