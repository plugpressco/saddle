/**
 * `ui.icons`: Iconoir icons with the kit's icon props (K3).
 *
 * Every key the kit's icon set had stays, drawn with the Iconoir icon named
 * in `uiNames` (scripts/icons.mjs). Each takes the props the kit's icons
 * took, so `<icons.Search size={ 16 } />` draws the same 16px box:
 *
 * - `size` (default 18) sets width and height;
 * - `strokeWidth` (default 1.5, Iconoir's own) reaches every stroke;
 * - `color` (default the text colour) colours the strokes and fills;
 * - `className` and any other SVG attribute pass through;
 * - `aria-label` makes the icon meaningful; without it the icon is hidden
 *   from assistive technology.
 *
 * One difference: a ref is not forwarded. Wrap the icon in an element when a
 * ref is needed, for example as the direct child of a Tooltip.
 */
import { byName, uiNames } from './iconoir';

/**
 * An Iconoir component with the kit's props.
 *
 * @param {Function} Svg  The svgr component.
 * @param {string}   name The `ui.icons` key, for React's dev tools.
 * @return {Function} The icon component.
 */
export function kitIcon( Svg, name ) {
	function Icon( {
		size = 18,
		strokeWidth = 1.5,
		'aria-label': ariaLabel,
		...rest
	} ) {
		return (
			<Svg
				width={ size }
				height={ size }
				strokeWidth={ strokeWidth }
				aria-label={ ariaLabel }
				aria-hidden={ ariaLabel ? undefined : true }
				focusable="false"
				{ ...rest }
			/>
		);
	}
	Icon.displayName = `${ name }Icon`;
	return Icon;
}

// The `ui.icons` object a module reads. Keys never disappear (Analytics has
// no fallback for a missing one); new keys are added on request.
export const icons = Object.fromEntries(
	Object.keys( uiNames ).map( ( key ) => [
		key,
		kitIcon( byName[ uiNames[ key ] ], key ),
	] )
);
