import { registerBlockType } from '@wordpress/blocks';
import edit from './edit';
import metadata from './block.json';

registerBlockType( metadata.name, {
	edit,
	save: () => null, // server-side render
} );
