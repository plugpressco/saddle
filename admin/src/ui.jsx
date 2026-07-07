/**
 * Saddle UI = @plugpress/ui (shared PlugPress design system) exposed under the
 * `@wordpress/components` API the app already uses. This keeps ~90 existing
 * call sites unchanged — only the import source moves from
 * '@wordpress/components' to '../ui' (or './ui' in App.jsx).
 *
 * New code should import from '@plugpress/ui' directly.
 */
import {
	Button as PPButton,
	Notice as PPNotice,
	Spinner as PPSpinner,
	Dialog,
	Switch,
	Input,
	Textarea,
	Field,
} from '@plugpress/ui';

/* ---- Button: map WP variant/size/state props to the design system ---- */

const VARIANT_MAP = {
	primary: 'primary',
	secondary: 'secondary',
	tertiary: 'ghost',
	link: 'link',
};

export function Button( {
	variant,
	isPrimary,
	isSecondary,
	isTertiary,
	isLink,
	isDestructive,
	size,
	isBusy,
	icon,
	iconPosition = 'left',
	className,
	children,
	style,
	...props
} ) {
	// Resolve the legacy boolean variant flags to a single variant name.
	let resolved = variant;
	if ( ! resolved ) {
		if ( isPrimary ) resolved = 'primary';
		else if ( isSecondary ) resolved = 'secondary';
		else if ( isTertiary ) resolved = 'tertiary';
		else if ( isLink ) resolved = 'link';
	}

	let ppVariant = VARIANT_MAP[ resolved ] || 'secondary';
	// A destructive filled/tertiary button becomes the danger variant; a
	// destructive *link* stays a link but painted with the danger token.
	const linkDanger = isDestructive && ppVariant === 'link';
	if ( isDestructive && ! linkDanger ) {
		ppVariant = 'danger';
	}

	return (
		<PPButton
			variant={ ppVariant }
			size={ 'small' === size ? 'sm' : 'md' }
			loading={ !! isBusy }
			className={ className }
			style={ linkDanger ? { color: 'var(--pp-danger)', ...style } : style }
			{ ...props }
		>
			{ icon && 'left' === iconPosition ? icon : null }
			{ children }
			{ icon && 'right' === iconPosition ? icon : null }
		</PPButton>
	);
}

/* ---- Notice: map status→tone; render WP `actions` as child links ---- */

const TONE_MAP = {
	error: 'danger',
	warning: 'warning',
	success: 'success',
	info: 'info',
};

export function Notice( {
	status = 'info',
	isDismissible,
	onRemove,
	actions,
	children,
	className,
	...props
} ) {
	return (
		<PPNotice
			tone={ TONE_MAP[ status ] || 'info' }
			onDismiss={ isDismissible && onRemove ? onRemove : undefined }
			className={ className }
			{ ...props }
		>
			{ children }
			{ Array.isArray( actions ) && actions.length > 0 && (
				<div className="saddle-notice__actions">
					{ actions.map( ( a, i ) =>
						a.url ? (
							<a key={ i } href={ a.url } className="pp-btn pp-btn--link pp-btn--sm">
								{ a.label }
							</a>
						) : (
							<Button key={ i } variant="link" size="small" onClick={ a.onClick }>
								{ a.label }
							</Button>
						)
					) }
				</div>
			) }
		</PPNotice>
	);
}

/* ---- Spinner ---- */

export function Spinner( props ) {
	return <PPSpinner { ...props } />;
}

/* ---- Modal → Dialog (title, onRequestClose, size, children) ---- */

export function Modal( {
	title,
	onRequestClose,
	size = 'medium',
	className,
	children,
	...props
} ) {
	const sizeMap = { small: 'sm', medium: 'md', large: 'lg', fill: 'lg' };
	return (
		<Dialog
			open
			onOpenChange={ ( isOpen ) => ! isOpen && onRequestClose && onRequestClose() }
			title={ title }
			size={ sizeMap[ size ] || 'md' }
			className={ className }
			{ ...props }
		>
			{ children }
		</Dialog>
	);
}

/* WP control props that must not reach the DOM. */
const stripWpProps = ( {
	// eslint-disable-next-line camelcase, no-unused-vars
	__nextHasNoMarginBottom,
	// eslint-disable-next-line camelcase, no-unused-vars
	__next40pxDefaultSize,
	// eslint-disable-next-line no-unused-vars
	hideLabelFromVision,
	...rest
} ) => rest;

/* ---- ToggleControl → Switch + label/help row ---- */

export function ToggleControl( { label, help, checked, onChange, disabled, ...props } ) {
	return (
		<div className="saddle-toggle-row">
			<Switch
				checked={ !! checked }
				onChange={ ( next ) => onChange && onChange( next ) }
				disabled={ disabled }
				aria-label={ typeof label === 'string' ? label : undefined }
				{ ...stripWpProps( props ) }
			/>
			{ ( label || help ) && (
				<span className="saddle-toggle-row__text">
					{ label && <span className="saddle-toggle-row__label">{ label }</span> }
					{ help && <span className="saddle-toggle-row__help">{ help }</span> }
				</span>
			) }
		</div>
	);
}

/* ---- TextControl / TextareaControl → Field-wrapped Input/Textarea ---- */

export function TextControl( { label, help, value, onChange, type = 'text', hideLabelFromVision, ...props } ) {
	const clean = stripWpProps( props );
	if ( hideLabelFromVision ) {
		return (
			<Input
				type={ type }
				aria-label={ typeof label === 'string' ? label : undefined }
				value={ value }
				onChange={ ( e ) => onChange && onChange( e.target.value ) }
				{ ...clean }
			/>
		);
	}
	return (
		<Field label={ label } hint={ help }>
			{ ( a11y ) => (
				<Input
					{ ...a11y }
					type={ type }
					value={ value }
					onChange={ ( e ) => onChange && onChange( e.target.value ) }
					{ ...clean }
				/>
			) }
		</Field>
	);
}

export function TextareaControl( { label, help, value, onChange, rows, hideLabelFromVision, ...props } ) {
	const clean = stripWpProps( props );
	if ( hideLabelFromVision ) {
		return (
			<Textarea
				rows={ rows }
				aria-label={ typeof label === 'string' ? label : undefined }
				value={ value }
				onChange={ ( e ) => onChange && onChange( e.target.value ) }
				{ ...clean }
			/>
		);
	}
	return (
		<Field label={ label } hint={ help }>
			{ ( a11y ) => (
				<Textarea
					{ ...a11y }
					rows={ rows }
					value={ value }
					onChange={ ( e ) => onChange && onChange( e.target.value ) }
					{ ...clean }
				/>
			) }
		</Field>
	);
}
