/**
 * UnsplashKeyRow — the Unsplash row in the Integrations block, with the
 * owner's Access Key one click away.
 *
 * The key never comes back from the server: the settings endpoint only says
 * whether one is configured plus a last-4 hint. So the row shows "Add key" (no
 * key) or the masked key with "Change", and either opens the same small form
 * directly under the row.
 */
import { useState, useEffect } from '@wordpress/element';
import {
	Badge,
	Button,
	Field,
	Input,
	Row,
	useConfirm,
	toast,
} from '@plugpress/ui';
import { __, sprintf, _n } from '@wordpress/i18n';
import { api } from '../api';

/**
 * @param {Object} props
 * @param {number} props.tools Number of Unsplash tools Saddle registers.
 */
export default function UnsplashKeyRow( { tools } ) {
	const confirm = useConfirm();
	const [ unsplash, setUnsplash ] = useState( null );
	const [ draft, setDraft ] = useState( '' );
	const [ editing, setEditing ] = useState( false );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		api( 'preferences' )
			.then( ( res ) =>
				setUnsplash(
					res.unsplash || { configured: false, key_hint: '' }
				)
			)
			.catch( () => setUnsplash( { configured: false, key_hint: '' } ) );
	}, [] );

	const close = () => {
		setEditing( false );
		setDraft( '' );
	};

	const save = ( key ) => {
		setSaving( true );
		api( 'preferences', {
			method: 'POST',
			data: { unsplash_access_key: key },
		} )
			.then( ( res ) => {
				setUnsplash(
					res.unsplash || { configured: false, key_hint: '' }
				);
				close();
				toast.success(
					key
						? __( 'Unsplash key saved.', 'saddle' )
						: __( 'Unsplash key removed.', 'saddle' )
				);
			} )
			.catch( ( e ) => toast.error( e.message ) )
			.finally( () => setSaving( false ) );
	};

	const remove = async () => {
		const ok = await confirm( {
			title: __( 'Remove the Unsplash key?', 'saddle' ),
			description: __(
				'Your AI loses stock-photo search and import until a new key is added. Photos already in the media library are kept.',
				'saddle'
			),
			danger: true,
			confirmLabel: __( 'Remove', 'saddle' ),
			cancelLabel: __( 'Cancel', 'saddle' ),
		} );
		if ( ok ) {
			save( '' );
		}
	};

	const configured = !! unsplash?.configured;

	return (
		<div className="saddle-unsplash">
			<Row
				title={ __( 'Unsplash stock photos', 'saddle' ) }
				description={
					sprintf(
						/* translators: %d: number of tools. */
						_n( '%d tool', '%d tools', tools, 'saddle' ),
						tools
					) +
					' · ' +
					__( 'Built in', 'saddle' )
				}
				actions={
					unsplash && (
						<>
							{ configured && (
								<span className="saddle-unsplash__key">
									{ sprintf(
										/* translators: %s: last four characters of the key. */
										__( 'Key ····%s', 'saddle' ),
										unsplash.key_hint
									) }
								</span>
							) }
							{ ! configured && (
								<Badge tone="warning">
									{ __( 'No key', 'saddle' ) }
								</Badge>
							) }
							{ ! editing && (
								<Button
									variant="link"
									size="sm"
									onClick={ () => setEditing( true ) }
								>
									{ configured
										? __( 'Change', 'saddle' )
										: __( 'Add key', 'saddle' ) }
								</Button>
							) }
						</>
					)
				}
			/>
			{ editing && (
				<div className="saddle-unsplash__form">
					<Field
						label={ __( 'Access Key', 'saddle' ) }
						hint={ __(
							'Free at unsplash.com/developers. Use the Access Key, not the Secret Key.',
							'saddle'
						) }
					>
						{ ( a11y ) => (
							<Input
								{ ...a11y }
								type="password"
								value={ draft }
								onChange={ ( e ) => setDraft( e.target.value ) }
								placeholder={ __(
									'Paste your Unsplash Access Key…',
									'saddle'
								) }
								autoComplete="off"
							/>
						) }
					</Field>
					<div className="saddle-unsplash__actions">
						<Button
							variant="secondary"
							size="sm"
							onClick={ () => save( draft.trim() ) }
							loading={ saving }
							disabled={ saving || ! draft.trim() }
						>
							{ __( 'Save', 'saddle' ) }
						</Button>
						<Button
							variant="ghost"
							size="sm"
							onClick={ close }
							disabled={ saving }
						>
							{ __( 'Cancel', 'saddle' ) }
						</Button>
						{ configured && (
							<Button
								variant="link"
								size="sm"
								className="saddle-link-danger"
								onClick={ remove }
								disabled={ saving }
							>
								{ __( 'Remove key', 'saddle' ) }
							</Button>
						) }
					</div>
				</div>
			) }
		</div>
	);
}
