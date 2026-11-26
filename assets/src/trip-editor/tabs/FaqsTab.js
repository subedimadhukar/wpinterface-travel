/**
 * Trip-specific questions and answers.
 */
import { __, sprintf } from '@wordpress/i18n';
import Repeater from '../../components/Repeater';
import { TextField, TextareaField } from '../../components/fields';

export default function FaqsTab( { meta, set } ) {
	return (
		<Repeater
			items={ meta._wpitravel_faqs || [] }
			onChange={ ( items ) => set( '_wpitravel_faqs', items ) }
			newItem={ () => ( { question: '', answer: '' } ) }
			addLabel={ __( 'Add question', 'wpinterface-travel' ) }
			emptyText={ __( 'No questions yet.', 'wpinterface-travel' ) }
			itemLabel={ ( faq, index ) =>
				faq.question ||
				sprintf(
					/* translators: %d: question number. */
					__( 'Question %d', 'wpinterface-travel' ),
					index + 1
				)
			}
			renderItem={ ( faq, update ) => (
				<>
					<TextField
						label={ __( 'Question', 'wpinterface-travel' ) }
						value={ faq.question }
						onChange={ ( question ) => update( { question } ) }
					/>
					<TextareaField
						label={ __( 'Answer', 'wpinterface-travel' ) }
						value={ faq.answer }
						onChange={ ( answer ) => update( { answer } ) }
					/>
				</>
			) }
		/>
	);
}
