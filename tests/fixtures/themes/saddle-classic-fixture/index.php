<?php
/**
 * The one template a classic theme must have. Its existence (and the absence
 * of templates/index.html + theme.json) is what makes wp_is_block_theme()
 * return false, which is the only thing the test suite needs from it.
 *
 * @package Saddle
 */

get_header();
the_post();
the_content();
get_footer();
