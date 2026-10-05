<?php

/**
 * Deferred group-line re-stamp after an enrolment changed.
 *
 * @category BackgroundJob
 * @package  OCA\Learniq\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/school-portals-use-the-new-blocks/specs/portal-contribution/spec.md#requirement-the-guardian-menu-lists-each-child-with-its-group-and-teacher
 */

declare(strict_types=1);

namespace OCA\Learniq\BackgroundJob;

use OCA\Learniq\Service\LearnerGroupLabelRestamp;
use OCA\OpenRegister\BackgroundJob\ActorForwardedJob;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Writes the group line of the pupils whose enrolment changed. LearnerGroupLabelCascade
 * buffers the pupils during the request; this job runs after it (ADR-078).
 *
 * @spec openspec/changes/school-portals-use-the-new-blocks/specs/portal-contribution/spec.md#requirement-the-guardian-menu-lists-each-child-with-its-group-and-teacher
 */
class LearnerGroupLabelRestampJob extends ActorForwardedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory             $time         Clock.
	 * @param IUserSession             $userSession  The acting user, forwarded.
	 * @param IUserManager             $userManager  Resolves the forwarded user.
	 * @param OrganisationService      $organisation The acting organisation, forwarded.
	 * @param LoggerInterface          $logger       Logger.
	 * @param LearnerGroupLabelRestamp $restamp      Writes one profile's line.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		IUserSession $userSession,
		IUserManager $userManager,
		OrganisationService $organisation,
		LoggerInterface $logger,
		private readonly LearnerGroupLabelRestamp $restamp,
	) {
		parent::__construct(
			time: $time,
			userSession: $userSession,
			userManager: $userManager,
			organisation: $organisation,
			logger: $logger
		);
	}//end __construct()

	/**
	 * Re-stamp each buffered pupil once.
	 *
	 * @param DeferredListenerContext $context The buffered entries.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/school-portals-use-the-new-blocks/specs/portal-contribution/spec.md#requirement-the-guardian-menu-lists-each-child-with-its-group-and-teacher
	 */
	protected function runDeferred(DeferredListenerContext $context): void {
		$refs = [];
		foreach ($context->getEntries() as $entry) {
			$ref = ($entry['learnerRef'] ?? null);
			if (is_string($ref) === true && $ref !== '') {
				$refs[$ref] = true;
			}
		}

		foreach (array_keys($refs) as $ref) {
			$this->restamp->restamp(learnerRef: (string)$ref);
		}
	}//end runDeferred()
}//end class
