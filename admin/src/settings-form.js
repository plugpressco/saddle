/**
 * The logic behind SettingsForm, kept apart from the markup so it can be
 * tested on its own: which fields show, how they split into basic and
 * advanced, what changed, and what is wrong with a draft.
 *
 * A field is one entry of `GET preferences/{scope}`:
 * `{ key, type, enum?, minimum?, maximum?, default, label, help, level, agent,
 *    control, screen, value }`.
 */

const NUMBER_TYPES = [ 'integer', 'number' ];

export const isNumeric = ( field ) => NUMBER_TYPES.includes( field.type );

/**
 * The id of a field's control, and the anchor an agent or a link can use.
 *
 * @param {string} scope `saddle` or a module key.
 * @param {string} key   Field key.
 * @return {string} Element id.
 */
export const fieldId = ( scope, key ) => `saddle-field-${ scope }-${ key }`;

/**
 * The fields this form draws: only those a bespoke component does not own,
 * and, when a screen is named, only those that belong on it.
 *
 * @param {Array}  fields All fields of the scope.
 * @param {string} screen Optional `area/tab`.
 * @return {Array} Fields to draw.
 */
export function renderableFields( fields, screen ) {
	return ( Array.isArray( fields ) ? fields : [] ).filter(
		( f ) =>
			f && 'custom' !== f.control && ( ! screen || f.screen === screen )
	);
}

/**
 * Split fields into the ones always shown and the ones behind "More
 * settings". When nothing is basic, the whole set is already the advanced
 * tab, so it shows directly with no disclosure.
 *
 * @param {Array} fields Renderable fields.
 * @return {{basic: Array, advanced: Array, disclosure: boolean}} The split.
 */
export function splitFields( fields ) {
	const advanced = fields.filter( ( f ) => 'advanced' === f.level );
	const basic = fields.filter( ( f ) => 'advanced' !== f.level );

	if ( basic.length === 0 ) {
		return { basic: advanced, advanced: [], disclosure: false };
	}

	return { basic, advanced, disclosure: advanced.length > 0 };
}

/**
 * The value a field starts a draft with. A secret starts empty: empty means
 * unchanged, and the saved one is never sent back to the browser.
 *
 * @param {Object} field Field.
 * @return {*} Draft value.
 */
export function startValue( field ) {
	if ( 'secret' === field.type ) {
		return '';
	}
	if ( isNumeric( field ) ) {
		return null === field.value || undefined === field.value
			? ''
			: String( field.value );
	}
	return field.value;
}

/**
 * @param {Array} fields Fields.
 * @return {Object} Draft keyed by field key.
 */
export function startDraft( fields ) {
	return Object.fromEntries(
		fields.map( ( f ) => [ f.key, startValue( f ) ] )
	);
}

/**
 * What the draft says for one field, in the type the server wants.
 *
 * @param {Object} field Field.
 * @param {*}      raw   Draft value.
 * @return {*} Typed value; NaN for a number that is not one.
 */
export function typedValue( field, raw ) {
	if ( isNumeric( field ) ) {
		return '' === String( raw ).trim() ? NaN : Number( raw );
	}
	return raw;
}

const sameValue = ( field, raw ) => {
	if ( 'secret' === field.type ) {
		return '' === raw;
	}
	if ( isNumeric( field ) ) {
		return typedValue( field, raw ) === Number( field.value );
	}
	return raw === field.value;
};

/**
 * Only what differs from what is saved.
 *
 * @param {Array}  fields Renderable fields.
 * @param {Object} draft  Draft by key.
 * @return {Object} `{ key: value }` of changed fields, typed for the server.
 */
export function changedValues( fields, draft ) {
	const out = {};
	fields.forEach( ( f ) => {
		if ( ! ( f.key in draft ) || sameValue( f, draft[ f.key ] ) ) {
			return;
		}
		out[ f.key ] = typedValue( f, draft[ f.key ] );
	} );
	return out;
}

export const isDirty = ( fields, draft ) =>
	Object.keys( changedValues( fields, draft ) ).length > 0;

/**
 * Problems a browser can see before asking the server: a number that is not
 * one, or outside the field's range. The server checks again regardless.
 *
 * @param {Array}  fields Renderable fields.
 * @param {Object} draft  Draft by key.
 * @return {Object} `{ key: code }`, where code is `number`, `min` or `max`.
 */
export function draftProblems( fields, draft ) {
	const out = {};
	Object.keys( changedValues( fields, draft ) ).forEach( ( key ) => {
		const f = fields.find( ( x ) => x.key === key );
		if ( ! f || ! isNumeric( f ) ) {
			return;
		}
		const n = typedValue( f, draft[ key ] );
		if (
			Number.isNaN( n ) ||
			( 'integer' === f.type && ! Number.isInteger( n ) )
		) {
			out[ key ] = 'number';
		} else if ( undefined !== f.minimum && n < f.minimum ) {
			out[ key ] = 'min';
		} else if ( undefined !== f.maximum && n > f.maximum ) {
			out[ key ] = 'max';
		}
	} );
	return out;
}

/**
 * Labels of the fields an agent may ask to change.
 *
 * @param {Array} fields Renderable fields.
 * @return {string[]} Labels.
 */
export const agentWritableLabels = ( fields ) =>
	fields.filter( ( f ) => 'write' === f.agent ).map( ( f ) => f.label );

/**
 * The field a `#saddle-field-{scope}-{key}` address points at.
 *
 * @param {string} hash   `window.location.hash`.
 * @param {string} scope  Scope of the form.
 * @param {Array}  fields Renderable fields.
 * @return {Object|null} The field, or null.
 */
export function fieldForHash( hash, scope, fields ) {
	const id = String( hash || '' ).replace( /^#/, '' );
	return fields.find( ( f ) => fieldId( scope, f.key ) === id ) || null;
}

/**
 * Whether the deep link needs "More settings" open to be seen.
 *
 * @param {Object} split The result of splitFields().
 * @param {Object} field Field the link points at.
 * @return {boolean} Whether to open the disclosure.
 */
export const needsDisclosure = ( split, field ) =>
	split.disclosure && split.advanced.some( ( f ) => f.key === field.key );
