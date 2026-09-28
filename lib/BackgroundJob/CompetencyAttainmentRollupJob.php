<?php

/**
 * Learniq Competency Attainment Roll-up Job
 *
 * The deferred half of CompetencyAttainmentRollupHandler (gate 61, ADR-078):
 * the handler only queues what happened, and this job, running as the actor
 * who caused it, does the reads and writes: the werkproces competencyId
 * resolution and the CompetencyAttainment upserts. Nothing reads either back
 * in the request that triggered it, so inline bought only a slower write.
 *
 * @category BackgroundJob
 * @package  OCA\Learniq\BackgroundJob
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
 * @spec openspec/changes/grading-rollup-followups/specs/competency/spec.md#requirement-the-competency-attainment-roll-up-runs-outside-the-save-that-triggers-it
 */

declare(strict_types=1);

namespace OCA\Learniq\BackgroundJob;

use OCA\Learniq\Service\CompetencyAttainmentRollup;
use OCA\OpenRegister\BackgroundJob\ActorForwardedJob;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs deferred competency roll-up entries.
 *
 * @spec openspec/changes/grading-rollup-followups/specs/competency/spec.md#requirement-the-competency-attainment-roll-up-runs-outside-the-save-that-triggers-it
 */
class CompetencyAttainmentRollupJob extends ActorForwardedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param IUserSession $userSession User session, for the actor.
	 * @param IUserManager $userManager User manager, for the actor.
	 * @param OrganisationService $organisation The actor's organisation.
	 * @param LoggerInterface $logger Logger.
	 * @param CompetencyAttainmentRollup $rollup The roll-up work.
	 */
	public function __construct(
		ITimeFactory $time,
		IUserSession $userSession,
		IUserManager $userManager,
		OrganisationService $organisation,
		LoggerInterface $logger,
		private readonly CompetencyAttainmentRollup $rollup,
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
	 * Run each queued entry; one failed entry does not stop the others.
	 *
	 * @param DeferredListenerContext $context The queued entries and the actor.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/grading-rollup-followups/specs/competency/spec.md#requirement-the-competency-attainment-roll-up-runs-outside-the-save-that-triggers-it
	 */
	protected function runDeferred(DeferredListenerContext $context): void {
		foreach ($context->getEntries() as $entry) {
			$kind = (string)($entry['kind'] ?? '');
			$object = $entry['object'] ?? null;
			if ($kind === '' || is_array($object) === false) {
				continue;
			}

			try {
				$this->rollup->run(kind: $kind, object: $object);
			} catch (Throwable $e) {
				$this->logger->warning(
					message: '[CompetencyAttainmentRollupJob] Roll-up failed for an entry',
					context: ['kind' => $kind, 'id' => ($object['id'] ?? ''), 'error' => $e->getMessage()]
				);
			}
		}
	}//end runDeferred()
}//end class
