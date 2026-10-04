/**
 * Skills on Context (1.5.0 release QA P14): an upload whose name exists asks
 * first, and an edit in place saves the skill under its own name.
 */
import {
	skillMarkdown,
	skillName,
	skillSlug,
	skillToReplace,
} from '../../admin/src/skills-logic';

const FILE = `---
name: Publish Post
description: How we publish a post
---

1. Read the draft.
`;

describe( 'skillName', () => {
	it( 'reads the name from the frontmatter, as stored', () => {
		expect( skillName( FILE ) ).toBe( 'publish-post' );
		expect( skillName( FILE.replace( /\n/g, '\r\n' ) ) ).toBe(
			'publish-post'
		);
	} );

	it( 'is empty for a file with no frontmatter or no name', () => {
		expect( skillName( '# Just a heading' ) ).toBe( '' );
		expect( skillName( '---\ndescription: x\n---\nbody' ) ).toBe( '' );
		expect( skillName( undefined ) ).toBe( '' );
	} );

	it( 'reads only the frontmatter, not a name: line in the body', () => {
		const md = '---\ndescription: x\n---\nname: not-this';
		expect( skillName( md ) ).toBe( '' );
	} );
} );

describe( 'skillSlug', () => {
	it( 'matches what sanitize_title keeps for plain names', () => {
		expect( skillSlug( '  SEO Checklist ' ) ).toBe( 'seo-checklist' );
		expect( skillSlug( 'café_menu!' ) ).toBe( 'cafe_menu' );
		expect( skillSlug( 'a -- b' ) ).toBe( 'a-b' );
	} );
} );

describe( 'skillToReplace', () => {
	const skills = [
		{ name: 'publish-post', builtin: false },
		{ name: 'build-page', builtin: true },
	];

	it( 'finds the owner skill an upload would replace', () => {
		expect( skillToReplace( skills, FILE ) ).toEqual( skills[ 0 ] );
	} );

	it( 'finds the built-in skill an upload would stand in for', () => {
		const md = '---\nname: build-page\ndescription: x\n---\nbody';
		expect( skillToReplace( skills, md ) ).toEqual( skills[ 1 ] );
	} );

	it( 'is null for a new name or an unreadable file', () => {
		const md = '---\nname: brand-new\ndescription: x\n---\nbody';
		expect( skillToReplace( skills, md ) ).toBeNull();
		expect( skillToReplace( skills, 'no frontmatter' ) ).toBeNull();
		expect( skillToReplace( undefined, FILE ) ).toBeNull();
	} );
} );

describe( 'skillMarkdown', () => {
	it( 'keeps the name and description over the new text', () => {
		const md = skillMarkdown(
			{
				name: 'publish-post',
				description: 'How we publish a post',
				when_to_use: '',
			},
			'  1. Read it again.\n2. Publish.  '
		);
		expect( md ).toBe(
			'---\nname: publish-post\ndescription: How we publish a post\n---\n\n1. Read it again.\n2. Publish.\n'
		);
		// It reads back as the same skill.
		expect( skillName( md ) ).toBe( 'publish-post' );
	} );

	it( 'keeps when to use, on one line', () => {
		const md = skillMarkdown(
			{
				name: 'seo',
				description: 'Our SEO\nchecklist',
				when_to_use: 'Before publishing',
			},
			'Body'
		);
		expect( md ).toContain( 'description: Our SEO checklist\n' );
		expect( md ).toContain( 'when_to_use: Before publishing\n' );
	} );

	it( 'keeps angle-bracket placeholders as they are', () => {
		expect(
			skillMarkdown( { name: 'x', description: 'y' }, 'Use <id> here.' )
		).toContain( 'Use <id> here.' );
	} );
} );
