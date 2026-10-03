<?php

/**
 * Learniq Catalogue Messages
 *
 * Words a catalogue refusal for the learner (enrolment-catalogue-self-signup), in the
 * learner's language: in the app and in the portal alike.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Catalogue
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
 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Catalogue;

use OCA\Learniq\AppInfo\Application;
use OCP\IUser;
use OCP\L10N\IFactory;

/**
 * Plain reasons for a refused sign-up or withdrawal.
 *
 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
 */
class CatalogueMessages {

	/**
	 * English source text per reason; translated through l10n.
	 */
	private const TEXTS = [
		'not-found' => 'This course could not be found.',
		'closed' => 'This course is not open for sign-up.',
		'already-signed-up' => 'You are already signed up for this course.',
		'not-withdrawable' => 'You cannot withdraw from this course. Ask your teacher.',
		'no-account' => 'Your school account is not ready for this yet. Ask your school.',
		'not-a-learner' => 'Your account has no learner profile yet. Ask your school or administrator to add one.',
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
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
	 */
	public function message(string $reason, ?IUser $user): string {
		$l10n = $this->l10nFactory->get(Application::APP_ID, $this->l10nFactory->getUserLanguage($user));

		return $l10n->t(self::TEXTS[$reason] ?? self::TEXTS['not-found']);
	}//end message()
}//end class
