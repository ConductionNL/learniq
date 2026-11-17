<?php

/**
 * Learniq Portal Messages
 *
 * The pupil-facing message for a refused portal assessment step, in the
 * pupil's own Nextcloud language. Portaliq shows a `message` as is.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\PortalMessagesInterface;
use OCP\IUser;
use OCP\L10N\IFactory;

/**
 * Words a refusal reason for the pupil.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
 */
class PortalMessages implements PortalMessagesInterface {

	/**
	 * English source text per reason; translated through l10n.
	 */
	private const TEXTS = [
		'not-available' => 'This test is not open for you right now.',
		'not-open' => 'This test is not open yet.',
		'closed' => 'This test is closed.',
		'attempts-used' => 'You have used all your attempts for this test.',
		'proctored' => 'You can only take this test in the supervised test screen at school.',
		'access-code-required' => 'This test needs an access code. Ask the person supervising the test.',
		'access-code-wrong' => 'The access code is not correct.',
		'attempt-closed' => 'This test is already handed in.',
		'no-account' => 'Your school account is not ready for tests yet. Ask your school.',
		'submission-not-found' => 'This work could not be found.',
		'already-handed-in' => 'This work is already handed in.',
		'late-not-accepted' => 'The deadline has passed and this assignment does not accept late work.',
		'hand-in-refused' => 'This work cannot be handed in right now. Ask your teacher.',
		// A day of BPV hours (bpv-hours-per-day); the last four are HourDayLines' refusals.
		'hour-day-incomplete' => 'Choose your placement and the day, as YYYY-MM-DD.',
		'hour-day-not-your-placement' => 'You can only write hours for your own placement.',
		'hour-week-with-trainer' => 'This week is with your workplace trainer or already approved, so its days cannot change.',
		'hour-day-outside-week' => 'This day is not part of the week.',
		'hour-day-times-invalid' => 'Fill in when you started and when you stopped, as HH:MM. You stop after you start.',
		'hour-day-break-too-long' => 'Your break is as long as the time you worked, or longer.',
		'hour-day-invalid' => 'This day could not be read. Fill it in again.',
	];

	/**
	 * Constructor.
	 *
	 * @param IFactory $l10nFactory Nextcloud translations.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IFactory $l10nFactory,
	) {
	}//end __construct()

	/**
	 * The message for a reason, in the pupil's language (the default language
	 * when there is no pupil account).
	 *
	 * @param string $reason A refusal reason.
	 * @param IUser|null $user The pupil's account, if any.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function message(string $reason, ?IUser $user): string {
		$l10n = $this->l10nFactory->get(AppIdentity::APP_ID, $this->l10nFactory->getUserLanguage($user));

		return $l10n->t(self::TEXTS[$reason] ?? self::TEXTS['not-available']);
	}//end message()
}//end class
