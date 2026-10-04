/**
 * A section's heading inside a page: its name, an optional "?" with a longer
 * explanation, an optional line under it, and its own actions. The page's
 * title and its one sentence live in the frame (Frame.jsx), so a section
 * never repeats them at page size.
 *
 * The "?" sits beside the heading, not inside it, so the heading's name is
 * the section's name and not the whole explanation.
 */
import { HelpTip } from '@plugpress/ui';

/**
 * @param {Object} props
 * @param {*}      props.title       Section name.
 * @param {*}      props.help        Optional explanation behind a "?".
 * @param {*}      props.description Optional line under the name.
 * @param {*}      props.actions     Optional right-aligned controls.
 * @param {string} props.id          Optional anchor, e.g. `memory`.
 */
export default function SectionHeader( {
	title,
	help,
	description,
	actions,
	id,
} ) {
	const heading = <h2 className="saddle-section-head__title">{ title }</h2>;
	return (
		<div className="saddle-section-head" id={ id }>
			<div className="saddle-section-head__text">
				{ help ? (
					<div className="saddle-section-head__name">
						{ heading }
						<HelpTip>{ help }</HelpTip>
					</div>
				) : (
					heading
				) }
				{ description && (
					<p className="saddle-section-head__desc">{ description }</p>
				) }
			</div>
			{ actions && (
				<div className="saddle-section-head__actions">{ actions }</div>
			) }
		</div>
	);
}
