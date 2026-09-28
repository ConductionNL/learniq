<?php

/**
 * Learniq Planninq Timetable Import
 *
 * The `timetable-import` job when planninq is the timetable source (decision
 * D10: planninq owns the timetable, the integriq rostering adapter delivers
 * into it, learniq reads from it). Learniq no longer fetches or writes a
 * single `Session` here. It asks integriq, through integriq's typed event
 * `OCA\Integriq\Event\RosterImportRequestedEvent` (contract v1, integriq change
 * `rostering-adapter-targets-planninq`), to deliver the job's rostering source
 * into planninq, and turns integriq's answer into the job's result. After the
 * job concludes, it runs the conflict scan on the lessons planninq now holds.
 *
 * The event class is looked up by name, so learniq runs without integriq
 * (ADR-041); an absent class, a silent integriq or a failed delivery fails
 * the job with a readable message and writes nothing.
 *
 * Which rostering source: `scope.rosterSource` when it names one of the four,
 * else the vendor of the job's mapping profile (`targetSchema`
 * `Zermelo:Appointment` becomes `roster-zermelo`, and so on), so jobs made
 * before this change keep working.
 *
 * @category Timetabling
 * @package  OCA\Learniq\Timetabling
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
 * @spec openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-a-timetable-import-job-delivers-into-planninq-when-planninq-is-the-source-req-003
 */

declare(strict_types=1);

namespace OCA\Learniq\Timetabling;

use OCA\Learniq\Service\TimetableExchangeSettings;
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Delivers a timetable-import job's rostering source into planninq via integriq.
 *
 * @spec openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-a-timetable-import-job-delivers-into-planninq-when-planninq-is-the-source-req-003
 */
class PlanninqTimetableImport {

	public const INTEGRIQ_EVENT = 'OCA\\Integriq\\Event\\RosterImportRequestedEvent';

	/**
	 * Mapping-profile vendor to integriq rostering Source row id.
	 */
	public const VENDOR_SOURCES = [
		'zermelo' => 'roster-zermelo',
		'untis' => 'roster-untis-oneroster',
		'xedule' => 'roster-xedule',
		'timeedit' => 'roster-timeedit',
	];

	/**
	 * Days ahead the conflict scan reads when the job names no window.
	 */
	private const SCAN_DAYS = 14;

	/**
	 * Constructor.
	 *
	 * @param TimetableSourceResolver   $sources          Says whether planninq is the source; reads its lessons.
	 * @param IEventDispatcher          $dispatcher       Dispatches integriq's event.
	 * @param TimetableConflictDetector $conflictDetector Scans the delivered lessons.
	 * @param LoggerInterface           $logger           PSR logger.
	 * @param TimetableExchangeSettings $groupMaps        The group code maps the administrator keeps per rostering system.
	 * @param string                    $eventClass       Integriq's event class name (tests only).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TimetableSourceResolver $sources,
		private readonly IEventDispatcher $dispatcher,
		private readonly TimetableConflictDetector $conflictDetector,
		private readonly LoggerInterface $logger,
		private readonly TimetableExchangeSettings $groupMaps,
		private readonly string $eventClass = self::INTEGRIQ_EVENT,
	) {
	}//end __construct()

	/**
	 * Whether a timetable-import job goes to planninq on this instance.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-a-timetable-import-job-delivers-into-planninq-when-planninq-is-the-source-req-003
	 */
	public function applies(): bool {
		return $this->sources->usesPlanninq();
	}//end applies()

	/**
	 * Ask integriq to deliver the job's rostering source into planninq.
	 *
	 * @param array<string,mixed>      $job     The DataExchangeJob data.
	 * @param array<string,mixed>|null $profile The job's DataMappingProfile, or null.
	 *
	 * @return array{state:string,fields:array<string,mixed>} The transition to apply and the job fields to save.
	 *
	 * @throws RuntimeException With a readable message when the delivery cannot happen or failed.
	 *
	 * @spec openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-a-timetable-import-job-delivers-into-planninq-when-planninq-is-the-source-req-003
	 */
	public function deliver(array $job, ?array $profile): array {
		$systemId = $this->rosterSource(job: $job, profile: $profile);
		if ($systemId === null) {
			throw new RuntimeException(
				'This job does not say which rostering system to read. Set scope.rosterSource, '
				. 'or use a Zermelo, Untis, Xedule or TimeEdit mapping profile.'
			);
		}

		if (class_exists($this->eventClass) === false) {
			throw new RuntimeException('Integriq is not installed, so the timetable cannot be delivered to planninq.');
		}

		$scope = $this->scope(job: $job);
		$options = [
			'groupMap' => $this->stringMap(value: ($scope['groupMap'] ?? [])),
			'teacherMap' => $this->stringMap(value: ($scope['teacherMap'] ?? [])),
		];

		// Named arguments work on a dynamic class name, and they are the
		// contract: a renamed parameter in integriq fails here, loudly.
		$event = new ($this->eventClass)(
			sourceApp: 'learniq',
			systemId: $systemId,
			options: $options,
			correlationId: (string)($job['id'] ?? ($job['uuid'] ?? '')),
		);
		$this->dispatcher->dispatchTyped($event);

		$result = $event->getResult();
		if ($event->isHandled() === false || is_array($result) === false) {
			throw new RuntimeException('Integriq did not answer the timetable delivery.');
		}

		if (($result['status'] ?? '') !== 'delivered') {
			throw new RuntimeException(
				sprintf(
					'Integriq could not deliver the timetable to planninq (%s): %s',
					(string)($result['errorCode'] ?? 'unknown'),
					(string)($result['error'] ?? '')
				)
			);
		}

		return $this->outcome(systemId: $systemId, delivery: $result);
	}//end deliver()

	/**
	 * Scan the lessons planninq holds for the job's mapped cohorts and teachers.
	 *
	 * A failed read is logged, not thrown: the delivery already succeeded, and
	 * a conflict scan that cannot run must not undo it.
	 *
	 * @param array<string,mixed> $job The DataExchangeJob data.
	 *
	 * @return int Number of lessons scanned.
	 *
	 * @spec openspec/changes/sessions-from-planninq/specs/timetable-source/spec.md#requirement-conflict-detection-runs-on-the-adapters-lessons-req-004
	 */
	public function scanConflicts(array $job): int {
		$scope = $this->scope(job: $job);
		$from = (string)($scope['from'] ?? date('c'));
		$to = (string)($scope['to'] ?? date('c', (time() + (self::SCAN_DAYS * 86400))));
		$planninq = $this->sources->planninq();

		try {
			$lessons = $planninq->sessionsForCohorts(
				cohortIds: array_values($this->stringMap(value: ($scope['groupMap'] ?? []))),
				from: $from,
				to: $to
			);
			foreach (array_unique(array_values($this->stringMap(value: ($scope['teacherMap'] ?? [])))) as $teacher) {
				$lessons = array_merge($lessons, $planninq->sessionsForTeacher(userId: $teacher, from: $from, to: $to));
			}
		} catch (RuntimeException $e) {
			$this->logger->warning(
				'[PlanninqTimetableImport] Conflict scan skipped: {msg}',
				['msg' => $e->getMessage()]
			);
			return 0;
		}

		$byId = [];
		foreach ($lessons as $lesson) {
			$byId[(string)($lesson['id'] ?? '')] = $lesson;
		}

		unset($byId['']);
		if ($byId === []) {
			return 0;
		}

		$this->conflictDetector->scanWindow(sessions: array_values($byId), tenantId: (string)($job['tenant_id'] ?? ''));

		return count($byId);
	}//end scanConflicts()

	/**
	 * The integriq rostering Source row id this job reads.
	 *
	 * @param array<string,mixed>      $job     The DataExchangeJob data.
	 * @param array<string,mixed>|null $profile The job's DataMappingProfile, or null.
	 *
	 * @return string|null
	 */
	private function rosterSource(array $job, ?array $profile): ?string {
		$explicit = (string)($this->scope(job: $job)['rosterSource'] ?? '');
		if (in_array($explicit, self::VENDOR_SOURCES, true) === true) {
			return $explicit;
		}

		$hint = strtolower((string)($profile['targetSchema'] ?? '') . ' ' . (string)($profile['name'] ?? ''));
		foreach (self::VENDOR_SOURCES as $vendor => $systemId) {
			if (str_contains($hint, $vendor) === true) {
				return $systemId;
			}
		}

		return null;
	}//end rosterSource()

	/**
	 * Turn integriq's delivery result into the job's transition and fields.
	 *
	 * @param string              $systemId The rostering Source row id.
	 * @param array<string,mixed> $delivery Integriq's `delivered` result.
	 *
	 * @return array{state:string,fields:array<string,mixed>}
	 */
	private function outcome(string $systemId, array $delivery): array {
		$planninq = (array)($delivery['planninq'] ?? []);
		$processed = (int)($planninq['processed'] ?? 0);
		$accepted = ((int)($planninq['created'] ?? 0) + (int)($planninq['updated'] ?? 0) + (int)($planninq['unchanged'] ?? 0));
		$rejections = (array)($planninq['rejected'] ?? []);

		$report = [];
		foreach ($rejections as $rejection) {
			$report[] = [
				'recordId' => ($rejection['externalRef'] ?? null),
				'errorCode' => (string)($rejection['errorCode'] ?? ''),
				'errorMessage' => (string)($rejection['errorMessage'] ?? ''),
			];
		}

		$state = 'succeed';
		if (count($report) > 0 && $accepted > 0) {
			$state = 'partial';
		}

		if (count($report) > 0 && $accepted === 0) {
			$state = 'fail';
		}

		$this->logger->info(
			'[PlanninqTimetableImport] {source} delivered into planninq ({flavour}): processed={p}, accepted={a}, rejected={r}.',
			['source' => $systemId, 'flavour' => (string)($delivery['flavour'] ?? ''), 'p' => $processed, 'a' => $accepted, 'r' => count($report)]
		);

		return [
			'state' => $state,
			'fields' => [
				'finishedAt' => date('c'),
				'connectorRunId' => null,
				'result' => [
					'recordsProcessed' => $processed,
					'recordsAccepted' => $accepted,
					'recordsRejected' => count($report),
					'validationReport' => $report,
					'artefactRef' => null,
					'target' => 'planninq',
					'rosterSource' => $systemId,
					'flavour' => (string)($delivery['flavour'] ?? ''),
				],
			],
		];
	}//end outcome()

	/**
	 * The job's scope as an array.
	 *
	 * @param array<string,mixed> $job The DataExchangeJob data.
	 *
	 * @return array<string,mixed>
	 */
	private function scope(array $job): array {
		$scope = $job['scope'] ?? [];
		if (is_array($scope) === false) {
			return [];
		}

		// A request without its own map uses the one the administrator keeps
		// for that rostering system (timetable-connection-and-import-screen).
		if (empty($scope['groupMap']) === true && is_string($scope['rosterSource'] ?? null) === true) {
			$kept = $this->groupMaps->groupMapFor(source: $scope['rosterSource']);
			if ($kept !== []) {
				$scope['groupMap'] = $kept;
			}
		}

		return $scope;
	}//end scope()

	/**
	 * Keep only non-empty string-to-string entries.
	 *
	 * @param mixed $value A candidate map.
	 *
	 * @return array<string,string>
	 */
	private function stringMap(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$map = [];
		foreach ($value as $code => $id) {
			if (is_scalar($id) === true && (string)$code !== '' && (string)$id !== '') {
				$map[(string)$code] = (string)$id;
			}
		}

		return $map;
	}//end stringMap()
}//end class
