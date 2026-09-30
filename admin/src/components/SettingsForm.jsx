/**
 * SettingsForm — the settings of one scope (Saddle itself, or a module),
 * drawn from the schema the server reports (`GET preferences/{scope}`).
 *
 * Changes stay on this screen until Save; Discard puts back what is saved.
 * Only fields a bespoke screen does not own are drawn (`control: auto`).
 * Basic fields show; advanced ones sit behind "More settings", which
 * remembers open or closed per browser.
 *
 * Shared with modules through `kit.SettingsForm` (feature `settings-form`).
 */
import { useState, useEffect, useMemo, useCallback } from '@wordpress/element';
import {
	ApplyBar,
	Button,
	Card,
	CardContent,
	Collapsible,
	ErrorText,
	Hint,
	Input,
	Label,
	Notice,
	Select,
	Spinner,
	Switch,
	toast,
} from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { api, saddleData } from '../api';
import { areaUrl } from '../routes';
import {
	agentWritableLabels,
	changedValues,
	draftProblems,
	fieldForHash,
	fieldId,
	isDirty,
	isNumeric,
	needsDisclosure,
	renderableFields,
	splitFields,
	startDraft,
} from '../settings-form';

const storeKey = ( scope ) => `saddle.settings.more.${ scope }`;

// A per-browser convenience: never required, and storage can be blocked.
const readOpen = ( scope ) => {
	try {
		return window.localStorage.getItem( storeKey( scope ) ) === '1';
	} catch ( e ) {
		return false;
	}
};
const writeOpen = ( scope, open ) => {
	try {
		window.localStorage.setItem( storeKey( scope ), open ? '1' : '0' );
	} catch ( e ) {
		// Not remembered; the disclosure still works.
	}
};

const problemText = ( field, code ) => {
	if ( 'min' === code ) {
		return sprintf(
			/* translators: %s: the smallest allowed number. */
			__( 'Must be %s or more.', 'saddle' ),
			field.minimum
		);
	}
	if ( 'max' === code ) {
		return sprintf(
			/* translators: %s: the largest allowed number. */
			__( 'Must be %s or less.', 'saddle' ),
			field.maximum
		);
	}
	return __( 'Enter a whole number.', 'saddle' );
};

/**
 * One field: its control, its label and its help.
 *
 * @param {Object}   props
 * @param {string}   props.scope    Scope of the form.
 * @param {Object}   props.field    The field.
 * @param {*}        props.value    Draft value.
 * @param {string}   props.error    Problem to show beside it.
 * @param {boolean}  props.busy     Saving.
 * @param {Function} props.onChange Called with the new value.
 */
function FieldRow( { scope, field, value, error, busy, onChange } ) {
	const id = fieldId( scope, field.key );
	const helpId = field.help ? `${ id }-help` : undefined;
	const errorId = error ? `${ id }-error` : undefined;
	const describedBy = [ helpId, errorId ].filter( Boolean ).join( ' ' );
	const common = {
		id,
		disabled: busy,
		'aria-describedby': describedBy || undefined,
		'aria-invalid': error ? true : undefined,
	};

	let control;
	if ( 'boolean' === field.type ) {
		control = (
			<Switch
				{ ...common }
				checked={ !! value }
				onChange={ onChange }
				aria-label={ field.label }
			/>
		);
	} else if ( field.enum ) {
		control = (
			<Select
				{ ...common }
				value={ String( value ) }
				onChange={ onChange }
				error={ !! error }
				options={ field.enum.map( ( v ) => ( {
					value: String( v ),
					label: String( v ),
				} ) ) }
			/>
		);
	} else if ( isNumeric( field ) ) {
		control = (
			<Input
				{ ...common }
				type="number"
				inputMode="numeric"
				min={ field.minimum }
				max={ field.maximum }
				step={ 'integer' === field.type ? 1 : 'any' }
				value={ value }
				error={ !! error }
				onChange={ ( e ) => onChange( e.target.value ) }
			/>
		);
	} else if ( 'secret' === field.type ) {
		const saved = field.value && field.value.configured;
		control = (
			<>
				<p className="saddle-field__status">
					{ saved
						? sprintf(
								/* translators: %s: the last characters of a saved key. */
								__( 'Set (%s)', 'saddle' ),
								field.value.hint || ''
						  )
						: __( 'Not set', 'saddle' ) }
				</p>
				<Input
					{ ...common }
					type="password"
					autoComplete="off"
					value={ value }
					error={ !! error }
					placeholder={
						saved
							? __( 'Type a new value to replace it', 'saddle' )
							: ''
					}
					onChange={ ( e ) => onChange( e.target.value ) }
				/>
			</>
		);
	} else {
		control = (
			<Input
				{ ...common }
				type="text"
				value={ value }
				error={ !! error }
				onChange={ ( e ) => onChange( e.target.value ) }
			/>
		);
	}

	const text = (
		<>
			<Label htmlFor={ id }>{ field.label }</Label>
			{ field.help && <Hint id={ helpId }>{ field.help }</Hint> }
		</>
	);

	return (
		<div
			className={ `saddle-field saddle-field--${
				'boolean' === field.type ? 'switch' : 'stacked'
			}` }
		>
			{ 'boolean' === field.type ? (
				<>
					{ control }
					<div className="saddle-field__text">{ text }</div>
				</>
			) : (
				<>
					<div className="saddle-field__text">{ text }</div>
					{ control }
				</>
			) }
			{ error && <ErrorText id={ errorId }>{ error }</ErrorText> }
		</div>
	);
}

/**
 * @param {Object} props
 * @param {string} props.scope  `saddle` or a module key.
 * @param {string} props.screen Optional `area/tab`: only fields for it.
 */
export default function SettingsForm( { scope, screen } ) {
	const [ fields, setFields ] = useState( null );
	const [ loadError, setLoadError ] = useState( '' );
	const [ draft, setDraft ] = useState( {} );
	const [ errors, setErrors ] = useState( {} );
	const [ saving, setSaving ] = useState( false );
	const [ more, setMore ] = useState( () => readOpen( scope ) );

	const shown = useMemo(
		() => renderableFields( fields, screen ),
		[ fields, screen ]
	);
	const split = useMemo( () => splitFields( shown ), [ shown ] );

	const load = useCallback( () => {
		setLoadError( '' );
		api( `preferences/${ encodeURIComponent( scope ) }` )
			.then( ( res ) => {
				const all = res.fields || [];
				setFields( all );
				setDraft( startDraft( renderableFields( all, screen ) ) );
			} )
			.catch( ( e ) => {
				setFields( [] );
				setLoadError(
					e?.message ||
						__( 'Could not load these settings.', 'saddle' )
				);
			} );
	}, [ scope, screen ] );

	useEffect( load, [ load ] );

	// A deep link `#saddle-field-{scope}-{key}` opens the disclosure if it has
	// to, then focuses the control.
	useEffect( () => {
		if ( ! fields ) {
			return undefined;
		}
		const go = () => {
			const field = fieldForHash( window.location.hash, scope, shown );
			if ( ! field ) {
				return;
			}
			if ( needsDisclosure( split, field ) ) {
				setMore( true );
			}
			window.setTimeout( () => {
				const el = document.getElementById(
					fieldId( scope, field.key )
				);
				if ( el ) {
					el.scrollIntoView( { block: 'center' } );
					el.focus();
				}
			}, 60 );
		};
		go();
		window.addEventListener( 'hashchange', go );
		return () => window.removeEventListener( 'hashchange', go );
	}, [ fields, scope, shown, split ] );

	const toggleMore = ( open ) => {
		setMore( open );
		writeOpen( scope, open );
	};

	const setValue = ( key, value ) => {
		setDraft( ( d ) => ( { ...d, [ key ]: value } ) );
		setErrors( ( e ) => {
			if ( ! e[ key ] ) {
				return e;
			}
			const next = { ...e };
			delete next[ key ];
			return next;
		} );
	};

	const discard = () => {
		setDraft( startDraft( shown ) );
		setErrors( {} );
	};

	const save = () => {
		const problems = draftProblems( shown, draft );
		if ( Object.keys( problems ).length ) {
			setErrors(
				Object.fromEntries(
					Object.entries( problems ).map( ( [ key, code ] ) => [
						key,
						problemText(
							shown.find( ( f ) => f.key === key ),
							code
						),
					] )
				)
			);
			return;
		}

		setSaving( true );
		setErrors( {} );
		api( `preferences/${ encodeURIComponent( scope ) }`, {
			method: 'POST',
			data: { values: changedValues( shown, draft ) },
		} )
			.then( ( res ) => {
				const all = res.fields || [];
				setFields( all );
				setDraft( startDraft( renderableFields( all, screen ) ) );
				toast.success( __( 'Settings saved.', 'saddle' ) );
			} )
			.catch( ( e ) => {
				const key = e?.data?.field;
				const message =
					e?.message ||
					__( 'Could not save that setting.', 'saddle' );
				if ( key && shown.some( ( f ) => f.key === key ) ) {
					setErrors( { [ key ]: message } );
					if ( split.advanced.some( ( f ) => f.key === key ) ) {
						setMore( true );
					}
				} else {
					toast.error( message );
				}
			} )
			.finally( () => setSaving( false ) );
	};

	if ( null === fields ) {
		return <Spinner />;
	}

	if ( loadError ) {
		return (
			<Notice tone="danger">
				{ loadError }{ ' ' }
				<Button variant="secondary" size="sm" onClick={ load }>
					{ __( 'Try again', 'saddle' ) }
				</Button>
			</Notice>
		);
	}

	if ( 0 === shown.length ) {
		return (
			<Notice tone="info">
				{ __( 'There are no settings to change here.', 'saddle' ) }
			</Notice>
		);
	}

	const row = ( field ) => (
		<FieldRow
			key={ field.key }
			scope={ scope }
			field={ field }
			value={ draft[ field.key ] }
			error={ errors[ field.key ] }
			busy={ saving }
			onChange={ ( v ) => setValue( field.key, v ) }
		/>
	);

	const writable = agentWritableLabels( shown );
	const permissionsUrl = areaUrl(
		saddleData.areas || [],
		'connections',
		'permissions'
	);

	return (
		<div className="saddle-settings-form" data-scope={ scope }>
			<Card>
				<CardContent>
					<div className="saddle-fields">
						{ split.basic.map( row ) }
					</div>
					{ split.disclosure && (
						<Collapsible
							className="saddle-fields__more"
							open={ more }
							onOpenChange={ toggleMore }
							trigger={ sprintf(
								/* translators: %d: number of advanced settings. */
								__( 'More settings (%d)', 'saddle' ),
								split.advanced.length
							) }
						>
							<div className="saddle-fields">
								{ split.advanced.map( row ) }
							</div>
						</Collapsible>
					) }
				</CardContent>
			</Card>

			<p className="saddle-settings-form__agent">
				{ __( 'Your AI can read these settings.', 'saddle' ) }
				{ writable.length > 0 && (
					<>
						{ ' ' }
						{ sprintf(
							/* translators: %s: a list of setting names. */
							__(
								'It can ask to change %s, and you confirm each change.',
								'saddle'
							),
							writable.join( ', ' )
						) }
					</>
				) }
				{ writable.length > 0 && permissionsUrl && (
					<>
						{ ' ' }
						<a href={ permissionsUrl }>
							{ __( 'Connections → Permissions', 'saddle' ) }
						</a>
					</>
				) }
			</p>

			<ApplyBar
				open={ isDirty( shown, draft ) }
				message={ __( 'You have unsaved changes.', 'saddle' ) }
				saveLabel={ __( 'Save', 'saddle' ) }
				discardLabel={ __( 'Discard', 'saddle' ) }
				saving={ saving }
				onSave={ save }
				onDiscard={ discard }
			/>
		</div>
	);
}
