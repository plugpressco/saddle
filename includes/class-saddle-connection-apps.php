<?php
/**
 * Which app a connection is, as far as the evidence says.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Names the app behind a connection, so the admin can say "Claude" rather
 * than "Saddle: AI app" or a random OAuth client id.
 *
 * A display hint only, never a security decision: every piece of evidence
 * here is self-reported by the client. The keys match the connect wizard's
 * catalog (`admin/src/connect-apps.js`), which owns the labels and logos.
 *
 * The layers, strongest first:
 *
 * 1. OAuth: the client-ID metadata URL, or a redirect URI the app registered.
 * 2. The `clientInfo.name` the app sent on `initialize`.
 * 3. The User-Agent's product token.
 * 4. The name the credential was made under: the app picked in the wizard
 *    for a key, or the name an app registered itself with.
 *
 * When nothing matches, the result is '' and the admin shows the client's
 * raw name. The tables grow from real traffic.
 */
class Saddle_Connection_Apps {

	/**
	 * OAuth evidence, matched as a prefix of the client id or a redirect URI.
	 */
	const OAUTH_URLS = array(
		'https://claude.ai/oauth/claude-code-client-metadata' => 'claude-code',
		'https://claude.ai/api/mcp/auth_callback'  => 'claude',
		'https://claude.com/api/mcp/auth_callback' => 'claude',
		'https://chatgpt.com/'                     => 'chatgpt',
	);

	/**
	 * `clientInfo.name` (and User-Agent product tokens), lowercased.
	 *
	 * Read from each client's own code on 2026-09-30 where it was installed:
	 * Claude Code 2.1 (`claude-code`), Codex 0.157 (`codex-mcp-client`),
	 * Cursor's cursor-mcp extension (`cursor-vscode`) and VS Code, which sends
	 * its product name. `claude-ai` is what claude.ai's connectors send.
	 */
	const CLIENT_NAMES = array(
		'claude-ai'                     => 'claude',
		'claude-code'                   => 'claude-code',
		'claude code'                   => 'claude-code',
		'codex-mcp-client'              => 'codex',
		'cursor-vscode'                 => 'cursor',
		'visual studio code'            => 'vscode',
		'visual studio code - insiders' => 'vscode',
	);

	/**
	 * App names as the wizard labels them, lowercased. Longer names first, so
	 * "Claude Code" is not taken for "Claude".
	 */
	const LABELS = array(
		'claude code' => 'claude-code',
		'gemini cli'  => 'gemini-cli',
		'claude'      => 'claude',
		'chatgpt'     => 'chatgpt',
		'codex'       => 'codex',
		'cursor'      => 'cursor',
		'vs code'     => 'vscode',
		'windsurf'    => 'windsurf',
		'openclaw'    => 'openclaw',
		'grok'        => 'grok',
	);

	/**
	 * The app a connection most likely is.
	 *
	 * @param array $evidence Any of: `urls` (OAuth client id and redirect
	 *                        URIs), `client_name`, `agent`, `name`.
	 * @return string An app key from the connect catalog, or ''.
	 */
	public static function detect( array $evidence ) {
		$urls = isset( $evidence['urls'] ) ? (array) $evidence['urls'] : array();
		foreach ( $urls as $url ) {
			foreach ( self::OAUTH_URLS as $prefix => $app ) {
				if ( is_string( $url ) && 0 === strpos( $url, $prefix ) ) {
					return $app;
				}
			}
		}

		$names = self::CLIENT_NAMES;
		foreach ( array( 'client_name', 'agent' ) as $field ) {
			$value = isset( $evidence[ $field ] ) ? strtolower( trim( (string) $evidence[ $field ] ) ) : '';
			if ( isset( $names[ $value ] ) ) {
				return $names[ $value ];
			}
		}

		return self::from_name( isset( $evidence['name'] ) ? (string) $evidence['name'] : '' );
	}

	/**
	 * The app a credential's own name refers to: "Claude Code", "Claude Code
	 * 2" (the wizard's counter for a second key), "Cursor (laptop)".
	 *
	 * @param string $name Credential name.
	 * @return string
	 */
	private static function from_name( $name ) {
		$name = strtolower( trim( preg_replace( '/\s+/', ' ', $name ) ) );
		if ( '' === $name ) {
			return '';
		}

		foreach ( self::LABELS as $label => $app ) {
			if ( $name === $label || 0 === strpos( $name, $label . ' ' ) ) {
				return $app;
			}
		}

		return '';
	}
}
