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

use OCA\Learniq\AppInfo\Application;
use OCP\IUser;
use OCP\L10N\IFactory;

/**
 * Words a refusal reason for the pupil.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
 */
class PortalMessages {

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
		$l10n = $this->l10nFactory->get(Application::APP_ID, $this->l10nFactory->getUserLanguage($user));

		return $l10n->t(self::TEXTS[$reason] ?? self::TEXTS['not-available']);
	}//end message()
}//end class
