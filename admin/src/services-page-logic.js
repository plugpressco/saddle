/**
 * An account's line on Settings → Accounts (1.5.0 release QA P23; the
 * Services page until #369), on top of services-logic.js. No React, no
 * network.
 */
import { __, sprintf } from '@wordpress/i18n';
import { statusLabel } from './services-logic';

/**
 * A row's line under its name: the status, and for an outside account what
 * it is for first ("Stock photos · Not set up"), since its name alone may not
 * say.
 *
 * @param {Object} record A service record.
 * @return {string} Line.
 */
export function rowLine( record ) {
	const status = statusLabel( record );
	const what =
		record && 'account' === record.kind
			? String( record.description || '' ).trim()
			: '';
	if ( ! what ) {
		return status;
	}
	if ( ! status ) {
		return what;
	}
	return sprintf(
		/* translators: 1: what a service is for, such as "Stock photos", 2: its status, such as "Not set up". */
		__( '%1$s · %2$s', 'saddle' ),
		what,
		status
	);
}
