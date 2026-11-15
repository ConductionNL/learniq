<?php

/**
 * Learniq BackfillRequiredLearnerRefs
 *
 * ExcuseRequest and ConferenceSignup now require `learnerRef`
 * (absence-and-booking-fields-are-required). OpenRegister validates the whole
 * object on every update, so a stored row without one would refuse every later
 * write, such as a teacher approving an absence report or the school planning a
 * conversation. This step writes the pupil's learner profile on each such row,
 * resolved from `learnerId`. A row whose pupil has no learner profile is
 * counted and left as it is. Idempotent: a row that has a `learnerRef` is not
 * saved, so a second run saves nothing. Runs without a session, so every read
 * and write passes `_rbac: false` and `_multitenancy: false`.
 *
 * @category Repair
 * @package  OCA\Learniq\Repair
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
 * @spec openspec/specs/attendance/spec.md#requirement-stored-rows-get-the-pupils-learner-profile-before-it-is-required
 */

declare(strict_types=1);

namespace OCA\Learniq\Repair;

use OCA\Learniq\Kernel\Contracts\LearnerRefResolverInterface;
use OCA\Learniq\Repair\Support\RepairRowWalk;
use OCA\Learniq\Repair\Support\RepairTally;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Back-fills `learnerRef` on stored absence reports and conference signups.
 *
 * @spec openspec/specs/attendance/spec.md#requirement-stored-rows-get-the-pupils-learner-profile-before-it-is-required
 */
class BackfillRequiredLearnerRefs implements IRepairStep {

	private const REGISTER = 'learniq';

	private const SCHEMAS = ['excuse-request', 'conference-signup'];

	/**
	 * Pages through the schemas this step back-fills.
	 *
	 * @var RepairRowWalk
	 */
	private readonly RepairRowWalk $rows;

	/**
	 * Constructor.
	 *
	 * @param ObjectService      $objectService OpenRegister object access.
	 * @param LearnerRefResolverInterface $learnerRefs   Nextcloud user id to learner profile uuid.
	 * @param LoggerInterface    $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LearnerRefResolverInterface $learnerRefs,
		private readonly LoggerInterface $logger,
	) {
		$this->rows = new RepairRowWalk(objectService: $objectService, logger: $logger, label: 'BackfillRequiredLearnerRefs');
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/attendance/spec.md#requirement-stored-rows-get-the-pupils-learner-profile-before-it-is-required
	 */
	public function getName(): string {
		return 'Write the pupil\'s learner profile on stored absence reports and conference signups that lack one';
	}//end getName()

	/**
	 * Back-fill every covered schema.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/attendance/spec.md#requirement-stored-rows-get-the-pupils-learner-profile-before-it-is-required
	 */
	public function run(IOutput $output): void {
		$cache = [];
		foreach (self::SCHEMAS as $schema) {
			$counts = $this->backfill(schema: $schema, cache: $cache);
			$output->info(
				'BackfillRequiredLearnerRefs ' . $schema . ': ' . $counts->countOf(key: 'stamped') . ' stamped, ' . $counts->countOf(key: 'noProfile')
				. ' without a learner profile, ' . $counts->countOf(key: 'failed') . ' failed, of ' . $counts->countOf(key: 'scanned') . ' scanned.'
			);
		}
	}//end run()

	/**
	 * Back-fill one schema.
	 *
	 * @param string                     $schema The schema slug.
	 * @param array<string, string|null> $cache  User id to profile uuid, per run.
	 *
	 * @return RepairTally
	 */
	private function backfill(string $schema, array &$cache): RepairTally {
		return $this->rows->walkNamingSchema(
			schema: $schema,
			visit: function (array $row) use ($schema, &$cache): ?string {
				return $this->stampRow(schema: $schema, row: $row, cache: $cache);
			}
		);
	}//end backfill()

	/**
	 * Write the learner profile on one row that lacks it.
	 *
	 * @param string                     $schema The schema slug.
	 * @param array<string, mixed>       $row    The row.
	 * @param array<string, string|null> $cache  User id to profile uuid, per run.
	 *
	 * @return string|null Counter to bump, or null when the row needed nothing.
	 */
	private function stampRow(string $schema, array $row, array &$cache): ?string {
		$candidate = $this->candidate(row: $row);
		if ($candidate === null) {
			return null;
		}

		[$learnerId, $uuid] = $candidate;
		try {
			if (array_key_exists($learnerId, $cache) === false) {
				$cache[$learnerId] = $this->learnerRefs->resolveAcrossTenants(learnerId: $learnerId);
			}

			if ($cache[$learnerId] === null) {
				return 'noProfile';
			}

			// The `@self` block would make OpenRegister check the acting
			// user's folder rights, which a session-less step never has.
			$object = array_merge($row, ['learnerRef' => $cache[$learnerId]]);
			unset($object['@self']);

			$this->objectService->saveObject(
				object: $object,
				register: self::REGISTER,
				schema: $schema,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[BackfillRequiredLearnerRefs] Could not write {schema} {id}: {msg}',
				['schema' => $schema, 'id' => $uuid, 'msg' => $exception->getMessage()]
			);
			return 'failed';
		}//end try

		return 'stamped';
	}//end stampRow()

	/**
	 * The learnerId and uuid of a row that still needs a learnerRef, or null
	 * when it already has one or lacks either value.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function candidate(array $row): ?array {
		$existing = ($row['learnerRef'] ?? null);
		if (is_string($existing) === true && $existing !== '') {
			return null;
		}

		$learnerId = ($row['learnerId'] ?? null);
		$uuid = ($row['id'] ?? ($row['uuid'] ?? null));
		if (is_string($learnerId) === false || $learnerId === '' || is_string($uuid) === false || $uuid === '') {
			return null;
		}

		return [$learnerId, $uuid];
	}//end candidate()
}//end class
