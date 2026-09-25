<?php
/**
 * Lint rule: local overrides fighting a global preset.
 *
 * @package Saddle
 */

defined( 'ABSPATH' ) || exit;

/**
 * The provable entanglement case: a node bound to a global preset that ALSO
 * carries local style overrides. Now neither lever works honestly — editing
 * the preset silently doesn't change this node where the override shadows
 * it, and editing the node leaves siblings on the preset behind. "A change
 * stays a change" breaks both ways.
 *
 * Deliberately the narrowest anti-entanglement trigger: preset binding AND
 * a local override, both read straight off the persisted attrs. The wider
 * heuristic (a shared token referenced across many nodes) was considered
 * and rejected — tokens shared widely are the design system WORKING, and
 * flagging that would teach agents to stop using tokens. Needs the
 * companion style accessor; silent without it.
 */
class Saddle_Lint_Rule_Preset_Coupling extends Saddle_Lint_Rule {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'preset-coupling';
	}

	/**
	 * Flag preset-bound nodes that carry local style overrides.
	 *
	 * @param array[]              $nodes    Flat node list.
	 * @param Saddle_Lint_Accessor $accessor Builder accessor.
	 * @return array[]
	 */
	public function check( array $nodes, Saddle_Lint_Accessor $accessor ) {
		if ( ! $accessor instanceof Saddle_Lint_Style_Accessor ) {
			return array();
		}

		$violations = array();
		foreach ( $nodes as $node ) {
			$block  = $node['block'];
			$preset = $accessor->global_preset_ref( $block );
			if ( null === $preset ) {
				continue;
			}

			$overrides = array();
			if ( null !== $accessor->background_color( $block ) ) {
				$overrides[] = __( 'background', 'saddle' );
			}
			if ( null !== $accessor->text_color( $block ) ) {
				$overrides[] = __( 'text color', 'saddle' );
			}
			if ( null !== $accessor->padding( $block ) ) {
				$overrides[] = __( 'padding', 'saddle' );
			}
			if ( null !== $accessor->border_radius( $block ) ) {
				$overrides[] = __( 'radius', 'saddle' );
			}
			if ( ! $overrides ) {
				continue;
			}

			$violations[] = $this->violation(
				$node['address'],
				self::SEVERITY_WARN,
				sprintf(
					/* translators: 1: preset id, 2: the overridden properties. */
					__( 'Bound to global preset "%1$s" but locally overriding %2$s — editing the preset will silently not change this node, and editing this node leaves its preset siblings behind.', 'saddle' ),
					$preset,
					implode( ', ', $overrides )
				),
				__( 'Pick one lever: move the override into the preset (or a new preset) so siblings stay in sync, or detach the preset so this node is honestly standalone.', 'saddle' )
			);
		}
		return $violations;
	}
}
