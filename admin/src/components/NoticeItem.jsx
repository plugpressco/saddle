/**
 * One notice, from Saddle_Notices or from the app itself (#276).
 *
 * Drawn the same in the frame's slot and in the bell, so a notice does not
 * change how it looks when a more severe one pushes it out of the slot.
 */
import { Notice, Button } from '@plugpress/ui';
import { TONES } from '../notices';

/**
 * @param {Object}    props
 * @param {Object}    props.notice    `{ id, severity, message, action, dismiss }`.
 *                                    `action` is `{ label, url }` or
 *                                    `{ label, onClick }`.
 * @param {Function=} props.onDismiss Called with the notice; shown only when set.
 * @param {string=}   props.className
 */
export default function NoticeItem( { notice, onDismiss, className } ) {
	const { action } = notice;

	return (
		<Notice
			tone={ TONES[ notice.severity ] || 'info' }
			className={ className }
			onDismiss={ onDismiss ? () => onDismiss( notice ) : undefined }
			data-saddle-notice={ notice.id }
		>
			{ notice.message }
			{ action && (
				<span className="saddle-notice__actions">
					<Button
						variant="link"
						size="sm"
						href={ action.url || undefined }
						onClick={ action.onClick }
					>
						{ action.label }
					</Button>
				</span>
			) }
		</Notice>
	);
}
