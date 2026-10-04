<?php
/**
 * Translator comments: make-pot keys a string by its context and text, and
 * warns when one key carries two different translator comments. Translators
 * then see only one of them, which is wrong for the other call.
 *
 * @package Saddle
 */

class Saddle_I18n_Comments_Test extends WP_UnitTestCase {

	/**
	 * Every translator comment in the plugin's PHP, keyed the way make-pot
	 * keys a string: context, then text.
	 *
	 * @return array<string,string[]> "context\x04text" => distinct comments.
	 */
	private function comments_by_string() {
		$root  = dirname( __DIR__ );
		$files = array( $root . '/saddle.php' );
		$iter  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/includes', FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iter as $file ) {
			if ( 'php' === $file->getExtension() && false === strpos( $file->getPathname(), '/includes/lib/' ) ) {
				$files[] = $file->getPathname();
			}
		}

		$pattern = '#/\*\s*translators:\s*(.*?)\s*\*/\s*(?:sprintf\(\s*|printf\(\s*)?(__|_n|_x|esc_html__|esc_attr__|esc_html_x|esc_attr_x)\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*,\s*\'((?:[^\'\\\\]|\\\\.)*)\'#s';

		$found = array();
		foreach ( $files as $path ) {
			preg_match_all( $pattern, (string) file_get_contents( $path ), $matches, PREG_SET_ORDER );
			foreach ( $matches as $m ) {
				$context = in_array( $m[2], array( '_x', 'esc_html_x', 'esc_attr_x' ), true ) ? $m[4] : '';
				$key     = $context . "\x04" . $m[3];

				$found[ $key ][] = $m[1];
			}
		}

		return array_map( 'array_unique', $found );
	}

	public function test_the_label_and_problem_pair_has_one_comment_per_meaning() {
		$found = $this->comments_by_string();

		// A setting's validation error and a Divi structural finding both read
		// "<thing>: <what is wrong>", with different things on the left.
		$this->assertArrayNotHasKey( "\x04%1\$s: %2\$s", $found, 'Each use carries its own context.' );
		$this->assertSame( array( '1: setting label, 2: what is wrong.' ), array_values( $found[ "invalid setting\x04%1\$s: %2\$s" ] ) );
		$this->assertSame( array( '1: module type, 2: the structural problem.' ), array_values( $found[ "Divi structural finding\x04%1\$s: %2\$s" ] ) );
	}
}
