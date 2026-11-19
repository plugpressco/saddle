<?php
/**
 * The guides Divi module packs give agents for their own modules.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * A pack's module.json says what a module can hold, not what it is for.
 * A pack fills that in through the `saddle_divi_module_guide` filter
 * ($guide, $type): purpose, use_when, key_settings, example and notes,
 * every key optional. divi-list-modules shows purpose and use_when,
 * divi-get-module-schema the rest, and Saddle_Divi_Module_Notes merges the
 * notes with its own.
 *
 * Each guide is read once per request and cleaned here: strings joined
 * into one line and capped, kept as text (no tag stripping: "<id>" is
 * text the agent must read), key settings kept only when the module has
 * them, and the example checked once with Saddle_Divi_Node_Check, the
 * same for every account. What fails is left out, never shown, and with
 * WP_DEBUG the reason goes to _doing_it_wrong(). A guide that is not an
 * array is ignored.
 *
 * A class of its own, not more of Saddle_Divi_Module_Notes, because the
 * reader, its caps and its per-request caches would more than double that
 * class's size.
 */
class Saddle_Divi_Module_Guide {

	/**
	 * Longest purpose and use_when line, and longest note, in characters.
	 */
	const PURPOSE_MAX  = 140;
	const USE_WHEN_MAX = 160;
	const NOTE_MAX     = 300;

	/**
	 * Notes and key settings kept at most.
	 */
	const NOTES_MAX        = 4;
	const KEY_SETTINGS_MAX = 5;

	/**
	 * Largest example, in bytes of JSON.
	 */
	const EXAMPLE_BYTES = 2048;

	/**
	 * A setting path: dot-separated attribute names.
	 */
	const SETTING_PATH = '/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)+$/';

	/**
	 * Cleaned guides read in this request, by module type.
	 *
	 * @var array<string,array>
	 */
	private static $guides = array();

	/**
	 * Checked examples in this request, by module type (null: none, or dropped).
	 *
	 * @var array<string,array|null>
	 */
	private static $examples = array();

	/**
	 * A module's guide, cleaned and capped, read once per request. Keys are
	 * present only when the pack gave them; "example" is not checked yet
	 * (example() checks it).
	 *
	 * @param string $type Module type.
	 * @return array
	 */
	public static function guide( $type ) {
		$type = (string) $type;
		if ( ! array_key_exists( $type, self::$guides ) ) {
			/**
			 * Filter the guide an agent reads for one Divi module. A module
			 * pack adds a guide for its own types only and returns $guide
			 * unchanged for every other type.
			 *
			 * @param array  $guide {
			 *     The guide so far (empty when no pack has one). Every key is optional.
			 *
			 *     @type string   $purpose      One line, at most 140 characters: what the module is.
			 *     @type string   $use_when     One line, at most 160 characters: when to pick it, and when not.
			 *     @type string[] $key_settings At most 5 setting paths from divi-get-module-schema, in the order to set them.
			 *     @type array    $example      One node as divi-set-page takes it, under 2 KB of JSON.
			 *     @type string[] $notes        At most 4 traps, one short sentence each.
			 * }
			 * @param string $type  Module type with its namespace.
			 */
			self::$guides[ $type ] = self::clean( apply_filters( 'saddle_divi_module_guide', array(), $type ) );
		}
		return self::$guides[ $type ];
	}

	/**
	 * The guide as divi-get-module-schema shows it: purpose, use_when and
	 * the key settings the module has, plus the checked example when
	 * $full. Empty when the pack gave none of these.
	 *
	 * @param string $type Module type.
	 * @param bool   $full Whether to add the example.
	 * @return array
	 */
	public static function for_schema( $type, $full = false ) {
		$guide = self::guide( $type );
		$out   = array_intersect_key( $guide, array_flip( array( 'purpose', 'use_when' ) ) );
		$keys  = isset( $guide['key_settings'] ) ? self::known_settings( (string) $type, $guide['key_settings'] ) : array();
		if ( $keys ) {
			$out['key_settings'] = $keys;
		}
		$example = $full ? self::example( $type ) : null;
		if ( null !== $example ) {
			$out['example'] = $example;
		}
		return $out;
	}

	/**
	 * The guide's example as the pack wrote it, once it passed the checks
	 * a write uses; null when there is none or it failed.
	 *
	 * @param string $type Module type.
	 * @return array|null
	 */
	public static function example( $type ) {
		$type = (string) $type;
		if ( ! array_key_exists( $type, self::$examples ) ) {
			$guide                   = self::guide( $type );
			self::$examples[ $type ] = isset( $guide['example'] ) ? self::checked( $type, $guide['example'] ) : null;
		}
		return self::$examples[ $type ];
	}

	/**
	 * Notes followed by those of $more not already there, blanks skipped.
	 *
	 * @param string[] $notes Notes kept as they are.
	 * @param string[] $more  Notes to add.
	 * @return string[]
	 */
	public static function merge( array $notes, array $more ) {
		foreach ( $more as $note ) {
			if ( '' !== $note && ! in_array( $note, $notes, true ) ) {
				$notes[] = $note;
			}
		}
		return $notes;
	}

	/**
	 * Forget the guides read in this request, so a filter added later counts.
	 */
	public static function flush() {
		self::$guides   = array();
		self::$examples = array();
	}

	/**
	 * A raw guide, cleaned: strings as one capped line, key settings shaped
	 * like a path and unique, notes unique and at most NOTES_MAX. Anything
	 * else is dropped.
	 *
	 * @param mixed $raw The filter's answer.
	 * @return array
	 */
	private static function clean( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$guide = array();
		foreach ( array(
			'purpose'  => self::PURPOSE_MAX,
			'use_when' => self::USE_WHEN_MAX,
		) as $key => $max ) {
			$line = isset( $raw[ $key ] ) ? self::line( $raw[ $key ], $max ) : '';
			if ( '' !== $line ) {
				$guide[ $key ] = $line;
			}
		}

		$keys = array();
		foreach ( isset( $raw['key_settings'] ) && is_array( $raw['key_settings'] ) ? $raw['key_settings'] : array() as $path ) {
			$path = is_string( $path ) ? trim( $path ) : '';
			if ( preg_match( self::SETTING_PATH, $path ) && ! in_array( $path, $keys, true ) ) {
				$keys[] = $path;
			}
		}
		if ( $keys ) {
			$guide['key_settings'] = $keys;
		}

		$notes = array();
		foreach ( isset( $raw['notes'] ) && is_array( $raw['notes'] ) ? $raw['notes'] : array() as $note ) {
			$notes[] = self::line( $note, self::NOTE_MAX );
		}
		$notes = array_slice( self::merge( array(), $notes ), 0, self::NOTES_MAX );
		if ( $notes ) {
			$guide['notes'] = $notes;
		}

		if ( isset( $raw['example'] ) ) {
			$guide['example'] = $raw['example'];
		}
		return $guide;
	}

	/**
	 * One line of text: invalid UTF-8 dropped, lines joined, whitespace
	 * collapsed, control characters removed, cut to $max characters with
	 * an ellipsis. Tags stay: the text is data an agent reads, like a
	 * skill body, never HTML a browser renders. '' for anything but a string.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Characters kept.
	 * @return string
	 */
	private static function line( $value, $max ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = wp_check_invalid_utf8( $value, true );
		$value = trim( (string) preg_replace( '/\p{C}/u', '', (string) preg_replace( '/\s+/u', ' ', $value ) ) );
		if ( mb_strlen( $value ) > $max ) {
			$value = rtrim( mb_substr( $value, 0, $max - 1 ) ) . '…';
		}
		return $value;
	}

	/**
	 * The key settings the module has (a path or an id from its settings
	 * list), at most KEY_SETTINGS_MAX. Kept as given when the module's
	 * settings cannot be read.
	 *
	 * @param string   $type  Module type.
	 * @param string[] $paths Key settings from the guide.
	 * @return string[]
	 */
	private static function known_settings( $type, array $paths ) {
		$known = array();
		foreach ( Saddle_Divi_Settings::for_module( $type ) as $entry ) {
			$known[ $entry['path'] ] = true;
			$known[ $entry['id'] ]   = true;
		}
		if ( $known ) {
			$unknown = array_values( array_diff( $paths, array_keys( $known ) ) );
			$paths   = array_values( array_intersect( $paths, array_keys( $known ) ) );
			if ( $unknown ) {
				/* translators: 1: module type, 2: comma-separated setting paths. */
				self::mistake( sprintf( __( 'The guide for %1$s names key settings the module does not have, so these were left out: %2$s.', 'saddle' ), $type, implode( ', ', $unknown ) ) );
			}
		}
		return array_slice( $paths, 0, self::KEY_SETTINGS_MAX );
	}

	/**
	 * The example when it is one node under EXAMPLE_BYTES that would save
	 * and render as written; else null, with a debug note.
	 *
	 * @param string $type    Module type.
	 * @param mixed  $example The guide's example.
	 * @return array|null
	 */
	private static function checked( $type, $example ) {
		if ( ! is_array( $example ) || ! isset( $example['type'] ) || ! is_string( $example['type'] ) ) {
			/* translators: %s: module type. */
			self::mistake( sprintf( __( 'The guide example for %s was left out because it is not one node with a "type".', 'saddle' ), $type ) );
			return null;
		}
		if ( strlen( (string) wp_json_encode( $example ) ) > self::EXAMPLE_BYTES ) {
			/* translators: %s: module type. */
			self::mistake( sprintf( __( 'The guide example for %s was left out because it is larger than 2 KB of JSON.', 'saddle' ), $type ) );
			return null;
		}
		$problem = Saddle_Divi_Node_Check::problem( $example );
		if ( '' === $problem ) {
			return $example;
		}
		/* translators: 1: module type, 2: the check's own message, one or more whole sentences. */
		self::mistake( sprintf( __( 'The guide example for %1$s was left out because a page built from it would not save or render as written. The check says: %2$s', 'saddle' ), $type, $problem ) );
		return null;
	}

	/**
	 * Tell the pack's developer, with WP_DEBUG on, why part of a guide
	 * was left out.
	 *
	 * @param string $message What was wrong.
	 */
	private static function mistake( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			_doing_it_wrong( 'saddle_divi_module_guide', esc_html( $message ), '1.7.7' );
		}
	}
}
