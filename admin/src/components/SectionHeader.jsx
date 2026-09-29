/**
 * A section's heading inside a page: its name, an optional line under it, and
 * its own actions. The page's title and its one sentence live in the frame
 * (Frame.jsx), so a section never repeats them at page size.
 *
 * @param {Object} props
 * @param {*}      props.title       Section name.
 * @param {*}      props.description Optional line under the name.
 * @param {*}      props.actions     Optional right-aligned controls.
 * @param {string} props.id          Optional anchor, e.g. `memory`.
 */
export default function SectionHeader( { title, description, actions, id } ) {
	return (
		<div className="saddle-section-head" id={ id }>
			<div className="saddle-section-head__text">
				<h2 className="saddle-section-head__title">{ title }</h2>
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
