<?php

/**
 * Learniq Course Evaluation Answer Service
 *
 * The learner side of a course evaluation, and the figures staff read back.
 * A learner sees their own open invitations and answers each once: the
 * answers are checked against the campaign questions, stored as a
 * CourseEvaluationResponse owned by the system (never by the learner), and
 * moved to `submitted` through the guarded `submit` transition, so
 * CourseEvaluationEligibilityGuard stays the only way in and
 * CourseEvaluationResponseSubmittedHandler marks the invitation answered.
 * Staff get the invitation count, the response count and the mean overall
 * score, and no mean while fewer than five people answered.
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
 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#requirement-learner-answers-an-evaluation
 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#requirement-campaign-results-for-staff
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * Lists, answers and sums up course evaluation invitations.
 *
 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#requirement-learner-answers-an-evaluation
 */
class CourseEvaluationAnswerService {

	/**
	 * The register every evaluation object lives in.
	 */
	private const REGISTER = 'learniq';

	/**
	 * Fewer answers than this and the mean would point at people (design D2).
	 */
	public const MIN_RESPONSES_FOR_MEAN = 5;

	/**
	 * Upper bound for one campaign's invitations or responses in a read.
	 */
	private const READ_LIMIT = 5000;

	/**
	 * Constructor.
	 *
	 * @param ObjectService                   $objectService    OpenRegister object access.
	 * @param TransitionEngine                $transitionEngine OpenRegister lifecycle engine, runs the guarded submit.
	 * @param CourseEvaluationResponseBuilder $builder          Checks the answers and builds the response object.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly TransitionEngine $transitionEngine,
		private readonly CourseEvaluationResponseBuilder $builder,
	) {
	}//end __construct()

	/**
	 * The caller's invitations that can still be answered: not answered yet,
	 * the campaign open and its closing date not passed.
	 *
	 * @param string            $learnerId The caller's user id (from the session).
	 * @param DateTimeImmutable $now       The moment to judge closing dates by.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-a-learner-answers-an-invitation
	 */
	public function openInvitations(string $learnerId, DateTimeImmutable $now): array {
		if ($learnerId === '') {
			return [];
		}

		$rows = $this->objectService->findAll(
			[
				'filters' => [
					'register' => self::REGISTER,
					'schema' => 'evaluation-invitation',
					'learnerId' => $learnerId,
					'hasResponded' => false,
				],
				'limit' => self::READ_LIMIT,
			]
		);

		$open = [];
		foreach ($rows as $row) {
			$invitation = self::toArray(value: $row);
			if (($invitation['learnerId'] ?? null) !== $learnerId || ($invitation['hasResponded'] ?? false) === true) {
				continue;
			}

			$campaign = $this->read(schema: 'evaluation-campaign', id: (string)($invitation['campaignId'] ?? ''));
			if ($campaign === null || self::isOpen(campaign: $campaign, now: $now) === false) {
				continue;
			}

			$course = $this->read(schema: 'course', id: (string)($invitation['courseId'] ?? ''));
			$open[] = [
				'invitationId' => (string)($invitation['id'] ?? ''),
				'campaignName' => (string)($campaign['name'] ?? ''),
				'courseName' => (string)($course['name'] ?? ''),
				'closesAt' => (string)($campaign['closesAt'] ?? ''),
				'instrumentKind' => (string)($campaign['instrumentKind'] ?? 'built-in'),
				'externalFormUrl' => ($campaign['externalFormUrl'] ?? null),
				'questions' => array_values((array)($campaign['questions'] ?? [])),
			];
		}

		usort($open, static fn (array $left, array $right): int => strcmp($left['closesAt'], $right['closesAt']));

		return $open;
	}//end openInvitations()

	/**
	 * Store the caller's answers to one invitation and submit them.
	 *
	 * The answer is refused before anything is written when the invitation is
	 * not the caller's (404, so its existence is not confirmed), was already
	 * answered or its campaign is closed (409), or the answers do not fit the
	 * questions (422). The response is stored without an owner, then
	 * submitted through the guarded transition; a refused submit removes the
	 * draft again.
	 *
	 * @param string            $learnerId    The caller's user id (from the session).
	 * @param string            $invitationId The invitation being answered.
	 * @param array             $answers      Map of questionId to rating or text.
	 * @param DateTimeImmutable $now          The moment to judge closing dates by.
	 *
	 * @return array{status: int, error?: string, missing?: array<int, string>}
	 *
	 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-a-learner-answers-an-invitation
	 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-a-learner-cannot-answer-twice
	 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-an-uninvited-user-is-refused
	 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-anonymous-answers-cannot-be-linked
	 */
	public function answer(string $learnerId, string $invitationId, array $answers, DateTimeImmutable $now): array {
		$invitation = $this->read(schema: 'evaluation-invitation', id: $invitationId);
		$campaign = $this->read(schema: 'evaluation-campaign', id: (string)($invitation['campaignId'] ?? ''));
		$refusal = self::refusal(learnerId: $learnerId, invitation: $invitation, campaign: $campaign, now: $now);
		if ($refusal !== null) {
			return $refusal;
		}

		$checked = $this->builder->checkAnswers(questions: (array)($campaign['questions'] ?? []), given: $answers);
		if ($checked['missing'] !== []) {
			return ['status' => 422, 'error' => 'Answer every required question.', 'missing' => $checked['missing']];
		}

		$response = $this->objectService->saveObject(
			object: $this->builder->responsePayload(invitation: $invitation, answers: $checked['answers']),
			register: self::REGISTER,
			schema: 'course-evaluation-response',
			_rbac: false,
			_unowned: true,
		);
		$responseId = (string)($response->getUuid() ?? '');

		try {
			$this->transitionEngine->transition(objectId: $responseId, action: 'submit');
		} catch (Throwable) {
			$this->objectService->deleteObject(uuid: $responseId, register: self::REGISTER, schema: 'course-evaluation-response', _rbac: false);
			return ['status' => 403, 'error' => 'You have no open invitation for this course evaluation.'];
		}

		return ['status' => 201];
	}//end answer()

	/**
	 * A campaign's figures for staff: invitations, responses and the mean
	 * overall score, the mean withheld under five responses.
	 *
	 * @param string $campaignId The campaign.
	 *
	 * @return array{invitationCount: int, responseCount: int, meanOverallScore: float|null, meanHidden: bool}|null Null for an unknown campaign.
	 *
	 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-small-groups-are-protected
	 */
	public function results(string $campaignId): ?array {
		if ($this->read(schema: 'evaluation-campaign', id: $campaignId) === null) {
			return null;
		}

		$invitations = $this->objectService->findAll(
			['filters' => ['register' => self::REGISTER, 'schema' => 'evaluation-invitation', 'campaignId' => $campaignId], 'limit' => self::READ_LIMIT]
		);
		$responses = $this->objectService->findAll(
			[
				'filters' => ['register' => self::REGISTER, 'schema' => 'course-evaluation-response', 'campaignId' => $campaignId, 'lifecycle' => 'submitted'],
				'limit' => self::READ_LIMIT,
			]
		);

		$scores = [];
		foreach ($responses as $row) {
			$score = (self::toArray(value: $row)['overallScore'] ?? null);
			if (is_numeric($score) === true) {
				$scores[] = (float)$score;
			}
		}

		$count = count($responses);
		$mean = null;
		if ($count >= self::MIN_RESPONSES_FOR_MEAN && $scores !== []) {
			$mean = round(array_sum($scores) / count($scores), 2);
		}

		return [
			'invitationCount' => count($invitations),
			'responseCount' => $count,
			'meanOverallScore' => $mean,
			'meanHidden' => $count < self::MIN_RESPONSES_FOR_MEAN,
		];
	}//end results()

	/**
	 * Why an answer is refused before anything is written, or null: not the
	 * caller's invitation (404), already answered or campaign closed (409),
	 * or a campaign answered in an external form (422).
	 *
	 * @param string            $learnerId  The caller.
	 * @param array|null        $invitation The invitation, or null.
	 * @param array|null        $campaign   Its campaign, or null.
	 * @param DateTimeImmutable $now        The moment to judge closing dates by.
	 *
	 * @return array{status: int, error: string}|null
	 *
	 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-an-uninvited-user-is-refused
	 */
	private static function refusal(string $learnerId, ?array $invitation, ?array $campaign, DateTimeImmutable $now): ?array {
		if ($invitation === null || $learnerId === '' || ($invitation['learnerId'] ?? null) !== $learnerId) {
			return ['status' => 404, 'error' => 'Invitation not found'];
		}

		if (($invitation['hasResponded'] ?? false) === true) {
			return ['status' => 409, 'error' => 'You already answered this evaluation.'];
		}

		if ($campaign === null || self::isOpen(campaign: $campaign, now: $now) === false) {
			return ['status' => 409, 'error' => 'This evaluation is closed.'];
		}

		if (($campaign['instrumentKind'] ?? 'built-in') !== 'built-in') {
			return ['status' => 422, 'error' => 'This evaluation is answered in an external form.'];
		}

		return null;
	}//end refusal()

	/**
	 * Whether a campaign takes answers now.
	 *
	 * @param array             $campaign The campaign.
	 * @param DateTimeImmutable $now      The moment to judge by.
	 *
	 * @return bool
	 */
	private static function isOpen(array $campaign, DateTimeImmutable $now): bool {
		if (($campaign['lifecycle'] ?? '') !== 'open') {
			return false;
		}

		try {
			return new DateTimeImmutable((string)($campaign['closesAt'] ?? '')) > $now;
		} catch (Throwable) {
			return false;
		}
	}//end isOpen()

	/**
	 * Read one object as an array, or null when it is not there.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The object id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function read(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$object = $this->objectService->find(id: $id, register: self::REGISTER, schema: $schema, _rbac: false);
		} catch (Throwable) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return self::toArray(value: $object);
	}//end read()

	/**
	 * Normalise an OpenRegister result to an array.
	 *
	 * @param mixed $value An entity or array.
	 *
	 * @return array<string, mixed>
	 */
	private static function toArray(mixed $value): array {
		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$value = $value->jsonSerialize();
		}

		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end toArray()
}//end class
