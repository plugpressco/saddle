/**
 * The Context page's fields live inside the one instructions text as
 * `### Heading` sections (#274). Nothing an owner wrote before may be lost.
 */
import { parseFields, serializeFields } from '../../admin/src/context-fields';

describe( 'context fields', () => {
	it( 'keeps instructions written before the page existed', () => {
		const legacy = 'Always save new posts as drafts.\nBe friendly.';
		const values = parseFields( legacy );

		expect( values.other ).toBe( legacy );
		expect( values.about ).toBe( '' );
		expect( serializeFields( values ) ).toBe(
			`### Other instructions\n${ legacy }`
		);
	} );

	it( 'reads each field from its heading', () => {
		const values = parseFields(
			'### About this site\nA bakery.\n\n### Rules\nDrafts only.\nNo homepage edits.'
		);

		expect( values.about ).toBe( 'A bakery.' );
		expect( values.rules ).toBe( 'Drafts only.\nNo homepage edits.' );
		expect( values.goal ).toBe( '' );
	} );

	it( 'round-trips what it writes', () => {
		const values = {
			about: 'A bakery in Leeds.',
			goal: '',
			voice: 'Warm and short.',
			rules: 'Drafts only.',
			other: 'Ask before deleting anything.\n### My own note\nkept',
		};
		const stored = serializeFields( values );

		expect( stored ).not.toContain( 'Current goal' );
		expect( parseFields( stored ) ).toEqual( values );
		expect( serializeFields( parseFields( stored ) ) ).toBe( stored );
	} );

	it( 'matches headings whatever their case', () => {
		expect( parseFields( '### voice and STYLE\nShort.' ).voice ).toBe(
			'Short.'
		);
	} );

	it( 'stores nothing for empty fields', () => {
		expect( serializeFields( parseFields( '' ) ) ).toBe( '' );
		expect( serializeFields( { about: '   ' } ) ).toBe( '' );
	} );
} );
