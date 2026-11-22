<?php

/**
 * Learniq Review Panel Decision
 *
 * The rule a review panel's decision follows: when an admissions round names
 * a panel, an application is placed, waitlisted or rejected only once every
 * member has given their advice and more than half of the panel advised that
 * outcome. One member cannot decide alone, and a decision the panel did not
 * advise is refused. A round without a panel has no such rule.
 *
 * Pure: it reads the panel and the advice rows it is handed and answers the
 * refusal in words, or null when the decision may go ahead.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
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
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#requirement-a-review-panel-decides-an-application-together
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

/**
 * Whether a review panel has reached the decision being made.
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#requirement-a-review-panel-decides-an-application-together
 */
final class ReviewPanelDecision {

	/**
	 * The advice that leads to each decision state.
	 *
	 * @var array<string,string>
	 */
	private const ADVICE_FOR = [
		'placed'     => 'place',
		'waitlisted' => 'waitlist',
		'rejected'   => 'reject',
	];

	/**
	 * The panel's member ids, without blanks or repeats.
	 *
	 * @var array<int,string>
	 */
	private readonly array $members;

	/**
	 * Constructor.
	 *
	 * @param mixed $panelMemberIds The round's panelMemberIds.
	 *
	 * @return void
	 */
	public function __construct(mixed $panelMemberIds) {
		$members = [];
		if (is_array($panelMemberIds) === true) {
			foreach ($panelMemberIds as $memberId) {
				if (is_string($memberId) === true && $memberId !== '' && in_array($memberId, $members, true) === false) {
					$members[] = $memberId;
				}
			}
		}

		$this->members = $members;
	}//end __construct()

	/**
	 * The panel's member ids; empty when the round names no panel.
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#requirement-a-review-panel-decides-an-application-together
	 */
	public function members(): array {
		return $this->members;
	}//end members()

	/**
	 * Why the decision may not be recorded yet, or null when the panel reached it.
	 *
	 * @param array<int,array<string,mixed>> $adviceRows The application's advice rows.
	 * @param string                         $target     The decision state: placed, waitlisted or rejected.
	 *
	 * @return string|null The refusal in words, or null.
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#requirement-a-review-panel-decides-an-application-together
	 */
	public function refusal(array $adviceRows, string $target): ?string {
		$wanted = (self::ADVICE_FOR[$target] ?? null);
		if ($this->members === [] || $wanted === null) {
			return null;
		}

		$advice = $this->adviceByMember(adviceRows: $adviceRows);
		$total = count($this->members);

		if (count($advice) < $total) {
			return sprintf(
				'The review panel has not decided yet: %d of %d members gave their advice.',
				count($advice),
				$total
			);
		}

		$votes = count(array_keys($advice, $wanted, true));
		if (($votes * 2) <= $total) {
			return sprintf(
				'The review panel did not advise this decision: %d of %d members advised it.',
				$votes,
				$total
			);
		}

		return null;
	}//end refusal()

	/**
	 * Each panel member's advice, one per member; advice from anyone else is not counted.
	 *
	 * @param array<int,array<string,mixed>> $adviceRows The advice rows.
	 *
	 * @return array<string,string> Advice per member id.
	 */
	private function adviceByMember(array $adviceRows): array {
		$advice = [];
		foreach ($adviceRows as $row) {
			$memberId = (string)($row['memberId'] ?? '');
			$value = (string)($row['advice'] ?? '');
			if (in_array($memberId, $this->members, true) === false || in_array($value, self::ADVICE_FOR, true) === false) {
				continue;
			}

			$advice[$memberId] = $value;
		}

		return $advice;
	}//end adviceByMember()
}//end class
