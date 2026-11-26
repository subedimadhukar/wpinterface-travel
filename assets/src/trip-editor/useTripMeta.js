/**
 * Reads and edits the trip's meta through the block editor's store, so
 * changes save with the post's own Save/Update button.
 *
 * The meta box renders outside the editor's entity context, so the post
 * type and ID are passed explicitly.
 */
import { useSelect } from '@wordpress/data';
import { useEntityProp } from '@wordpress/core-data';
import { store as editorStore } from '@wordpress/editor';

/**
 * @return {[Object, Function]} Meta object and a setter: set( key, value ).
 */
export default function useTripMeta() {
	const { postType, postId } = useSelect( ( select ) => {
		const editor = select( editorStore );
		return {
			postType: editor.getCurrentPostType(),
			postId: editor.getCurrentPostId(),
		};
	}, [] );
	const [ meta, setMeta ] = useEntityProp(
		'postType',
		postType,
		'meta',
		postId
	);
	const current = meta || {};

	const set = ( key, value ) => setMeta( { ...current, [ key ]: value } );

	return [ current, set ];
}
