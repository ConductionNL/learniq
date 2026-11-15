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
 * @spec openspec/specs/competency/spec.md#requirement-the-competency-attainment-roll-up-runs-outside-the-save-that-triggers-it
 */

declare(strict_types=1);

namespace OCA\Learniq\BackgroundJob;

use OCA\Learniq\Kernel\Contracts\CompetencyAttainmentRollupInterface;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs deferred competency roll-up entries.
 *
 * @spec openspec/specs/competency/spec.md#requirement-the-competency-attainment-roll-up-runs-outside-the-save-that-triggers-it
 */
class CompetencyAttainmentRollupJob extends ForwardedJob {

	/**
	 * Constructor.
	 *
	 * @param ActorForwarding $forwarding Clock, session, user manager and tenant context.
	 * @param LoggerInterface $logger Logger.
	 * @param CompetencyAttainmentRollupInterface $rollup The roll-up work.
	 */
	public function __construct(
		ActorForwarding $forwarding,
		LoggerInterface $logger,
		private readonly CompetencyAttainmentRollupInterface $rollup,
	) {
		parent::__construct(forwarding: $forwarding, logger: $logger);
	}//end __construct()

	/**
	 * Run each queued entry; one failed entry does not stop the others.
	 *
	 * @param DeferredListenerContext $context The queued entries and the actor.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-the-competency-attainment-roll-up-runs-outside-the-save-that-triggers-it
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
