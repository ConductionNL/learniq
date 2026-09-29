<?php

/**
 * Learniq Work Group Messages
 *
 * Words a work group refusal for the learner (enrolment-self-join-work-group), in the
 * learner's language: in the app and in the portal alike.
 *
 * @category Service
 * @package  OCA\Learniq\Service\WorkGroup
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
 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\WorkGroup;

use OCA\Learniq\AppInfo\Application;
use OCP\IUser;
use OCP\L10N\IFactory;

/**
 * Plain reasons for a refused join or leave.
 *
 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
 */
class WorkGroupMessages {

	/**
	 * English source text per reason; translated through l10n.
	 */
	private const TEXTS = [
		'not-found' => 'This work group could not be found.',
		'not-in-cohort' => 'You are not in the group these work groups belong to.',
		'sign-up-closed' => 'Sign-up for these work groups has closed. Ask your teacher.',
		'full' => 'This work group is full.',
		'not-a-member' => 'You are not in this work group.',
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
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
	 */
	public function message(string $reason, ?IUser $user): string {
		$l10n = $this->l10nFactory->get(Application::APP_ID, $this->l10nFactory->getUserLanguage($user));

		return $l10n->t(self::TEXTS[$reason] ?? self::TEXTS['not-found']);
	}//end message()
}//end class
