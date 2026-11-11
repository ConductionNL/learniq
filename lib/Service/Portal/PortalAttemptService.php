<?php

/**
 * Learniq Portal Attempt Service
 *
 * The `available`, `start`, `answer` and `submit` steps of portaliq's timed
 * task (ConductionNL/portaliq#749), for one pupil. Every rule a portal attempt
 * is subject to is checked here, before any write, because the attempt gate
 * and the integrity listener exempt a caller without a Nextcloud user:
 *
 * - what may be started: PortalAssessmentCatalogue (published, not proctored,
 *   same school, enrolled, window, drip, release conditions, attempts left);
 * - the access code: AssessmentAccessPolicy;
 * - one attempt in progress: an open attempt is resumed, never duplicated;
 * - the deadline with extra time: PortalAttemptCloser hands in an attempt the
 *   first time a request finds it past the deadline plus grace;
 * - answers one question at a time, only on the pupil's own attempt in
 *   progress, only for a drawn item, only in a shape it takes
 *   (PortalAnswerRules);
 * - nothing after hand-in;
 * - hand-in fires the attempt's `submit` transition, which auto-scores.
 *
 * Writes run as the pupil (PortalAttemptWriter), so the attempt gate and the
 * integrity listener still apply as a second layer.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal
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
 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use DateTimeInterface;
use OCA\Learniq\Service\AssessmentAccessPolicy;

/**
 * Starts, saves and hands in a pupil's portal attempts.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
 */
class PortalAttemptService {

	/**
	 * Constructor.
	 *
	 * @param PortalAttemptReader $reader OpenRegister reads.
	 * @param PortalAttemptWriter $writer Pupil-scoped writes.
	 * @param PortalAssessmentCatalogue $catalogue What a pupil may start.
	 * @param PortalAttemptCloser $closer Time: open attempts, closing, hand-in.
	 * @param PortalAttemptPayload $payload Task lines and the start payload.
	 * @param PortalAnswerRules $answerRules Item and shape checks for an answer.
	 * @param AssessmentAccessPolicy $policy The access code.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PortalAttemptReader $reader,
		private readonly PortalAttemptWriter $writer,
		private readonly PortalAssessmentCatalogue $catalogue,
		private readonly PortalAttemptCloser $closer,
		private readonly PortalAttemptPayload $payload,
		private readonly PortalAnswerRules $answerRules,
		private readonly AssessmentAccessPolicy $policy,
	) {
	}//end __construct()

	/**
	 * The tests the pupil may start or continue.
	 *
	 * @param PortalLearner $learner The pupil.
	 *
	 * @return PortalOutcome
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function available(PortalLearner $learner): PortalOutcome {
		$tasks = [];
		foreach ($this->catalogue->examsFor(learner: $learner) as $entry) {
			$exam = $entry['exam'];
			$examId = (string)$exam['id'];
			$extra = $this->closer->extraFor(learner: $learner, examId: $examId);
			$attempts = $this->reader->attemptsFor(examId: $examId, ncUserId: $learner->ncUserId);

			$open = $this->closer->openAttempt(learner: $learner, exam: $exam, attempts: $attempts, extra: $extra);
			if ($open !== null) {
				$tasks[] = $this->payload->task(exam: $exam, extra: $extra, attemptId: (string)$open['id']);
				continue;
			}

			$block = $this->catalogue->startBlock(
				learner: $learner,
				exam: $exam,
				enrolment: $entry['enrolment'],
				attemptsUsed: count($attempts),
				now: $this->closer->now()
			);
			if ($block === null) {
				$tasks[] = $this->payload->task(exam: $exam, extra: $extra, attemptId: null);
			}
		}

		return new PortalOutcome(status: 200, body: ['tasks' => $tasks]);
	}//end available()

	/**
	 * Start a new attempt, or resume the one in progress.
	 *
	 * @param PortalLearner $learner The pupil.
	 * @param string $taskId The test's uuid.
	 * @param mixed $accessCode The code the pupil typed, if any.
	 *
	 * @return PortalOutcome
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function start(PortalLearner $learner, string $taskId, mixed $accessCode): PortalOutcome {
		$exam = $this->reader->exam(id: $taskId);
		if ($exam === null) {
			return $this->refuse(status: 403, error: 'not_available', reason: PortalAssessmentCatalogue::NOT_AVAILABLE);
		}

		$request = new PortalAttemptStart();
		$request->learner = $learner;
		$request->taskId = $taskId;
		$request->exam = $exam;
		$request->accessCode = $accessCode;

		return $this->startExam(request: $request);
	}//end start()

	/**
	 * Resume the open attempt of a test that exists, or start a new one when
	 * the rules allow it.
	 *
	 * @param PortalAttemptStart $request The start being worked through.
	 *
	 * @return PortalOutcome
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	private function startExam(PortalAttemptStart $request): PortalOutcome {
		$learner = $request->learner;
		$request->extra = $this->closer->extraFor(learner: $learner, examId: $request->taskId);
		$request->attempts = $this->reader->attemptsFor(examId: $request->taskId, ncUserId: $learner->ncUserId);

		$attempt = $this->closer->openAttempt(learner: $learner, exam: $request->exam, attempts: $request->attempts, extra: $request->extra);
		if ($attempt !== null) {
			return $this->attemptOutcome(request: $request, attempt: $attempt);
		}

		$refusal = $this->startRefusal(request: $request);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->attemptOutcome(request: $request, attempt: $this->create(request: $request));
	}//end startExam()

	/**
	 * The start payload of an attempt that is open now.
	 *
	 * @param PortalAttemptStart $request The start being worked through.
	 * @param array<string, mixed> $attempt The resumed or created attempt.
	 *
	 * @return PortalOutcome
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	private function attemptOutcome(PortalAttemptStart $request, array $attempt): PortalOutcome {
		return new PortalOutcome(
			status: 200,
			body: $this->payload->attempt(exam: $request->exam, attempt: $attempt, extra: $request->extra, now: $this->closer->now())
		);
	}//end attemptOutcome()

	/**
	 * Save the answer to one question.
	 *
	 * @param PortalLearner $learner The pupil.
	 * @param string $attemptId The attempt.
	 * @param string $itemId The question.
	 * @param mixed $response The answer.
	 *
	 * @return PortalOutcome
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function answer(PortalLearner $learner, string $attemptId, string $itemId, mixed $response): PortalOutcome {
		$attempt = $this->ownAttempt(learner: $learner, attemptId: $attemptId);
		if ($attempt === null) {
			return $this->refuse(status: 404, error: 'not_found');
		}

		if ($this->closer->closeIfDue(learner: $learner, attempt: $attempt) === true) {
			return $this->refuse(status: 409, error: 'attempt_closed', reason: 'attempt-closed');
		}

		$problem = $this->answerRules->problem(attempt: $attempt, itemId: $itemId, response: $response);
		if ($problem !== null) {
			return $this->refuse(status: 422, error: $problem);
		}

		$attempt['responses'] = $this->withResponse(responses: ($attempt['responses'] ?? []), itemId: $itemId, response: $response);
		$this->writer->saveAttempt(learner: $learner, id: $attemptId, row: $attempt);

		return new PortalOutcome(status: 200, body: ['saved' => true]);
	}//end answer()

	/**
	 * Hand the attempt in.
	 *
	 * @param PortalLearner $learner The pupil.
	 * @param string $attemptId The attempt.
	 *
	 * @return PortalOutcome
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function submit(PortalLearner $learner, string $attemptId): PortalOutcome {
		$attempt = $this->ownAttempt(learner: $learner, attemptId: $attemptId);
		if ($attempt === null) {
			return $this->refuse(status: 404, error: 'not_found');
		}

		if (($attempt['lifecycle'] ?? '') !== PortalAttemptCloser::IN_PROGRESS) {
			return $this->refuse(status: 409, error: 'attempt_closed', reason: 'attempt-closed');
		}

		$this->closer->handIn(learner: $learner, attempt: $attempt);

		return new PortalOutcome(status: 200, body: ['state' => 'submitted']);
	}//end submit()

	/**
	 * Why a new attempt may not start, or null: the catalogue's rules, then the
	 * access code.
	 *
	 * @param PortalAttemptStart $request The start being worked through.
	 *
	 * @return PortalOutcome|null
	 */
	private function startRefusal(PortalAttemptStart $request): ?PortalOutcome {
		$learner = $request->learner;
		$exam = $request->exam;
		$block = $this->catalogue->startBlock(
			learner: $learner,
			exam: $exam,
			enrolment: $this->catalogue->enrolmentFor(learner: $learner, exam: $exam),
			attemptsUsed: count($request->attempts),
			now: $this->closer->now()
		);
		if ($block !== null) {
			return $this->refuse(status: 403, error: 'not_available', reason: $block);
		}

		return $this->accessCodeRefusal(exam: $exam, accessCode: $request->accessCode);
	}//end startRefusal()

	/**
	 * Why the typed access code does not open the test, or null when it does.
	 *
	 * @param array<string, mixed> $exam The test, raw.
	 * @param mixed $accessCode The typed code.
	 *
	 * @return PortalOutcome|null
	 */
	private function accessCodeRefusal(array $exam, mixed $accessCode): ?PortalOutcome {
		$codeBlock = $this->policy->accessCodeBlock(assessment: $exam, given: $accessCode);
		if ($codeBlock === null) {
			return null;
		}

		if ($codeBlock['reason'] === AssessmentAccessPolicy::REASON_CODE_REQUIRED) {
			return $this->refuse(status: 403, error: 'access_code_required', reason: 'access-code-required');
		}

		return $this->refuse(status: 403, error: 'access_code_wrong', reason: 'access-code-wrong');
	}//end accessCodeRefusal()

	/**
	 * Create the attempt as the pupil and read it back, so the draw that
	 * AssessmentDrawResolver writes after the insert is in it.
	 *
	 * @param PortalAttemptStart $request The start being worked through.
	 *
	 * @return array<string, mixed>
	 */
	private function create(PortalAttemptStart $request): array {
		$created = $this->writer->createAttempt(learner: $request->learner, data: $this->newAttemptRow(request: $request));

		$id = (string)($created['id'] ?? '');
		$attempt = ($this->reader->attempt(id: $id) ?? $created);
		$attempt['id'] = $id;

		return $attempt;
	}//end create()

	/**
	 * The row of the pupil's next attempt, in progress from now.
	 *
	 * @param PortalAttemptStart $request The start being worked through.
	 *
	 * @return array<string, mixed>
	 */
	private function newAttemptRow(PortalAttemptStart $request): array {
		$exam = $request->exam;
		$learner = $request->learner;

		return [
			'assessmentId' => (string)$exam['id'],
			'learnerId' => $learner->ncUserId,
			'tenant_id' => (string)($exam['tenant_id'] ?? $learner->tenantId),
			'attemptNumber' => (count($request->attempts) + 1),
			'startedAt' => $this->closer->now()->format(DateTimeInterface::ATOM),
			'responses' => [],
			'lifecycle' => PortalAttemptCloser::IN_PROGRESS,
			// The attempt gate checks it again as the pupil and clears it.
			'accessCode' => $this->typedCode(accessCode: $request->accessCode),
		];
	}//end newAttemptRow()

	/**
	 * The typed code when it is a string, null otherwise.
	 *
	 * @param mixed $accessCode The typed code.
	 *
	 * @return string|null
	 */
	private function typedCode(mixed $accessCode): ?string {
		if (is_string($accessCode) === true) {
			return $accessCode;
		}

		return null;
	}//end typedCode()

	/**
	 * The attempt, when it is this pupil's; null otherwise (no oracle).
	 *
	 * @param PortalLearner $learner The pupil.
	 * @param string $attemptId The attempt's uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function ownAttempt(PortalLearner $learner, string $attemptId): ?array {
		$attempt = $this->reader->attempt(id: $attemptId);
		if ($attempt === null || ($attempt['learnerId'] ?? null) !== $learner->ncUserId) {
			return null;
		}

		$attempt['id'] = $attemptId;

		return $attempt;
	}//end ownAttempt()

	/**
	 * The responses with one question's answer replaced, unscored.
	 *
	 * @param mixed $responses The stored responses.
	 * @param string $itemId The question.
	 * @param mixed $response The answer.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function withResponse(mixed $responses, string $itemId, mixed $response): array {
		$kept = [];
		foreach ((array)$responses as $row) {
			if (is_array($row) === true && ($row['itemId'] ?? null) !== $itemId) {
				$kept[] = $row;
			}
		}

		$kept[] = ['itemId' => $itemId, 'response' => ['value' => $response], 'autoScore' => null, 'manualScore' => null];

		return $kept;
	}//end withResponse()

	/**
	 * A refusal with the contract's error code and a message key.
	 *
	 * @param int $status HTTP status.
	 * @param string $error The contract's `error` value.
	 * @param string|null $reason Message key, null when no pupil message fits.
	 *
	 * @return PortalOutcome
	 */
	private function refuse(int $status, string $error, ?string $reason = null): PortalOutcome {
		return new PortalOutcome(status: $status, body: ['error' => $error], reason: $reason);
	}//end refuse()
}//end class
