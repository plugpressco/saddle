<?php
/**
 * Shared SEO copy advice for the native SEO integrations.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Non-blocking length advice shared by every native SEO integration (Yoast,
 * Rank Math, and the ones that follow). Extracted from Saddle_Yoast when
 * Rank Math arrived — one place for the thresholds, so two integrations can
 * never disagree about what "too long" means. Warn, never block: same
 * advise-don't-reject philosophy as Divi's lint echo.
 */
class Saddle_Seo_Advice {

	/**
	 * Length notes for a title/description pair. Empty array when both are
	 * within range; never blocks the write.
	 *
	 * @param string $title       SEO title.
	 * @param string $description Meta description.
	 * @return string[]
	 */
	public static function length_warnings( $title, $description ) {
		$warnings = array();

		if ( '' !== $title && mb_strlen( $title ) > 60 ) {
			$warnings[] = sprintf(
				/* translators: %d: character count. */
				__( 'SEO title is %d characters — search engines typically truncate past ~60.', 'saddle' ),
				mb_strlen( $title )
			);
		}

		if ( '' !== $description && mb_strlen( $description ) > 160 ) {
			$warnings[] = sprintf(
				/* translators: %d: character count. */
				__( 'Meta description is %d characters — aim for roughly 155-160 or less.', 'saddle' ),
				mb_strlen( $description )
			);
		}

		return $warnings;
	}
}
