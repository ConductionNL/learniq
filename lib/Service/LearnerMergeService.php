<?php

/**
 * Learniq Learner Merge Service
 *
 * Moves a merged LearnerProfile's history to the surviving profile (learniq#950).
 *
 * Learner-owned records reference the learner in two ways: by Nextcloud user id
 * (`learnerId` on most schemas) and by LearnerProfile UUID (`learnerRef`, and
 * `learnerId` on Credential, ExternalTrainingRecord and ExemptionCase). Stored
 * data does not always follow the declared shape (the credential bridge copies
 * the enrolment's Nextcloud user id into Credential.learnerId), so every
 * reference field is matched against BOTH the old user id and the old profile
 * UUID, and each match is rewritten to the surviving counterpart.
 *
 * ADR-031 legitimate exception: a cross-object write across ~45 schemas that no
 * declarative schema expression covers.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/parity/capabilities.json#gov-merge-duplicate-accounts
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Validates a learner merge and re-points the merged learner's records.
 */
class LearnerMergeService {

	private const LEARNIQ_REGISTER = 'learniq';
	private const PROFILE_SCHEMA = 'learner-profile';
	private const ENROLMENT_SCHEMA = 'enrolment';

	/**
	 * Enrolment states that still hold a seat in the course.
	 */
	private const OPEN_ENROLMENT_STATES = ['pending', 'active'];

	/**
	 * Every learner-owned schema and the fields on it that name the learner.
	 * Generated from lib/Settings/learniq_register.json: each schema carrying a
	 * `learnerId` or `learnerRef` property.
	 */
	private const LEARNER_OWNED = [
		'credential' => ['learnerId'],
		'lesson-completion' => ['learnerId', 'learnerRef'],
		'enrolment' => ['learnerId', 'learnerRef'],
		'attestation' => ['learnerId'],
		'external-training-record' => ['learnerId', 'learnerRef'],
		'subject-choice' => ['learnerId', 'learnerRef'],
		'exam-accommodation' => ['learnerId'],
		'self-assessment' => ['learnerId'],
		'assessment-result' => ['learnerId'],
		'proctoring-session' => ['learnerId'],
		'grade-entry' => ['learnerId', 'learnerRef'],
		'final-grade' => ['learnerId', 'learnerRef'],
		'report-card' => ['learnerId', 'learnerRef'],
		'report-card-parent-notification' => ['learnerId', 'learnerRef'],
		'competency-attainment' => ['learnerId', 'learnerRef'],
		'exemption-case' => ['learnerId'],
		'learning-plan' => ['learnerId'],
		'support-request' => ['learnerId'],
		'dossier-note' => ['learnerId'],
		'behaviour-incident' => ['learnerId'],
		'wellbeing-check-in' => ['learnerId'],
		'attendance-record' => ['learnerId', 'learnerRef'],
		'excuse-request' => ['learnerId', 'learnerRef'],
		'attendance-flag' => ['learnerId'],
		'bsa-progress-flag' => ['learnerId'],
		'engagement-score' => ['learnerId', 'learnerRef'],
		'engagement-risk-flag' => ['learnerId'],
		'bsa-warning' => ['learnerId'],
		'bsa-decision' => ['learnerId'],
		'grade-notification' => ['learnerId', 'learnerRef'],
		'bpv-placement' => ['learnerId', 'learnerRef'],
		'bpv-visit-report' => ['learnerRef'],
		'conference-signup' => ['learnerId', 'learnerRef'],
		'conference-slot' => ['learnerId', 'learnerRef'],
		'conference-report' => ['learnerId', 'learnerRef'],
		'portfolio' => ['learnerId', 'learnerRef'],
		'portfolio-entry' => ['learnerId'],
		'learning-record-export' => ['learnerId', 'learnerRef'],
		'learning-record-share' => ['learnerId', 'learnerRef'],
		'point-award' => ['learnerId'],
		'learner-engagement' => ['learnerId'],
		'evaluation-invitation' => ['learnerId'],
		'order' => ['learnerId', 'learnerRef'],
		'entitlement' => ['learnerId'],
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OR object access service.
	 * @param LoggerInterface $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Why a merge of $profile into its `mergedInto` target must be refused.
	 *
	 * @param array<string,mixed> $profile The profile being merged.
	 *
	 * @return string|null A reason, or null when the merge may proceed.
	 *
	 * @spec openspec/parity/capabilities.json#gov-merge-duplicate-accounts
	 */
	public function refusalReason(array $profile): ?string {
		$targetId = (string)($profile['mergedInto'] ?? '');
		$ownId = $this->profileId(profile: $profile);
		if ($targetId === '') {
			return 'mergedInto is not set';
		}

		if ($targetId === $ownId) {
			return 'a profile cannot be merged into itself';
		}

		$survivor = $this->loadProfile(id: $targetId);
		if ($survivor === null) {
			return 'the surviving profile does not exist';
		}

		if (($survivor['lifecycle'] ?? 'active') !== 'active') {
			return 'the surviving profile is not active';
		}

		$clashes = $this->conflictingCourses(merged: $profile, survivor: $survivor);
		if ($clashes !== []) {
			return 'both accounts hold an open enrolment in course(s) '.implode(', ', $clashes);
		}

		return null;
	}//end refusalReason()

	/**
	 * Move every learner-owned record from the merged profile to its survivor.
	 *
	 * The profiles themselves are never rewritten, so `mergedInto` stays set on
	 * the merged profile as the audit link. Each save goes through OpenRegister,
	 * which writes the audit trail entry for the moved record.
	 *
	 * @param array<string,mixed> $merged The merged profile (carries mergedInto).
	 *
	 * @return int How many records were moved.
	 *
	 * @spec openspec/parity/capabilities.json#gov-merge-duplicate-accounts
	 */
	public function moveRecords(array $merged): int {
		$survivor = $this->loadProfile(id: (string)($merged['mergedInto'] ?? ''));
		if ($survivor === null) {
			$this->logger->warning('[LearnerMergeService] Surviving profile not found; nothing moved.');
			return 0;
		}

		$rewrites = $this->rewriteMap(merged: $merged, survivor: $survivor);
		if ($rewrites === []) {
			return 0;
		}

		$moved = 0;
		foreach (self::LEARNER_OWNED as $schema => $fields) {
			$moved += $this->moveSchema(schema: $schema, fields: $fields, rewrites: $rewrites);
		}

		$this->logger->info(
			'[LearnerMergeService] Moved {n} record(s) from profile {from} to {to}.',
			['n' => $moved, 'from' => $this->profileId(profile: $merged), 'to' => $this->profileId(profile: $survivor)]
		);

		return $moved;
	}//end moveRecords()

	/**
	 * Old value to new value, for the user id and the profile UUID.
	 *
	 * @param array<string,mixed> $merged   The merged profile.
	 * @param array<string,mixed> $survivor The surviving profile.
	 *
	 * @return array<string,string>
	 */
	private function rewriteMap(array $merged, array $survivor): array {
		$map = [];
		$pairs = [
			[(string)($merged['ncUserId'] ?? ''), (string)($survivor['ncUserId'] ?? '')],
			[$this->profileId(profile: $merged), $this->profileId(profile: $survivor)],
		];
		foreach ($pairs as [$old, $new]) {
			if ($old !== '' && $new !== '' && $old !== $new) {
				$map[$old] = $new;
			}
		}

		return $map;
	}//end rewriteMap()

	/**
	 * Re-point one schema's records.
	 *
	 * @param string               $schema   Schema slug.
	 * @param array<int,string>    $fields   Reference fields on the schema.
	 * @param array<string,string> $rewrites Old value to new value.
	 *
	 * @return int How many records were saved.
	 */
	private function moveSchema(string $schema, array $fields, array $rewrites): int {
		$pending = [];
		foreach ($fields as $field) {
			foreach (array_keys($rewrites) as $old) {
				foreach ($this->findRows(schema: $schema, filters: [$field => $old]) as $row) {
					$rowId = $this->profileId(profile: $row);
					$pending[$rowId] = ($pending[$rowId] ?? $row);
				}
			}
		}

		foreach ($pending as $rowId => $row) {
			foreach ($fields as $field) {
				$current = $row[$field] ?? null;
				if (is_string($current) === true && isset($rewrites[$current]) === true) {
					$row[$field] = $rewrites[$current];
				}
			}

			unset($row['@self']);
			$this->objectService->saveObject(
				object: $row,
				register: self::LEARNIQ_REGISTER,
				schema: $schema,
				uuid: $rowId
			);
			$this->logger->info('[LearnerMergeService] Moved {schema} {id} to the surviving learner.', ['schema' => $schema, 'id' => $rowId]);
		}

		return count($pending);
	}//end moveSchema()

	/**
	 * Courses in which both accounts hold an open enrolment.
	 *
	 * @param array<string,mixed> $merged   The profile being merged.
	 * @param array<string,mixed> $survivor The surviving profile.
	 *
	 * @return array<int,string>
	 */
	private function conflictingCourses(array $merged, array $survivor): array {
		$mergedCourses = $this->openCourses(profile: $merged);
		$survivorCourses = $this->openCourses(profile: $survivor);

		return array_values(array_intersect($mergedCourses, $survivorCourses));
	}//end conflictingCourses()

	/**
	 * Course ids of a profile's open enrolments, matched by user id or profile ref.
	 *
	 * @param array<string,mixed> $profile The profile.
	 *
	 * @return array<int,string>
	 */
	private function openCourses(array $profile): array {
		$courses = [];
		$lookups = [
			['learnerId', (string)($profile['ncUserId'] ?? '')],
			['learnerRef', $this->profileId(profile: $profile)],
		];
		foreach ($lookups as [$field, $value]) {
			if ($value === '') {
				continue;
			}

			foreach ($this->findRows(schema: self::ENROLMENT_SCHEMA, filters: [$field => $value]) as $row) {
				if (in_array($row['lifecycle'] ?? '', self::OPEN_ENROLMENT_STATES, true) === true
					&& (string)($row['courseId'] ?? '') !== ''
				) {
					$courses[] = (string)$row['courseId'];
				}
			}
		}

		return array_values(array_unique($courses));
	}//end openCourses()

	/**
	 * Load a LearnerProfile as an array.
	 *
	 * @param string $id Profile UUID.
	 *
	 * @return array<string,mixed>|null
	 */
	private function loadProfile(string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$entity = $this->objectService->find(id: $id, register: self::LEARNIQ_REGISTER, schema: self::PROFILE_SCHEMA);
		} catch (\Throwable $e) {
			$this->logger->warning('[LearnerMergeService] Profile {id} could not be loaded: {m}', ['id' => $id, 'm' => $e->getMessage()]);
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $entity->jsonSerialize();
	}//end loadProfile()

	/**
	 * Rows of a schema matching the filters, as arrays.
	 *
	 * @param string              $schema  Schema slug.
	 * @param array<string,mixed> $filters Field filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findRows(string $schema, array $filters): array {
		$rows = $this->objectService->findAll(
			[
				'register' => self::LEARNIQ_REGISTER,
				'schema' => $schema,
				'filters' => $filters,
				'limit' => 10000,
			]
		);

		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				$row = $row->jsonSerialize();
			}

			$out[] = $row;
		}

		return $out;
	}//end findRows()

	/**
	 * The object's id, falling back to uuid.
	 *
	 * @param array<string,mixed> $profile Object payload.
	 *
	 * @return string
	 */
	private function profileId(array $profile): string {
		$id = (string)($profile['id'] ?? '');
		if ($id === '') {
			$id = (string)($profile['uuid'] ?? '');
		}

		return $id;
	}//end profileId()
}//end class
