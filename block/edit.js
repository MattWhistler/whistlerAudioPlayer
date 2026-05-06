import { __ } from '@wordpress/i18n';
import {
	useBlockProps,
	MediaPlaceholder,
	MediaUploadCheck,
	MediaUpload,
	InspectorControls,
	BlockControls,
} from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	Button,
	ToolbarGroup,
	ToolbarButton,
	Notice,
} from '@wordpress/components';

const ALLOWED_TYPES = [ 'audio' ];

export default function Edit( { attributes, setAttributes } ) {
	const { audioId, audioUrl, audioDuration, ctaText } = attributes;
	const blockProps = useBlockProps( { className: 'asp-block-editor' } );

	const onSelectAudio = ( media ) => {
		if ( ! media || ! media.url ) {
			return;
		}
		setAttributes( {
			audioId: media.id,
			audioUrl: media.url,
			audioDuration: media.fileLength
				? parseDurationString( media.fileLength )
				: ( media.media_details && media.media_details.length_formatted
					? parseDurationString( media.media_details.length_formatted )
					: ( media.media_details && media.media_details.length ) || 0 ),
		} );
	};

	const onRemove = () => {
		setAttributes( { audioId: 0, audioUrl: '', audioDuration: 0 } );
	};

	if ( ! audioUrl ) {
		return (
			<div { ...blockProps }>
				<MediaPlaceholder
					icon="format-audio"
					labels={ {
						title: __( 'Odtwarzacz streszczenia', 'audio-summary-player' ),
						instructions: __(
							'Wybierz plik audio ze streszczeniem artykułu z biblioteki mediów lub prześlij nowy.',
							'audio-summary-player'
						),
					} }
					onSelect={ onSelectAudio }
					accept="audio/*"
					allowedTypes={ ALLOWED_TYPES }
				/>
			</div>
		);
	}

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody title={ __( 'Ustawienia', 'audio-summary-player' ) } initialOpen={ true }>
					<TextControl
						label={ __( 'Tekst CTA (opcjonalny)', 'audio-summary-player' ) }
						help={ __(
							'Pozostaw puste, aby użyć domyślnego tekstu z ustawień wtyczki.',
							'audio-summary-player'
						) }
						value={ ctaText }
						onChange={ ( value ) => setAttributes( { ctaText: value } ) }
					/>
					<MediaUploadCheck>
						<MediaUpload
							onSelect={ onSelectAudio }
							allowedTypes={ ALLOWED_TYPES }
							value={ audioId }
							render={ ( { open } ) => (
								<Button variant="secondary" onClick={ open }>
									{ __( 'Zmień plik audio', 'audio-summary-player' ) }
								</Button>
							) }
						/>
					</MediaUploadCheck>{ ' ' }
					<Button variant="link" isDestructive onClick={ onRemove }>
						{ __( 'Usuń plik', 'audio-summary-player' ) }
					</Button>
				</PanelBody>
			</InspectorControls>

			<BlockControls>
				<ToolbarGroup>
					<MediaUploadCheck>
						<MediaUpload
							onSelect={ onSelectAudio }
							allowedTypes={ ALLOWED_TYPES }
							value={ audioId }
							render={ ( { open } ) => (
								<ToolbarButton
									icon="format-audio"
									label={ __( 'Zmień plik audio', 'audio-summary-player' ) }
									onClick={ open }
								/>
							) }
						/>
					</MediaUploadCheck>
				</ToolbarGroup>
			</BlockControls>

			<div className="asp-player asp-player--editor-preview" aria-hidden="true">
				<button
					type="button"
					className="asp-player__play"
					aria-label={ __( 'Podgląd przycisku odtwarzania', 'audio-summary-player' ) }
				>
					<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
						<path d="M8 5v14l11-7z" fill="currentColor" />
					</svg>
				</button>
				<div className="asp-player__body">
					<div className="asp-player__text">
						{ ctaText || __( 'Odsłuchaj streszczenie', 'audio-summary-player' ) }
					</div>
					<div className="asp-player__progress">
						<div className="asp-player__progress-fill" style={ { width: '0%' } } />
					</div>
					<div className="asp-player__times">
						<span>0:00</span>
						<span>{ formatDuration( audioDuration ) }</span>
					</div>
				</div>
				<button type="button" className="asp-player__speed" aria-label={ __( 'Prędkość', 'audio-summary-player' ) }>
					1×
				</button>
			</div>

			{ audioDuration === 0 && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Czas trwania zostanie odczytany z metadanych pliku przy renderze frontendu.',
						'audio-summary-player'
					) }
				</Notice>
			) }
		</div>
	);
}

function parseDurationString( str ) {
	if ( typeof str !== 'string' ) {
		return Number( str ) || 0;
	}
	const parts = str.split( ':' ).map( Number );
	if ( parts.some( Number.isNaN ) ) return 0;
	if ( parts.length === 3 ) return parts[ 0 ] * 3600 + parts[ 1 ] * 60 + parts[ 2 ];
	if ( parts.length === 2 ) return parts[ 0 ] * 60 + parts[ 1 ];
	return parts[ 0 ] || 0;
}

function formatDuration( seconds ) {
	if ( ! seconds || seconds <= 0 ) return '–:––';
	const s = Math.floor( seconds );
	const m = Math.floor( s / 60 );
	const r = s % 60;
	return `${ m }:${ String( r ).padStart( 2, '0' ) }`;
}
