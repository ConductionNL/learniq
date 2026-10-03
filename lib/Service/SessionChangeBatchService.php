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

		$results = [];
		$learners = [];
		$parents = [];
		$dates = [];
		$tenantId = '';

		foreach ($change['sessionIds'] as $sessionId) {
			$outcome = $this->applyOne(sessionId: $sessionId, change: $change, batchId: $batchId, callerId: $callerId);
			$results[] = $outcome['result'];
			if ($outcome['result']['outcome'] !== 'applied') {
				continue;
			}

			if ($tenantId === '') {
				$tenantId = (string)($outcome['session']['tenant_id'] ?? '');
			}
			$people = $this->notices->affectedPeople(session: $outcome['session']);
			$learners = array_merge($learners, $people['learnerIds']);
			$parents = array_merge($parents, $people['parentIds']);
			$dates[] = (string)($outcome['session']['startsAt'] ?? '');
		}

		$applied = count($dates);
		$batch = [
			'kind' => $change['kind'],
			'sessionIds' => $change['sessionIds'],
			'substituteTeacherId' => $change['substituteTeacherId'],
			'roomId' => $change['roomId'],
			'changeReasonKind' => $change['changeReasonKind'],
			'changeReason' => $change['changeReason'],
			'results' => $results,
			'appliedCount' => $applied,
			'lessonDates' => $this->formatDates(dates: $dates),
			'affectedLearnerIds' => array_values(array_unique($learners)),
			'affectedParentIds' => array_values(array_unique($parents)),
			'madeBy' => $callerId,
			'tenant_id' => $tenantId,
		];

		$saved = $this->objectService->saveObject(
			object: $batch,
			register: self::REGISTER,
			schema: self::BATCH_SCHEMA,
			uuid: $batchId
		);

		$this->logger->info(
			'[SessionChangeBatchService] Batch {id} by {u}: {a} of {n} lesson(s) changed.',
			['id' => $batchId, 'u' => $callerId, 'a' => $applied, 'n' => count($results)]
		);

		return array_merge($batch, ['id' => $batchId], $this->series->toArray(row: $saved));
	}//end apply()

	/**
	 * Run the change on one lesson.
	 *
	 * @param string               $sessionId The lesson.
	 * @param array<string, mixed> $change    The validated change.
	 * @param string               $batchId   The batch's uuid.
	 * @param string               $callerId  The person applying it.
	 *
	 * @return array{result: array<string, mixed>, session: array<string, mixed>}
	 */
	private function applyOne(string $sessionId, array $change, string $batchId, string $callerId): array {
		$session = $this->series->loadSession(sessionId: $sessionId);
		if ($session === null) {
			return $this->refused(sessionId: $sessionId, startsAt: null, reason: self::NOT_FOUND);
		}

		$startsAt = (string)($session['startsAt'] ?? '');
		$lifecycle = (string)($session['lifecycle'] ?? 'scheduled');
		if (in_array($lifecycle, SessionSeries::OPEN_STATES, true) === false) {
			return $this->refused(sessionId: $sessionId, startsAt: $startsAt, reason: self::CLOSED_LESSON);
		}

		$action = $this->actionFor(kind: $change['kind'], lifecycle: $lifecycle);
		$changed = array_merge(
			$session,
			[
				'changeBatchId' => $batchId,
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

		$verdict = $this->guard->check(object: $changed, action: $action, userId: $callerId);
		if ($verdict->isAllowed() === false) {
			return $this->refused(sessionId: $sessionId, startsAt: $startsAt, reason: (string)$verdict->getMessage());
		}

		try {
			$this->objectService->saveObject(object: $changed, register: self::REGISTER, schema: self::SESSION_SCHEMA);
			if ($change['kind'] !== 'room') {
				$this->transitionEngine->transition(objectId: $sessionId, action: $action);
			}
		} catch (Throwable $exception) {
			$this->restore(original: $session);
			return $this->refused(sessionId: $sessionId, startsAt: $startsAt, reason: $this->refusalText(exception: $exception));
		}

		return [
			'result' => ['sessionId' => $sessionId, 'startsAt' => $startsAt, 'outcome' => 'applied', 'reason' => null],
			'session' => $changed,
		];
	}//end applyOne()

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
			$this->objectService->saveObject(object: $original, register: self::REGISTER, schema: self::SESSION_SCHEMA);
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
		if (method_exists($exception, 'getErrors') === true) {
			$errors = $exception->getErrors();
			if (is_array($errors) === true && is_string($errors['message'] ?? null) === true && $errors['message'] !== '') {
				return $errors['message'];
			}
		}

		$message = trim($exception->getMessage());
		if ($message === '') {
			return 'This lesson could not be changed.';
		}

		return $message;
	}//end refusalText()

	/**
	 * A refused lesson's result.
	 *
	 * @param string      $sessionId The lesson.
	 * @param string|null $startsAt  Its start, when known.
	 * @param string      $reason    Why.
	 *
	 * @return array{result: array<string, mixed>, session: array<string, mixed>}
	 */
	private function refused(string $sessionId, ?string $startsAt, string $reason): array {
		return [
			'result' => ['sessionId' => $sessionId, 'startsAt' => $startsAt, 'outcome' => 'refused', 'reason' => $reason],
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
