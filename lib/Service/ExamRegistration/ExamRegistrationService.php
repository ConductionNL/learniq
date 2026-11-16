<?php

/**
 * Learniq ExamRegistrationService
 *
 * The registration list of a course day's exam with an external examining
 * body: opens it, keeps its lines in step with the participants' profiles
 * while it is open, closes it at its moment, exports a closed list in the
 * body's column order, marks it sent and records the body's results.
 *
 * @category Service
 * @package  OCA\Learniq\Service\ExamRegistration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\ExamRegistration;

use OCA\Learniq\Kernel\Contracts\ExamRegistrationServiceInterface;
use OCA\Learniq\Service\CourseDay\CourseDayException;
use OCA\Learniq\Service\CourseDay\CourseDayRows;
use OCA\Learniq\Support\SerialisedRow;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Opens, refreshes, closes, exports and sends an exam registration list.
 *
 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
 */
class ExamRegistrationService implements ExamRegistrationServiceInterface {
	use CourseDayRows;

	/**
	 * The schema.
	 *
	 * @var string
	 */
	public const SCHEMA = 'exam-registration';

	/**
	 * Enrolment states that are no longer on the list.
	 *
	 * @var list<string>
	 */
	private const LEFT = ['withdrawn'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService The OpenRegister object service.
	 * @param ITimeFactory  $time          The clock.
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Open a registration list for a course day's exam with a body.
	 *
	 * @param string       $cohortId       The course day.
	 * @param string       $bodyName       The examining body.
	 * @param string       $closesAt       The moment the list closes (ISO 8601).
	 * @param string|null  $examDate       The exam date (Y-m-d), when known.
	 * @param list<string> $requiredFields The fields the body requires, in its column order; [] for the default.
	 *
	 * @return array<string, mixed> The list as {@see self::view()} reads it.
	 *
	 * @throws CourseDayException When the day is no course day or a field is missing.
	 * @throws \Throwable         When OpenRegister cannot be read or written.
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
	 */
	public function open(string $cohortId, string $bodyName, string $closesAt, ?string $examDate, array $requiredFields): array {
		$cohort = $this->one(schema: 'cohort', id: $cohortId);
		if ($cohort === null || (string)($cohort['courseId'] ?? '') === '') {
			throw new CourseDayException(message: 'This is not a course day.', status: 404);
		}

		if (trim($bodyName) === '' || ExamRegistrationLines::closingSentence(examDate: null, closesAt: $closesAt) === null) {
			throw new CourseDayException(message: 'Name the examining body and the moment the list closes.', status: 400);
		}

		$course = ($this->one(schema: 'course', id: (string)$cohort['courseId']) ?? []);
		$list   = [
			'cohortId' => $cohortId,
			'courseId' => (string)$cohort['courseId'],
			'courseName' => ($course['title'] ?? ($course['name'] ?? null)),
			'examDate' => ($examDate ?? ($cohort['dayDate'] ?? null)),
			'bodyName' => trim($bodyName),
			'closesAt' => $closesAt,
			'requiredFields' => ExamRegistrationLines::required(required: $requiredFields),
			'lines' => [],
			'lifecycle' => 'open',
			'tenant_id' => ($cohort['tenant_id'] ?? null),
		];

		return $this->view(list: $this->save(list: $this->withLines(list: $list)));
	}//end open()

	/**
	 * The list, refreshed from the participants' profiles while it is open.
	 *
	 * @param string $id The list.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws CourseDayException When the list does not exist.
	 * @throws \Throwable         When OpenRegister cannot be read or written.
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#scenario-youssefs-birth-date
	 */
	public function show(string $id): array {
		$list = $this->requireList(id: $id);
		if (($list['lifecycle'] ?? 'open') === 'open') {
			$list = $this->refreshed(list: $list);
		}

		return $this->view(list: $list);
	}//end show()

	/**
	 * Close the list: by hand, or at its moment by the system (`$userId` null).
	 *
	 * Its lines are refreshed one last time and then stand.
	 *
	 * @param string      $id     The list.
	 * @param string|null $userId Who closes it, null for the closing moment.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws CourseDayException When the list is not open.
	 * @throws \Throwable         When OpenRegister cannot be read or written.
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
	 */
	public function close(string $id, ?string $userId): array {
		$list = $this->requireList(id: $id);
		if (($list['lifecycle'] ?? 'open') !== 'open') {
			throw new CourseDayException(message: 'The list is already closed.', status: 409);
		}

		$list = $this->withLines(list: $list);
		$list['lifecycle'] = 'closed';
		$list['closedAt']  = $this->now();
		$list['closedBy']  = $userId;

		return $this->view(list: $this->save(list: $list));
	}//end close()

	/**
	 * Close every open list whose moment has come.
	 *
	 * @return int How many lists closed.
	 *
	 * @throws \Throwable When OpenRegister cannot be read or written.
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
	 */
	public function closeDue(): int {
		$closed = 0;
		$now    = $this->now();
		foreach ($this->many(schema: self::SCHEMA, filters: ['lifecycle' => 'open']) as $list) {
			if (ExamRegistrationLines::isDue(closesAt: ($list['closesAt'] ?? null), now: $now) === false) {
				continue;
			}

			$this->close(id: self::idOf(row: $list), userId: null);
			$closed++;
		}

		return $closed;
	}//end closeDue()

	/**
	 * The CSV of a closed list, in the body's column order. Only the export
	 * reads the birth date and place of birth, from the profiles.
	 *
	 * @param string $id The list.
	 *
	 * @return array{filename: string, csv: string}
	 *
	 * @throws CourseDayException When the list is still open.
	 * @throws \Throwable         When OpenRegister cannot be read or written.
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
	 */
	public function export(string $id): array {
		$list = $this->requireList(id: $id);
		if (($list['lifecycle'] ?? 'open') === 'open') {
			throw new CourseDayException(message: 'Only a closed list can be exported.', status: 409);
		}

		$lines    = (array)($list['lines'] ?? []);
		$profiles = [];
		foreach ($lines as $line) {
			$ref = (string)($line['learnerRef'] ?? '');
			if ($ref !== '' && isset($profiles[$ref]) === false) {
				$profiles[$ref] = ($this->one(schema: 'learner-profile', id: $ref) ?? []);
			}
		}

		$required = ExamRegistrationLines::required(required: ($list['requiredFields'] ?? []));
		$csv      = ExamRegistrationLines::csv(required: $required, lines: $lines, profiles: $profiles);
		$list['exportedAt'] = $this->now();
		$this->save(list: $list);

		$name = preg_replace('/[^A-Za-z0-9]+/', '-', (string)($list['bodyName'] ?? 'examen'));

		return ['filename' => 'aanmelding-' . strtolower(trim((string)$name, '-')) . '-' . (string)($list['examDate'] ?? '') . '.csv', 'csv' => $csv];
	}//end export()

	/**
	 * Mark a closed list sent to the body.
	 *
	 * @param string $id     The list.
	 * @param string $userId Who sent it.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws CourseDayException When the list is not closed.
	 * @throws \Throwable         When OpenRegister cannot be read or written.
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
	 */
	public function markSent(string $id, string $userId): array {
		$list = $this->requireList(id: $id);
		if (($list['lifecycle'] ?? 'open') !== 'closed') {
			throw new CourseDayException(message: 'Only a closed list that was not sent yet can be marked sent.', status: 409);
		}

		$list['lifecycle'] = 'sent';
		$list['sentAt']    = $this->now();
		$list['sentBy']    = $userId;

		return $this->view(list: $this->save(list: $list));
	}//end markSent()

	/**
	 * Record the body's result for one participant: `pass`, `fail`, or '' to clear it.
	 *
	 * @param string $id          The list.
	 * @param string $enrolmentId The participant's enrolment.
	 * @param string $result      The result.
	 * @param string $userId      Who entered it.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws CourseDayException When the list is open, the result unknown or the participant not on the list.
	 * @throws \Throwable         When OpenRegister cannot be read or written.
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-the-certificates-of-a-finished-course-day-are-issued-in-one-action
	 */
	public function recordResult(string $id, string $enrolmentId, string $result, string $userId): array {
		if (in_array($result, ['pass', 'fail', ''], true) === false) {
			throw new CourseDayException(message: 'A result is pass or fail.', status: 400);
		}

		$list = $this->requireList(id: $id);
		if (($list['lifecycle'] ?? 'open') === 'open') {
			throw new CourseDayException(message: 'Results are entered after the list closed.', status: 409);
		}

		$found  = false;
		$lines  = [];
		$stored = null;
		$by     = null;
		if ($result !== '') {
			$stored = $result;
			$by     = $userId;
		}

		foreach ((array)($list['lines'] ?? []) as $line) {
			if ((string)($line['enrolmentId'] ?? '') === $enrolmentId) {
				$found            = true;
				$line['result']   = $stored;
				$line['resultBy'] = $by;
			}

			$lines[] = $line;
		}

		if ($found === false) {
			throw new CourseDayException(message: 'This participant is not on the list.', status: 404);
		}

		$list['lines'] = $lines;

		return $this->view(list: $this->save(list: $list));
	}//end recordResult()

	/**
	 * The lists of a course day.
	 *
	 * @param string $cohortId The course day.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-the-certificates-of-a-finished-course-day-are-issued-in-one-action
	 */
	public function forCourseDay(string $cohortId): array {
		return $this->many(schema: self::SCHEMA, filters: ['cohortId' => $cohortId]);
	}//end forCourseDay()

	/**
	 * The lists still open, refreshed, each as {@see self::view()} reads it.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws \Throwable When OpenRegister cannot be read or written.
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#scenario-youssefs-birth-date
	 */
	public function openLists(): array {
		$views = [];
		foreach ($this->many(schema: self::SCHEMA, filters: ['lifecycle' => 'open']) as $list) {
			$views[] = $this->view(list: $this->refreshed(list: $list));
		}

		return $views;
	}//end openLists()

	/**
	 * The list with what the administration reads on it: the closing line,
	 * how many are complete, who is incomplete and when the employer was told.
	 *
	 * @param array<string, mixed> $list The list.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#scenario-youssefs-birth-date
	 */
	public function view(array $list): array {
		$lines      = array_values((array)($list['lines'] ?? []));
		$incomplete = [];
		foreach ($lines as $line) {
			if (($line['complete'] ?? false) === true) {
				continue;
			}

			$incomplete[] = [
				'enrolmentId' => ($line['enrolmentId'] ?? null),
				'learnerName' => ($line['learnerName'] ?? null),
				'contactName' => ($line['contactName'] ?? null),
				'missing' => ($line['missing'] ?? []),
				'sentence' => ExamRegistrationLines::missingSentence(line: $line),
				'notice' => ExamRegistrationLines::notice(notifiedAt: ($line['employerNotifiedAt'] ?? null), now: $this->now()),
			];
		}

		return array_merge(
			$list,
			[
				'closingLine' => ExamRegistrationLines::closingSentence(examDate: ($list['examDate'] ?? null), closesAt: ($list['closesAt'] ?? null)),
				'summary' => ExamRegistrationLines::summary(lines: $lines),
				'incomplete' => $incomplete,
			]
		);
	}//end view()

	/**
	 * The open list with fresh lines, saved only when they moved.
	 *
	 * @param array<string, mixed> $list The open list.
	 *
	 * @return array<string, mixed>
	 */
	private function refreshed(array $list): array {
		$next = $this->withLines(list: $list);
		if (($next['lines'] ?? []) === ($list['lines'] ?? []) && ($next['completeCount'] ?? null) === ($list['completeCount'] ?? null)) {
			return $list;
		}

		return $this->save(list: $next);
	}//end refreshed()

	/**
	 * The list with one line per participant of its course day.
	 *
	 * @param array<string, mixed> $list The list.
	 *
	 * @return array<string, mixed>
	 */
	private function withLines(array $list): array {
		$required = ExamRegistrationLines::required(required: ($list['requiredFields'] ?? []));
		$previous = [];
		foreach ((array)($list['lines'] ?? []) as $line) {
			$previous[(string)($line['enrolmentId'] ?? '')] = $line;
		}

		$lines = [];
		foreach ($this->participants(cohortId: (string)($list['cohortId'] ?? '')) as $enrolment) {
			$profile      = ($this->one(schema: 'learner-profile', id: (string)($enrolment['learnerRef'] ?? '')) ?? []);
			$companyRef   = (string)($enrolment['organisationRef'] ?? ($profile['organisationRef'] ?? ''));
			$organisation = ($this->one(schema: 'client-organisation', id: $companyRef) ?? []);
			$lines[]      = ExamRegistrationLines::line(
				enrolment: $enrolment,
				profile: $profile,
				organisation: $organisation,
				required: $required,
				previous: ($previous[self::idOf(row: $enrolment)] ?? null),
				now: $this->now()
			);
		}

		usort($lines, static fn (array $one, array $two): int => strcmp((string)($one['learnerName'] ?? ''), (string)($two['learnerName'] ?? '')));

		$list['requiredFields']   = $required;
		$list['lines']            = $lines;
		$list['participantCount'] = count($lines);
		$list['completeCount']    = ExamRegistrationLines::completeCount(lines: $lines);

		return $list;
	}//end withLines()

	/**
	 * The enrolments on a course day that are still on it.
	 *
	 * @param string $cohortId The course day.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function participants(string $cohortId): array {
		if ($cohortId === '') {
			return [];
		}

		return array_values(
			array_filter(
				$this->many(schema: 'enrolment', filters: ['cohortId' => $cohortId]),
				static fn (array $row): bool => in_array((string)($row['lifecycle'] ?? 'pending'), self::LEFT, true) === false
			)
		);
	}//end participants()

	/**
	 * The list, or a 404.
	 *
	 * @param string $id The list.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws CourseDayException When it does not exist.
	 */
	private function requireList(string $id): array {
		$list = $this->one(schema: self::SCHEMA, id: $id);
		if ($list === null) {
			throw new CourseDayException(message: 'This registration list does not exist.', status: 404);
		}

		return $list;
	}//end requireList()

	/**
	 * Save the list as the system and return it as stored.
	 *
	 * The readable parts the view adds are never stored.
	 *
	 * @param array<string, mixed> $list The list.
	 *
	 * @return array<string, mixed>
	 */
	private function save(array $list): array {
		$id = self::idOf(row: $list);
		unset($list['closingLine'], $list['summary'], $list['incomplete']);
		$uuid = null;
		if ($id !== '') {
			$uuid = $id;
		}

		$saved = $this->objectService->saveObject(
			object: $list,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: 'learniq', schema: self::SCHEMA),
				uuid: $uuid
			),
			_rbac: false,
			_multitenancy: false
		);

		return array_merge($list, (new SerialisedRow())->asArray(object: $saved));
	}//end save()

	/**
	 * A row's uuid, '' when it has none.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private static function idOf(array $row): string {
		return (string)($row['id'] ?? ($row['uuid'] ?? ''));
	}//end idOf()

	/**
	 * Now, as an ISO 8601 timestamp.
	 *
	 * @return string
	 */
	private function now(): string {
		return $this->time->getDateTime()->format(DATE_ATOM);
	}//end now()
}//end class
