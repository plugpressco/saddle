/**
 * UnsplashKeyCard — the owner's Unsplash Access Key, next to the Integrations
 * tools it unlocks on the Permissions page.
 *
 * The key never comes back from the server: the settings endpoint only says
 * whether one is configured plus a last-4 hint, so this card is either an
 * entry form (not configured / replacing) or a masked status row.
 */
import { useState, useEffect } from '@wordpress/element';
import {
	Button,
	Card,
	CardHeader,
	CardContent,
	Field,
	Input,
	useConfirm,
	toast,
} from '@plugpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../api';

export default function UnsplashKeyCard() {
	const confirm = useConfirm();
	const [ unsplash, setUnsplash ] = useState( null );
	const [ draft, setDraft ] = useState( '' );
	const [ replacing, setReplacing ] = useState( false );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		api( 'settings' )
			.then( ( res ) =>
				setUnsplash(
					res.unsplash || { configured: false, key_hint: '' }
				)
			)
			.catch( () => setUnsplash( { configured: false, key_hint: '' } ) );
	}, [] );

	const save = ( key ) => {
		setSaving( true );
		api( 'settings', {
			method: 'POST',
			data: { unsplash_access_key: key },
		} )
			.then( ( res ) => {
				setUnsplash(
					res.unsplash || { configured: false, key_hint: '' }
				);
				setDraft( '' );
				setReplacing( false );
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

	if ( ! unsplash ) {
		return null;
	}

	const showForm = ! unsplash.configured || replacing;

	return (
		<Card className="saddle-unsplash">
			<CardHeader
				title={ __( 'Unsplash stock photos', 'saddle' ) }
				description={ __(
					'Lets your AI search Unsplash and import photos into the media library, with photographer credit added automatically.',
					'saddle'
				) }
			/>
			<CardContent>
				{ showForm ? (
					<>
						<Field label={ __( 'Access Key', 'saddle' ) }>
							{ ( a11y ) => (
								<Input
									{ ...a11y }
									type="password"
									value={ draft }
									onChange={ ( e ) =>
										setDraft( e.target.value )
									}
									placeholder={ __(
										'Paste your Unsplash Access Key…',
										'saddle'
									) }
									autoComplete="off"
								/>
							) }
						</Field>
						<p className="saddle-unsplash__hint">
							{ __(
								'Free from unsplash.com/developers (the Access Key, not the Secret Key). It is stored only on this site and sent only to Unsplash — never anywhere else.',
								'saddle'
							) }
						</p>
						<div className="saddle-unsplash__actions">
							<Button
								variant="secondary"
								onClick={ () => save( draft.trim() ) }
								loading={ saving }
								disabled={ saving || ! draft.trim() }
							>
								{ __( 'Save key', 'saddle' ) }
							</Button>
							{ replacing && (
								<Button
									variant="ghost"
									onClick={ () => {
										setReplacing( false );
										setDraft( '' );
									} }
									disabled={ saving }
								>
									{ __( 'Cancel', 'saddle' ) }
								</Button>
							) }
						</div>
					</>
				) : (
					<div className="saddle-unsplash__configured">
						<span>
							{ sprintf(
								/* translators: %s: last four characters of the key. */
								__(
									'Configured — key ends in ••••%s.',
									'saddle'
								),
								unsplash.key_hint
							) }
						</span>
						<div className="saddle-unsplash__actions">
							<Button
								variant="ghost"
								onClick={ () => setReplacing( true ) }
								disabled={ saving }
							>
								{ __( 'Replace', 'saddle' ) }
							</Button>
							<Button
								variant="ghost"
								onClick={ remove }
								loading={ saving }
								disabled={ saving }
							>
								{ __( 'Remove', 'saddle' ) }
							</Button>
						</div>
					</div>
				) }
			</CardContent>
		</Card>
	);
}
