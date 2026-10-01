/**
 * A REST call that hit a fatal error comes back with WordPress's HTML page
 * message. The Dashboard showed it as "<p>There has been a critical error …
 * </p><p><a href=…>", tags and all.
 */
import { connectionPath, plainError } from '../../admin/src/api';

describe( 'plainError', () => {
	it( 'reduces an HTML message to its words', () => {
		const error = plainError( {
			code: 'internal_server_error',
			message:
				'<p>There has been a critical error on this website.</p><p><a href="https://wordpress.org/documentation/article/faq-troubleshooting/">Learn more about troubleshooting WordPress.</a></p>',
		} );

		expect( error.message ).toBe(
			'There has been a critical error on this website. Learn more about troubleshooting WordPress.'
		);
		expect( error.code ).toBe( 'internal_server_error' );
	} );

	it( 'leaves a plain message alone, angle brackets included', () => {
		const message = 'Use a value < 10 and > 2.';
		expect( plainError( { message } ).message ).toBe( message );
	} );

	it( 'passes through an error without a message', () => {
		expect( plainError( undefined ) ).toBeUndefined();
		expect( plainError( { code: 'x' } ) ).toEqual( { code: 'x' } );
	} );
} );

describe( 'connectionPath', () => {
	it( 'keeps the colon in a connection id', () => {
		expect( connectionPath( 'key:b1a3-9f', 'role' ) ).toBe(
			'connections/key:b1a3-9f/role'
		);
		expect( connectionPath( 'oauth:7' ) ).toBe( 'connections/oauth:7' );
	} );

	it( 'still encodes anything that is not a plain id', () => {
		expect( connectionPath( 'key:a/b?c', 'role' ) ).toBe(
			'connections/key:a%2Fb%3Fc/role'
		);
	} );
} );
