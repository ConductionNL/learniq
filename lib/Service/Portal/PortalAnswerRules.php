<?php

/**
 * Learniq Portal Answer Rules
 *
 * Whether one answer may be saved on an attempt: the item must be one the
 * attempt drew, and the answer must fit the item's type and options.
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

/**
 * Item and shape checks for one answer.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
 */
class PortalAnswerRules {

	/**
	 * Constructor.
	 *
	 * @param PortalAttemptReader $reader OpenRegister reads (the item).
	 * @param PortalItemPresenter $presenter The item as the pupil saw it.
	 * @param PortalAnswerShape $shape Whether an answer fits its item.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PortalAttemptReader $reader,
		private readonly PortalItemPresenter $presenter,
		private readonly PortalAnswerShape $shape,
	) {
	}//end __construct()

	/**
	 * The contract error for an answer that may not be saved, or null.
	 *
	 * @param array<string, mixed> $attempt The attempt.
	 * @param string $itemId The question.
	 * @param mixed $response The answer.
	 *
	 * @return string|null `unknown_item`, `invalid_response` or null.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function problem(array $attempt, string $itemId, mixed $response): ?string {
		$ref = $this->drawnRef(attempt: $attempt, itemId: $itemId);
		$item = null;
		if ($ref !== null) {
			$item = $this->reader->item(id: $itemId);
		}

		if ($ref === null || $item === null) {
			return 'unknown_item';
		}

		if ($this->shape->acceptsResponse(presented: $this->presenter->present(item: $item, drawnRef: $ref), response: $response) === false) {
			return 'invalid_response';
		}

		return null;
	}//end problem()

	/**
	 * The attempt's drawn entry for one item, or null when it did not draw it.
	 *
	 * @param array<string, mixed> $attempt The attempt.
	 * @param string $itemId The item.
	 *
	 * @return array<string, mixed>|null
	 */
	private function drawnRef(array $attempt, string $itemId): ?array {
		if ($itemId === '') {
			return null;
		}

		foreach ((array)($attempt['drawnItemRefs'] ?? []) as $ref) {
			if (is_array($ref) === true && ($ref['itemId'] ?? null) === $itemId) {
				return $ref;
			}
		}

		return null;
	}//end drawnRef()
}//end class
