<?php

/**
 * Learniq Session Change Batch Service
 *
 * Applies one timetable change (cancel, substitute teacher or other room) to
 * several lessons of the same weekly slot at once. Every lesson goes through
 * the same Session transition and the same SessionChangeGuard as a single
 * change, as the person who made the batch; a refused lesson is recorded with
 * the guard's reason and the loop goes on. The lessons in a batch send no
 * message of their own: the batch sends one message to everyone affected.
 *
 * Under D10 planninq owns the timetable, but cancellations and substitutions
 * stay learniq's operational record on its own Session rows (planninq lessons
 * have no Manage action, sessions-from-planninq), so the series lookup reads
 * learniq sessions only.
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
 * @spec openspec/specs/timetabling/spec.md#requirement-every-lesson-in-a-batch-passes-the-same-checks
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeZone;
use InvalidArgumentException;
use OCA\Learniq\Lifecycle\SessionChangeGuard;
use OCA\Learniq\Listener\SessionChangeNoticeHandler;
use OCA\Learniq\Timetabling\SessionChangeInput;
use OCA\Learniq\Timetabling\SessionSeries;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\Object\ObjectFindTarget;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One change on many lessons, each through its own guard.
 *
 * @spec openspec/specs/timetabling/spec.md#requirement-every-lesson-in-a-batch-passes-the-same-checks
 */
class SessionChangeBatchService {

	private const REGISTER = 'learniq';
	private const SESSION_SCHEMA = 'session';
	private const BATCH_SCHEMA = 'session-change-batch';
	private const CLOSED_LESSON = 'This lesson already took place or was cancelled.';
	private const NOT_FOUND = 'This lesson cannot be found, or you cannot see it.';

	/**
	 * Constructor.
	 *
	 * @param ObjectService              $objectService    OpenRegister objects, as the caller.
	 * @param SessionSeries              $series           Reads lessons as the caller.
	 * @param SessionChangeInput         $input            Checks the request body.
	 * @param TransitionEngine           $transitionEngine Session lifecycle transitions.
	 * @param SessionChangeGuard         $guard            Who may change a lesson.
	 * @param SessionChangeNoticeHandler $notices          Who a change affects.
	 * @param LoggerInterface            $logger           Logger.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly SessionSeries $series,
		private readonly SessionChangeInput $input,
		private readonly TransitionEngine $transitionEngine,
		private readonly SessionChangeGuard $guard,
		private readonly SessionChangeNoticeHandler $notices,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Apply one change to every listed lesson and store the batch.
	 *
	 * @param array<string, mixed> $input    `kind`, `sessionIds`, `changeReasonKind`, and `changeReason`, `substituteTeacherId` or `roomId`.
	 * @param string               $callerId The person applying the change.
	 *
	 * @return array<string, mixed> The stored batch, with `results` per lesson.
	 *
	 * @throws InvalidArgumentException When the input is incomplete.
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-every-lesson-in-a-batch-passes-the-same-checks
	 */
	public function apply(array $input, string $callerId): array {
		$change = $this->input->validated(input: $input);
		$batchId = $this->uuid();
		$tally = $this->applyAll(change: $change + ['batchId' => $batchId], callerId: $callerId);

		$batch = $this->batchRecord(change: $change, tally: $tally) + ['madeBy' => $callerId, 'tenant_id' => $tally['tenantId']];

		$saved = $this->objectService->saveObject(
			object: $batch,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: self::REGISTER, schema: self::BATCH_SCHEMA),
				uuid: $batchId
			)
		);

		$this->logger->info(
			'[SessionChangeBatchService] Batch {id} by {u}: {a} of {n} lesson(s) changed.',
			['id' => $batchId, 'u' => $callerId, 'a' => $batch['appliedCount'], 'n' => count($tally['results'])]
		);

		return array_merge($batch, ['id' => $batchId], $this->series->toArray(row: $saved));
	}//end apply()

	/**
	 * Run the change on every listed lesson as the caller, and gather what the applied ones touch.
	 *
	 * @param array<string, mixed> $change   The validated change, with its `batchId`.
	 * @param string               $callerId The person applying the change.
	 *
	 * @return array{results: list<array<string, mixed>>, learners: list<string>, parents: list<string>, dates: list<string>, tenantId: string}
	 */
	private function applyAll(array $change, string $callerId): array {
		$tally = ['results' => [], 'learners' => [], 'parents' => [], 'dates' => [], 'tenantId' => ''];
		foreach ($change['sessionIds'] as $sessionId) {
			$outcome = $this->applyOne(job: ['sessionId' => $sessionId, 'change' => $change], callerId: $callerId);
			$tally['results'][] = $outcome['result'];
			if ($outcome['result']['outcome'] === 'applied') {
				$tally = $this->countApplied(tally: $tally, session: $outcome['session']);
			}
		}

		return $tally;
	}//end applyAll()

	/**
	 * Add an applied lesson's tenant (the first one found), people and date to the tally.
	 *
	 * @param array<string, mixed> $tally   The tally so far.
	 * @param array<string, mixed> $session The changed lesson.
	 *
	 * @return array<string, mixed>
	 */
	private function countApplied(array $tally, array $session): array {
		if ($tally['tenantId'] === '') {
			$tally['tenantId'] = (string)($session['tenant_id'] ?? '');
		}

		$people = $this->notices->affectedPeople(session: $session);
		$tally['learners'] = array_merge($tally['learners'], $people['learnerIds']);
		$tally['parents'] = array_merge($tally['parents'], $people['parentIds']);
		$tally['dates'][] = (string)($session['startsAt'] ?? '');
		return $tally;
	}//end countApplied()

	/**
	 * The batch as stored, without who made it and its tenant.
	 *
	 * @param array<string, mixed> $change The validated change.
	 * @param array<string, mixed> $tally  What applyAll() gathered.
	 *
	 * @return array<string, mixed>
	 */
	private function batchRecord(array $change, array $tally): array {
		return [
			'kind' => $change['kind'],
			'sessionIds' => $change['sessionIds'],
			'substituteTeacherId' => $change['substituteTeacherId'],
			'roomId' => $change['roomId'],
			'changeReasonKind' => $change['changeReasonKind'],
			'changeReason' => $change['changeReason'],
			'results' => $tally['results'],
			'appliedCount' => count($tally['dates']),
			'lessonDates' => $this->formatDates(dates: $tally['dates']),
			'affectedLearnerIds' => array_values(array_unique($tally['learners'])),
			'affectedParentIds' => array_values(array_unique($tally['parents'])),
		];
	}//end batchRecord()

	/**
	 * Run the change on one lesson, checked by the guard for the caller.
	 *
	 * @param array{sessionId: string, change: array<string, mixed>} $job      The lesson and the validated change with its `batchId`.
	 * @param string                                                 $callerId The person applying it.
	 *
	 * @return array{result: array<string, mixed>, session: array<string, mixed>}
	 */
	private function applyOne(array $job, string $callerId): array {
		['sessionId' => $sessionId, 'change' => $change] = $job;
		$session = $this->series->loadSession(sessionId: $sessionId);
		if ($session === null) {
			return $this->refused(lesson: ['sessionId' => $sessionId, 'startsAt' => null], reason: self::NOT_FOUND);
		}

		$lesson = ['sessionId' => $sessionId, 'startsAt' => (string)($session['startsAt'] ?? '')];
		$lifecycle = (string)($session['lifecycle'] ?? 'scheduled');
		if (in_array($lifecycle, SessionSeries::OPEN_STATES, true) === false) {
			return $this->refused(lesson: $lesson, reason: self::CLOSED_LESSON);
		}

		$action = $this->actionFor(kind: $change['kind'], lifecycle: $lifecycle);
		$changed = $this->changedLesson(session: $session, change: $change);
		$verdict = $this->guard->check(object: $changed, action: $action, userId: $callerId);
		if ($verdict->isAllowed() === false) {
			return $this->refused(lesson: $lesson, reason: (string)$verdict->getMessage());
		}

		return $this->commit(lesson: $lesson + ['original' => $session, 'changed' => $changed, 'action' => $action, 'kind' => $change['kind']]);
	}//end applyOne()

	/**
	 * A lesson with the change and the batch's reason on it.
	 *
	 * @param array<string, mixed> $session The lesson.
	 * @param array<string, mixed> $change  The validated change with its `batchId`.
	 *
	 * @return array<string, mixed>
	 */
	private function changedLesson(array $session, array $change): array {
		$changed = array_merge(
			$session,
			[
				'changeBatchId' => $change['batchId'],
				'changeReasonKind' => $change['changeReasonKind'],
				'changeReason' => $change['changeReason'],
				'affectedLearnerIds' => [],
				'affectedParentIds' => [],
			]
		);
		if ($change['kind'] === 'substitute') {
			$changed['substituteTeacherId'] = $change['substituteTeacherId'];
		}

		if ($change['kind'] === 'room') {
			$changed['roomId'] = $change['roomId'];
		}

		return $changed;
	}//end changedLesson()

	/**
	 * Write a checked change and fire its transition (none for a room change);
	 * on failure put the lesson back and refuse it.
	 *
	 * @param array<string, mixed> $lesson `sessionId`, `startsAt`, `original`, `changed`, `action` and `kind`.
	 *
	 * @return array{result: array<string, mixed>, session: array<string, mixed>}
	 */
	private function commit(array $lesson): array {
		try {
			$this->objectService->saveObject(
				object: $lesson['changed'],
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: self::REGISTER, schema: self::SESSION_SCHEMA)
				)
			);
			if ($lesson['kind'] !== 'room') {
				$this->transitionEngine->transition(objectId: $lesson['sessionId'], action: $lesson['action']);
			}
		} catch (Throwable $exception) {
			$this->restore(original: $lesson['original']);
			return $this->refused(lesson: $lesson, reason: $this->refusalText(exception: $exception));
		}

		return [
			'result' => ['sessionId' => $lesson['sessionId'], 'startsAt' => $lesson['startsAt'], 'outcome' => 'applied', 'reason' => null],
			'session' => $lesson['changed'],
		];
	}//end commit()

	/**
	 * The lifecycle action a change fires on a lesson in this state.
	 *
	 * @param string $kind      `cancel`, `substitute` or `room`.
	 * @param string $lifecycle The lesson's current state.
	 *
	 * @return string The action name; `room-change` for a room change, which is a plain update.
	 */
	private function actionFor(string $kind, string $lifecycle): string {
		if ($kind === 'cancel') {
			return 'cancel';
		}

		if ($kind === 'substitute') {
			if ($lifecycle === 'in-progress') {
				return 'substitute-teacher-in-progress';
			}

			return 'substitute-teacher';
		}

		return 'room-change';
	}//end actionFor()

	/**
	 * Put a lesson back the way it was after a failed transition.
	 *
	 * @param array<string, mixed> $original The lesson before the change.
	 *
	 * @return void
	 */
	private function restore(array $original): void {
		try {
			$this->objectService->saveObject(
				object: $original,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: self::REGISTER, schema: self::SESSION_SCHEMA)
				)
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[SessionChangeBatchService] Could not restore lesson {id}: {msg}',
				['id' => (string)($original['id'] ?? ''), 'msg' => $exception->getMessage()]
			);
		}
	}//end restore()

	/**
	 * A readable reason from a refused write.
	 *
	 * @param Throwable $exception What the write threw.
	 *
	 * @return string The reason.
	 */
	private function refusalText(Throwable $exception): string {
		$fromErrors = $this->errorsMessage(exception: $exception);
		if ($fromErrors !== '') {
			return $fromErrors;
		}

		$message = trim($exception->getMessage());
		if ($message === '') {
			return 'This lesson could not be changed.';
		}

		return $message;
	}//end refusalText()

	/**
	 * The `message` of a write exception's validation errors; '' when it carries none.
	 *
	 * @param Throwable $exception What the write threw.
	 *
	 * @return string
	 */
	private function errorsMessage(Throwable $exception): string {
		if (method_exists($exception, 'getErrors') === false) {
			return '';
		}

		$errors = $exception->getErrors();
		if (is_array($errors) === true && is_string($errors['message'] ?? null) === true && $errors['message'] !== '') {
			return $errors['message'];
		}

		return '';
	}//end errorsMessage()

	/**
	 * A refused lesson's result.
	 *
	 * @param array<string, mixed> $lesson `sessionId`, and `startsAt` (null when unknown).
	 * @param string               $reason Why.
	 *
	 * @return array{result: array<string, mixed>, session: array<string, mixed>}
	 */
	private function refused(array $lesson, string $reason): array {
		return [
			'result' => ['sessionId' => $lesson['sessionId'], 'startsAt' => $lesson['startsAt'], 'outcome' => 'refused', 'reason' => $reason],
			'session' => [],
		];
	}//end refused()

	/**
	 * The changed lessons' dates for the message, such as "3-3-2026, 10-3-2026".
	 *
	 * @param array<int, string> $dates Start date-times.
	 *
	 * @return string|null
	 */
	private function formatDates(array $dates): ?string {
		$tz = new DateTimeZone(SessionSeries::TIMEZONE);
		$labels = [];
		foreach ($dates as $date) {
			$local = $this->series->localTime(value: $date, tz: $tz);
			if ($local !== null) {
				$labels[] = $local->format('j-n-Y');
			}
		}

		if ($labels === []) {
			return null;
		}

		return implode(', ', $labels);
	}//end formatDates()

	/**
	 * A random version 4 uuid for the batch, so its lessons can point at it
	 * before it is saved.
	 *
	 * @return string
	 */
	private function uuid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
		$hex = bin2hex($bytes);

		return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
	}//end uuid()
}//end class
