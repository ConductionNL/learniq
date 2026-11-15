<?php

/**
 * Contract of CourseEvaluationAnswerService
 *
 * The public surface of CourseEvaluationAnswerService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Learniq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Kernel\Contracts;

use DateTimeImmutable;

/**
 * Contract of CourseEvaluationAnswerService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CourseEvaluationAnswerServiceInterface {

	/**
	 * Store the caller's answers to one invitation and submit them.
	 *
	 * The answer is refused before anything is written when the invitation is
	 * not the caller's (404, so its existence is not confirmed), was already
	 * answered or its campaign is closed (409), or the answers do not fit the
	 * questions (422), or OpenRegister is too old to submit as the system
	 * (503). The response is stored without an owner, then submitted through
	 * the guarded transition, run as the system because no learner holds a
	 * right on response rows; the guard still judges the session user. A
	 * refused submit removes the draft again.
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
	public function answer(string $learnerId, string $invitationId, array $answers, DateTimeImmutable $now): array;

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
	public function openInvitations(string $learnerId, DateTimeImmutable $now): array;

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
	public function results(string $campaignId): ?array;
}//end interface
