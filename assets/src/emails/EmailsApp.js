/**
 * Sender settings and templates. Saves through POST /settings.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { IntegerField, TextField } from '../components/fields';

const BASE = '/wpinterface-travel/v1';

function Template( { template, values, onChange } ) {
	const [ open, setOpen ] = useState( false );
	const [ preview, setPreview ] = useState( null );
	const [ message, setMessage ] = useState( null );
	const draft = {
		subject: values.subject ?? template.defaults.subject,
		body: values.body ?? template.defaults.body,
	};

	const call = async ( action ) => {
		setMessage( null );
		try {
			const result = await apiFetch( {
				path: `${ BASE }/emails/${ template.id }/${ action }`,
				method: 'POST',
				data: draft,
			} );
			if ( action === 'preview' ) {
				setPreview( result );
			} else {
				setMessage( {
					status: 'success',
					text: sprintf(
						/* translators: %s: email address. */
						__( 'Test email sent to %s.', 'wpinterface-travel' ),
						result.sent_to
					),
				} );
			}
		} catch ( e ) {
			setMessage( { status: 'error', text: e.message } );
		}
	};

	const insertTag = ( tag ) =>
		onChange( 'body', `${ draft.body }{${ tag }}` );

	return (
		<Card className="wpitravel-email" size="small">
			<CardHeader>
				<div>
					<strong>{ template.title }</strong>
					<div className="wpitravel-email__meta">
						{ template.recipient === 'admin'
							? __( 'To staff', 'wpinterface-travel' )
							: __(
									'To the customer',
									'wpinterface-travel'
							  ) }{ ' ' }
						· { template.description }
					</div>
				</div>
				<div className="wpitravel-email__controls">
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'On', 'wpinterface-travel' ) }
						checked={ values.enabled !== false }
						onChange={ ( value ) => onChange( 'enabled', value ) }
					/>
					<Button
						variant="secondary"
						size="compact"
						aria-expanded={ open }
						onClick={ () => setOpen( ! open ) }
					>
						{ open
							? __( 'Close', 'wpinterface-travel' )
							: __( 'Edit', 'wpinterface-travel' ) }
					</Button>
				</div>
			</CardHeader>
			{ open && (
				<CardBody className="wpitravel-email__body">
					<TextField
						label={ __( 'Subject', 'wpinterface-travel' ) }
						value={ draft.subject }
						onChange={ ( value ) => onChange( 'subject', value ) }
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Message', 'wpinterface-travel' ) }
						help={ __(
							'Plain text or basic HTML. Blank lines start new paragraphs.',
							'wpinterface-travel'
						) }
						rows={ 10 }
						value={ draft.body }
						onChange={ ( value ) => onChange( 'body', value ) }
					/>
					<details className="wpitravel-email__tags">
						<summary>
							{ __(
								'Merge tags (click to add)',
								'wpinterface-travel'
							) }
						</summary>
						<ul>
							{ Object.entries( template.tags ).map(
								( [ tag, label ] ) => (
									<li key={ tag }>
										<Button
											variant="link"
											onClick={ () => insertTag( tag ) }
										>
											{ `{${ tag }}` }
										</Button>{ ' ' }
										{ label }
									</li>
								)
							) }
						</ul>
					</details>
					<div className="wpitravel-email__actions">
						<Button
							variant="secondary"
							onClick={ () => call( 'preview' ) }
						>
							{ __( 'Preview', 'wpinterface-travel' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ () => call( 'test' ) }
						>
							{ __( 'Send me a test', 'wpinterface-travel' ) }
						</Button>
						<Button
							variant="tertiary"
							isDestructive
							onClick={ () => {
								onChange(
									'subject',
									template.defaults.subject
								);
								onChange( 'body', template.defaults.body );
							} }
						>
							{ __(
								'Restore default text',
								'wpinterface-travel'
							) }
						</Button>
					</div>
					{ message && (
						<Notice
							status={ message.status }
							isDismissible={ false }
						>
							{ message.text }
						</Notice>
					) }
					{ preview && (
						<div className="wpitravel-email__preview">
							<p>
								<strong>
									{ __( 'Subject:', 'wpinterface-travel' ) }
								</strong>{ ' ' }
								{ preview.subject }
							</p>
							<iframe
								title={ __(
									'Email preview',
									'wpinterface-travel'
								) }
								// Scripts stay blocked (no allow-scripts); same-origin only lets it render.
								sandbox="allow-same-origin"
								srcDoc={ preview.html }
							/>
						</div>
					) }
				</CardBody>
			) }
		</Card>
	);
}

export default function EmailsApp( { config } ) {
	const [ emails, setEmails ] = useState( config.emails );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const set = ( key, value ) =>
		setEmails( ( current ) => ( { ...current, [ key ]: value } ) );

	const setTemplate = ( id, key, value ) =>
		setEmails( ( current ) => ( {
			...current,
			templates: {
				...current.templates,
				[ id ]: {
					...( current.templates[ id ] || {} ),
					[ key ]: value,
				},
			},
		} ) );

	const save = async () => {
		setSaving( true );
		setNotice( null );
		try {
			const response = await apiFetch( {
				path: `${ BASE }/settings`,
				method: 'POST',
				data: { emails },
			} );
			setEmails( response.settings.emails );
			setNotice( {
				status: 'success',
				text: __( 'Emails saved.', 'wpinterface-travel' ),
			} );
		} catch ( e ) {
			setNotice( { status: 'error', text: e.message } );
		}
		setSaving( false );
	};

	const group = ( recipient ) =>
		config.templates
			.filter( ( template ) => template.recipient === recipient )
			.map( ( template ) => (
				<Template
					key={ template.id }
					template={ template }
					values={ emails.templates[ template.id ] || {} }
					onChange={ ( key, value ) =>
						setTemplate( template.id, key, value )
					}
				/>
			) );

	return (
		<div className="wpitravel-emails">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }

			<Card>
				<CardHeader>
					<h2>{ __( 'Sender', 'wpinterface-travel' ) }</h2>
				</CardHeader>
				<CardBody className="wpitravel-email__body">
					<div className="wpitravel-grid">
						<TextField
							label={ __( '"From" name', 'wpinterface-travel' ) }
							placeholder={ config.siteName }
							value={ emails.from_name }
							onChange={ ( value ) => set( 'from_name', value ) }
						/>
						<TextField
							label={ __(
								'"From" address',
								'wpinterface-travel'
							) }
							help={ __(
								'Use an address on your own domain, or emails may land in spam. Empty = WordPress default.',
								'wpinterface-travel'
							) }
							type="email"
							value={ emails.from_email }
							onChange={ ( value ) => set( 'from_email', value ) }
						/>
					</div>
					<TextField
						label={ __(
							'Staff notifications go to',
							'wpinterface-travel'
						) }
						help={ __(
							'One or more addresses, separated by commas.',
							'wpinterface-travel'
						) }
						placeholder={ config.adminEmails }
						value={ emails.admin_recipients }
						onChange={ ( value ) =>
							set( 'admin_recipients', value )
						}
					/>
					<div className="wpitravel-grid">
						<IntegerField
							label={ __(
								'Trip reminder (days before departure)',
								'wpinterface-travel'
							) }
							help={ __(
								'0 = no reminder.',
								'wpinterface-travel'
							) }
							max={ 60 }
							value={ emails.reminder_days }
							onChange={ ( value ) =>
								set( 'reminder_days', value )
							}
						/>
						<IntegerField
							label={ __(
								'Review request (days after the trip)',
								'wpinterface-travel'
							) }
							max={ 60 }
							value={ emails.review_delay_days }
							onChange={ ( value ) =>
								set( 'review_delay_days', value )
							}
						/>
					</div>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __(
							'Footer (address, phone, licence number…)',
							'wpinterface-travel'
						) }
						rows={ 3 }
						value={ emails.footer }
						onChange={ ( value ) => set( 'footer', value ) }
					/>
				</CardBody>
			</Card>

			<h2>{ __( 'Emails to customers', 'wpinterface-travel' ) }</h2>
			{ group( 'customer' ) }
			<h2>{ __( 'Emails to staff', 'wpinterface-travel' ) }</h2>
			{ group( 'admin' ) }

			<div className="wpitravel-emails__save">
				<Button
					variant="primary"
					isBusy={ saving }
					disabled={ saving }
					onClick={ save }
				>
					{ __( 'Save emails', 'wpinterface-travel' ) }
				</Button>
			</div>
		</div>
	);
}
