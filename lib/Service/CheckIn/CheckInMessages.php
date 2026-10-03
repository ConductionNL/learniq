<?php

/**
 * Learniq Check-in Messages
 *
 * Words a check-in refusal for the learner (attendance-self-check-in), in the
 * learner's language: in the app and in the portal alike.
 *
 * @category Service
 * @package  OCA\Learniq\Service\CheckIn
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
 * @spec openspec/specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CheckIn;

use OCA\Learniq\AppInfo\Application;
use OCP\IUser;
use OCP\L10N\IFactory;

/**
 * Plain reasons for a refused check-in.
 *
 * @spec openspec/specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code
 */
class CheckInMessages {

	/**
	 * English source text per reason; translated through l10n.
	 */
	private const TEXTS = [
		'not-found' => 'This check-in could not be found.',
		'not-in-group' => 'You are not in this lesson\'s group.',
		'window-closed' => 'This check-in has closed.',
		'invalid-code' => 'This code is not valid. Check the code on the board and try again.',
		'already-recorded' => 'Your attendance is already recorded.',
		'no-account' => 'Your school account is not ready for this yet. Ask your school.',
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
	 * The message for a reason in the learner's language.
	 *
	 * @param string     $reason A refusal reason.
	 * @param IUser|null $user   The learner's account, if any.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code
	 */
	public function message(string $reason, ?IUser $user): string {
		$l10n = $this->l10nFactory->get(Application::APP_ID, $this->l10nFactory->getUserLanguage($user));

		return $l10n->t(self::TEXTS[$reason] ?? self::TEXTS['not-found']);
	}//end message()
}//end class
