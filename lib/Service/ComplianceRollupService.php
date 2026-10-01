<?php

/**
 * Learniq Compliance Roll-up Service
 *
 * Rolls compliance up per department (learniq#951). Every active
 * LearnerProfile counts towards its own department and every level above it
 * ('Operations/Infra/Team A' also counts towards 'Operations/Infra' and
 * 'Operations'), so a team, its department and its directorate each get:
 *
 * - learners: active profiles at or under the level;
 * - obligations: learner x regulation pairs where the regulation's audience
 *   scope covers the learner (RegulationAudienceResolver);
 * - covered: obligations with a signed attestation, valid credential or
 *   verified external training (ExternalTrainingService::isLearnerCovered);
 * - coveragePercent: covered / obligations, null when there are none;
 * - upcomingDeadlines / overdue: open mandatory enrolments due within the
 *   window, or already past their due date;
 * - expiredCredentials: credentials past `expiresAt` or in the `expired` state.
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
 * @spec openspec/parity/capabilities.json#comp-roll-up-by-department
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Per-department compliance figures, aggregated up the department path.
 */
class ComplianceRollupService {

	private const LEARNIQ_REGISTER = 'learniq';
	private const OPEN_ENROLMENT_STATES = ['pending', 'active'];
	private const UPCOMING_WINDOW_DAYS = 30;
	private const PAGE_LIMIT = 10000;


	/**
	 * Constructor.
	 *
	 * @param ObjectService              $objectService   OR object access.
	 * @param RegulationAudienceResolver $audience        Audience-scope predicate.
	 * @param ExternalTrainingService    $trainingService Coverage predicate.
	 * @param RunningExemptions          $runningExemptions The exemptions that run on a day.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly RegulationAudienceResolver $audience,
		private readonly ExternalTrainingService $trainingService,
		private readonly RunningExemptions $runningExemptions=new RunningExemptions(),
	) {
	}//end __construct()

	/**
	 * The roll-up, one row per department level, sorted by path.
	 *
	 * @param DateTimeImmutable|null $now Evaluation instant (injectable for tests).
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/parity/capabilities.json#comp-roll-up-by-department
	 */
	public function byDepartment(?DateTimeImmutable $now=null): array {
		$now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));
		$horizon = $now->add(new DateInterval('P'.self::UPCOMING_WINDOW_DAYS.'D'));

		$population = $this->population();
		$regulations = $population['regulations'];
		$exemptions = $population['exemptions'];
		$enrolments = $this->rows(schema: 'enrolment', filters: ['mandatory' => true]);
		$credentials = $this->rows(schema: 'credential');

		$nodes = [];
		foreach ($population['learners'] as $profile) {
			$figures = $this->learnerFigures(
				profile: $profile,
				regulations: $regulations,
				enrolments: $enrolments,
				credentials: $credentials,
				exemptions: $exemptions,
				now: $now,
				horizon: $horizon
			);

			foreach ($this->audience->departmentLevels(department: (string)($profile['department'] ?? '')) as $level) {
				$nodes[$level] = $this->addFigures(node: ($nodes[$level] ?? $this->emptyNode(department: $level)), figures: $figures);
			}
		}

		ksort($nodes, SORT_STRING);

		return array_values(array_map(fn (array $node): array => $this->finishNode(node: $node), $nodes));
	}//end byDepartment()

	/**
	 * Who and what a coverage count runs over: the published, enforced
	 * regulations, the active learner profiles (not merged or archived) and
	 * the granted exemptions (RunningExemptions decides which run on a day).
	 *
	 * @return array{regulations:array<int,array<string,mixed>>,learners:array<int,array<string,mixed>>,exemptions:array<int,array<string,mixed>>}
	 * @spec openspec/changes/compliance-rule-coverage-table/specs/compliance-rule-coverage/spec.md#requirement-per-rule-coverage-table
	 */
	public function population(): array {
		return [
			'regulations' => array_values(
				array_filter(
					$this->rows(schema: 'regulation'),
					static fn (array $reg): bool => ($reg['lifecycle'] ?? '') === 'published' && ($reg['active'] ?? true) !== false
				)
			),
			'learners' => array_values(
				array_filter(
					$this->rows(schema: 'learner-profile'),
					static fn (array $profile): bool => ($profile['lifecycle'] ?? 'active') === 'active'
				)
			),
			'exemptions' => $this->rows(schema: 'regulation-exemption', filters: ['lifecycle' => 'granted']),
		];
	}//end population()

	/**
	 * One learner's contribution to every level of their department.
	 *
	 * @param array<string,mixed>            $profile     The learner.
	 * @param array<int,array<string,mixed>> $regulations Published regulations.
	 * @param array<int,array<string,mixed>> $enrolments  Mandatory enrolments.
	 * @param array<int,array<string,mixed>> $credentials All credentials.
	 * @param array<int,array<string,mixed>> $exemptions  Granted regulation exemptions.
	 * @param DateTimeImmutable              $now         Evaluation instant.
	 * @param DateTimeImmutable              $horizon     End of the upcoming window.
	 *
	 * @return array<string,int>
	 */
	private function learnerFigures(
		array $profile,
		array $regulations,
		array $enrolments,
		array $credentials,
		array $exemptions,
		DateTimeImmutable $now,
		DateTimeImmutable $horizon,
	): array {
		$keys = $this->learnerKeys(profile: $profile);
		$excusedFrom = $this->runningExemptions->regulationsFor(keys: $keys, exemptions: $exemptions, now: $now);
		$regulationFigures = $this->regulationFigures(profile: $profile, keys: $keys, regulations: $regulations, excusedFrom: $excusedFrom, now: $now);
		$deadlineFigures = $this->deadlineFigures(keys: $keys, enrolments: $enrolments, now: $now, horizon: $horizon);

		$expired = 0;
		foreach ($credentials as $credential) {
			if ($this->ownedBy(row: $credential, keys: $keys) === true && $this->isExpired(credential: $credential, now: $now) === true) {
				$expired++;
			}
		}

		$figures = ['learners' => 1] + $regulationFigures + $deadlineFigures + ['expiredCredentials' => $expired];

		return $figures;
	}//end learnerFigures()

	/**
	 * Obligations, covered obligations and excused rules for one learner. A
	 * rule the learner holds a running exemption from is not an obligation:
	 * it is counted as excused, so an auditor sees it.
	 *
	 * @param array<string,mixed>            $profile     The learner.
	 * @param array<int,string>              $keys        The learner's keys.
	 * @param array<int,array<string,mixed>> $regulations Published regulations.
	 * @param array<string,true>             $excusedFrom Regulation slugs the learner is exempt from today.
	 * @param DateTimeImmutable              $now         Evaluation instant.
	 *
	 * @return array{obligations: int, covered: int, excused: int}
	 *
	 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#requirement-exemptions-in-the-roll-up
	 */
	private function regulationFigures(array $profile, array $keys, array $regulations, array $excusedFrom, DateTimeImmutable $now): array {
		$figures = ['obligations' => 0, 'covered' => 0, 'excused' => 0];
		foreach ($regulations as $regulation) {
			if ($this->audience->covers(regulation: $regulation, profile: $profile) === false) {
				continue;
			}

			if (isset($excusedFrom[(string)($regulation['slug'] ?? '')]) === true) {
				$figures['excused']++;
				continue;
			}

			$figures['obligations']++;
			if ($this->isCovered(keys: $keys, regulationSlug: (string)($regulation['slug'] ?? ''), now: $now) === true) {
				$figures['covered']++;
			}
		}

		return $figures;
	}//end regulationFigures()

	/**
	 * Upcoming and overdue open mandatory enrolments for one learner.
	 *
	 * @param array<int,string>              $keys       The learner's keys.
	 * @param array<int,array<string,mixed>> $enrolments Mandatory enrolments.
	 * @param DateTimeImmutable              $now        Evaluation instant.
	 * @param DateTimeImmutable              $horizon    End of the upcoming window.
	 *
	 * @return array{upcomingDeadlines: int, overdue: int}
	 */
	private function deadlineFigures(array $keys, array $enrolments, DateTimeImmutable $now, DateTimeImmutable $horizon): array {
		$figures = ['upcomingDeadlines' => 0, 'overdue' => 0];
		foreach ($enrolments as $enrolment) {
			if ($this->ownedBy(row: $enrolment, keys: $keys) === false
				|| in_array($enrolment['lifecycle'] ?? '', self::OPEN_ENROLMENT_STATES, true) === false
			) {
				continue;
			}

			$due = $this->parseDate(value: $enrolment['dueDate'] ?? null);
			if ($due !== null && $due < $now) {
				$figures['overdue']++;
			} else if ($due !== null && $due <= $horizon) {
				$figures['upcomingDeadlines']++;
			}
		}

		return $figures;
	}//end deadlineFigures()

	/**
	 * Whether the learner is covered under any of their keys. Stored evidence
	 * names the learner by profile UUID or by Nextcloud user id (the credential
	 * bridge copies the enrolment's user id), so both are asked.
	 *
	 * @param array<int,string> $keys           The learner's keys.
	 * @param string            $regulationSlug Regulation slug.
	 * @param DateTimeImmutable $now            Evaluation instant.
	 *
	 * @return bool
	 * @spec openspec/changes/compliance-rule-coverage-table/specs/compliance-rule-coverage/spec.md#requirement-per-rule-coverage-table
	 */
	public function isCovered(array $keys, string $regulationSlug, DateTimeImmutable $now): bool {
		foreach ($keys as $key) {
			if ($this->trainingService->isLearnerCovered(learnerId: $key, regulationSlug: $regulationSlug, now: $now) === true) {
				return true;
			}
		}

		return false;
	}//end isCovered()

	/**
	 * The profile UUID and the Nextcloud user id, leaving out empty ones.
	 *
	 * @param array<string,mixed> $profile The learner.
	 *
	 * @return array<int,string>
	 * @spec openspec/changes/compliance-rule-coverage-table/specs/compliance-rule-coverage/spec.md#requirement-per-rule-coverage-table
	 */
	public function learnerKeys(array $profile): array {
		$keys = [(string)($profile['id'] ?? ($profile['uuid'] ?? '')), (string)($profile['ncUserId'] ?? '')];

		return array_values(array_filter($keys, static fn (string $key): bool => $key !== ''));
	}//end learnerKeys()

	/**
	 * Whether a record names the learner by UUID or user id.
	 *
	 * @param array<string,mixed> $row  The record.
	 * @param array<int,string>   $keys The learner's keys.
	 *
	 * @return bool
	 */
	private function ownedBy(array $row, array $keys): bool {
		foreach (['learnerRef', 'learnerId'] as $field) {
			$value = $row[$field] ?? null;
			if (is_string($value) === true && $value !== '' && in_array($value, $keys, true) === true) {
				return true;
			}
		}

		return false;
	}//end ownedBy()

	/**
	 * Whether a credential has lapsed (revoked ones are not counted as expired).
	 *
	 * @param array<string,mixed> $credential The credential.
	 * @param DateTimeImmutable   $now        Evaluation instant.
	 *
	 * @return bool
	 */
	private function isExpired(array $credential, DateTimeImmutable $now): bool {
		$state = (string)($credential['lifecycle'] ?? '');
		if ($state === 'expired') {
			return true;
		}

		if ($state === 'revoked') {
			return false;
		}

		$expiresAt = $this->parseDate(value: $credential['expiresAt'] ?? null);

		return $expiresAt !== null && $expiresAt < $now;
	}//end isExpired()

	/**
	 * Parse an ISO date, or null when absent or unparsable.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function parseDate(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
			return null;
		}

		try {
			return new DateTimeImmutable($value, new DateTimeZone('UTC'));
		} catch (\Exception) {
			return null;
		}
	}//end parseDate()

	/**
	 * A zeroed node for a department level.
	 *
	 * @param string $department The level's path ('' for no department).
	 *
	 * @return array<string,mixed>
	 */
	private function emptyNode(string $department): array {
		// Levels of '' are [''], so no department has depth 0 and no parent.
		$levels = $this->audience->departmentLevels(department: $department);
		$depth  = count($levels) - 1;
		$parent = ($levels[$depth - 1] ?? null);

		return [
			'department' => $department,
			'parent' => $parent,
			'depth' => $depth,
			'learners' => 0,
			'obligations' => 0,
			'covered' => 0,
			'excused' => 0,
			'upcomingDeadlines' => 0,
			'overdue' => 0,
			'expiredCredentials' => 0,
		];
	}//end emptyNode()

	/**
	 * Add one learner's figures to a node.
	 *
	 * @param array<string,mixed> $node    The node.
	 * @param array<string,int>   $figures The learner's figures.
	 *
	 * @return array<string,mixed>
	 */
	private function addFigures(array $node, array $figures): array {
		foreach ($figures as $key => $value) {
			$node[$key] += $value;
		}

		return $node;
	}//end addFigures()

	/**
	 * Add the coverage percentage to a finished node.
	 *
	 * @param array<string,mixed> $node The node.
	 *
	 * @return array<string,mixed>
	 */
	private function finishNode(array $node): array {
		$node['coveragePercent'] = null;
		if ($node['obligations'] > 0) {
			$node['coveragePercent'] = round(100 * $node['covered'] / $node['obligations'], 1);
		}

		return $node;
	}//end finishNode()

	/**
	 * Rows of a schema as arrays.
	 *
	 * @param string              $schema  Schema slug.
	 * @param array<string,mixed> $filters Field filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rows(string $schema, array $filters=[]): array {
		$config = [
			'filters' => array_merge(
				$filters,
				[
					'register' => self::LEARNIQ_REGISTER,
					'schema' => $schema,
				]
			),
			'limit' => self::PAGE_LIMIT,
		];

		$out = [];
		foreach ($this->objectService->findAll($config) as $row) {
			if (is_array($row) === false) {
				$row = $row->jsonSerialize();
			}

			$out[] = $row;
		}

		return $out;
	}//end rows()
}//end class
