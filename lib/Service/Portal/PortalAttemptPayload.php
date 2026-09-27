<?php

/**
 * Learniq Portal Attempt Payload
 *
 * Builds what portaliq's timed task reads: a task line for `available`, and
 * for `start` the attempt with its server clock, deadline, items (in the
 * attempt's drawn order, without answers) and the answers saved so far.
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
 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Learniq\Service\AssessmentAccessPolicy;
use Psr\Log\LoggerInterface;

/**
 * Task lines and the start payload.
 *
 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
 */
class PortalAttemptPayload {

	/**
	 * Constructor.
	 *
	 * @param PortalAttemptReader $reader OpenRegister reads (the items).
	 * @param PortalItemPresenter $presenter Items without answers.
	 * @param PortalAttemptClock $clock Deadlines and extra time.
	 * @param AssessmentAccessPolicy $policy Whether a test needs a code.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PortalAttemptReader $reader,
		private readonly PortalItemPresenter $presenter,
		private readonly PortalAttemptClock $clock,
		private readonly AssessmentAccessPolicy $policy,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * One task line for `available`.
	 *
	 * @param array<string, mixed> $exam The test, raw.
	 * @param float $extra The pupil's extra time.
	 * @param string|null $attemptId The attempt in progress, or null.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function task(array $exam, float $extra, ?string $attemptId): array {
		$state = 'available';
		if ($attemptId !== null) {
			$state = PortalAttemptCloser::IN_PROGRESS;
		}

		return [
			'taskId' => (string)$exam['id'],
			'title' => (string)($exam['title'] ?? ''),
			'description' => (string)($exam['description'] ?? ''),
			'timeLimitMinutes' => ($exam['timeLimitMinutes'] ?? null),
			'extraTimeMinutes' => $this->clock->extraMinutes(exam: $exam, extraPercentage: $extra),
			'availableUntil' => ($exam['availableUntil'] ?? null),
			'needsAccessCode' => $this->policy->requiresAccessCode(assessment: $exam),
			'state' => $state,
			'attemptId' => $attemptId,
		];
	}//end task()

	/**
	 * The start payload.
	 *
	 * @param array<string, mixed> $exam The test.
	 * @param array<string, mixed> $attempt The attempt.
	 * @param float $extra The pupil's extra time.
	 * @param DateTimeImmutable $now The current time.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function attempt(array $exam, array $attempt, float $extra, DateTimeImmutable $now): array {
		$deadline = $this->clock->deadline(attempt: $attempt, exam: $exam, extraPercentage: $extra);

		$items = [];
		foreach ($this->drawnRefs(attempt: $attempt, exam: $exam) as $ref) {
			$item = $this->reader->item(id: (string)$ref['itemId']);
			if ($item !== null) {
				$items[] = $this->presenter->present(item: $item, drawnRef: $ref);
			}
		}

		return [
			'attemptId' => (string)$attempt['id'],
			'title' => (string)($exam['title'] ?? ''),
			'serverNow' => $now->format(DateTimeInterface::ATOM),
			'deadlineAt' => $deadline?->format(DateTimeInterface::ATOM),
			'items' => $items,
			'responses' => (object)$this->savedAnswers(attempt: $attempt),
		];
	}//end attempt()

	/**
	 * The answers saved so far, by item.
	 *
	 * @param array<string, mixed> $attempt The attempt.
	 *
	 * @return array<string, mixed>
	 */
	private function savedAnswers(array $attempt): array {
		$answers = [];
		foreach ((array)($attempt['responses'] ?? []) as $response) {
			if (is_array($response) === true && isset($response['itemId']) === true) {
				$answers[(string)$response['itemId']] = ($response['response']['value'] ?? null);
			}
		}

		return $answers;
	}//end savedAnswers()

	/**
	 * The attempt's frozen item list; the test's fixed list when the draw left
	 * none (logged, so an empty draw is visible).
	 *
	 * @param array<string, mixed> $attempt The attempt.
	 * @param array<string, mixed> $exam The test.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function drawnRefs(array $attempt, array $exam): array {
		$refs = $this->refsWithItem(refs: ($attempt['drawnItemRefs'] ?? []));
		if ($refs !== []) {
			return $refs;
		}

		$this->logger->warning(
			'[PortalAttemptPayload] Attempt {id} has no drawn items; using the test item list.',
			['id' => ($attempt['id'] ?? '')]
		);

		return $this->refsWithItem(refs: ($exam['itemRefs'] ?? []));
	}//end drawnRefs()

	/**
	 * The entries of a ref list that name an item.
	 *
	 * @param mixed $refs A drawnItemRefs or itemRefs value.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function refsWithItem(mixed $refs): array {
		$out = [];
		foreach ((array)$refs as $ref) {
			if (is_array($ref) === true && is_string($ref['itemId'] ?? null) === true && $ref['itemId'] !== '') {
				$out[] = $ref;
			}
		}

		return $out;
	}//end refsWithItem()
}//end class
