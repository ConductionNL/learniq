<?php

/**
 * Learniq First Today Card
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the First today card says and opens for the rule that won: one
 * sentence (`title`), one line of context (`reason`) and the page its action
 * opens (`page`). Today draws ONE attention card that reads these three from
 * `GET /api/today/first`, so the page asks once and a role only ever sees its
 * own rule. The pages are in first-today-pages.json, which the structure test
 * reads too: each is a page every role of the rule already reaches.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Today
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Today;

use OCP\IL10N;

/**
 * Writes the sentence, the context line and the page of a First today card.
 *
 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
 */
final class FirstTodayCard {

	/**
	 * The page per rule, read once.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $pages = null;

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n The translator.
	 *
	 * @return void
	 */
	public function __construct(private readonly IL10N $l10n) {
	}//end __construct()

	/**
	 * The card with its `title`, `reason` and `page` added, when its rule has a page.
	 *
	 * A card without a rule, or with a rule no page belongs to, comes back as
	 * it was: without a title Today shows no card.
	 *
	 * @param array<string, mixed> $card The service's answer.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
	 */
	public function describe(array $card): array {
		$rule = $card['rule'] ?? null;
		if (is_string($rule) === false) {
			return $card;
		}

		$page = ($this->pages()[$rule] ?? null);
		$lines = $this->lines(rule: $rule, card: $card);
		if ($page === null || $lines === null) {
			return $card;
		}

		return array_merge($card, ['title' => $lines[0], 'reason' => $lines[1], 'page' => $page]);
	}//end describe()

	/**
	 * The page per rule, from first-today-pages.json.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/today-first-per-school-role/tasks.md#T1
	 */
	public function pages(): array {
		if ($this->pages === null) {
			$raw = json_decode((string) file_get_contents(__DIR__ . '/first-today-pages.json'), true);
			$this->pages = [];
			foreach ((is_array($raw) === true ? $raw : []) as $rule => $page) {
				if (is_string($page) === true && str_starts_with((string) $rule, '$') === false) {
					$this->pages[(string) $rule] = $page;
				}
			}
		}

		return $this->pages;
	}//end pages()

	/**
	 * The sentence and the context line of a rule.
	 *
	 * @param string               $rule The rule that won.
	 * @param array<string, mixed> $card Its facts.
	 *
	 * @return array{0: string, 1: string}|null Null for a rule Today writes no card for.
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
	 */
	private function lines(string $rule, array $card): ?array {
		$count = (int) ($card['count'] ?? 0);

		return match ($rule) {
			FirstTodayRules::REGISTER_NOT_FILLED => [
				$this->l10n->t('Today\'s register is not filled in yet'),
				$this->registerReason(card: $card),
			],
			FirstTodayRules::BOOKINGS_TO_CONFIRM => [
				$this->l10n->n('%n conference booking waits for your confirmation', '%n conference bookings wait for your confirmation', $count),
				$this->l10n->t('Confirm them so the parents know the time.'),
			],
			FirstTodayRules::NEARING_LIMIT => [
				$this->l10n->n('%n pupil is nearing the attendance limit', '%n pupils are nearing the attendance limit', $count),
				$this->l10n->t('Talk to them before the limit is reached.'),
			],
			FirstTodayRules::LESSON_REGISTER_OPEN => [
				$this->l10n->n('%n lesson of yours today has no register yet', '%n lessons of yours today have no register yet', $count),
				$this->nameOf(value: $card['sessionTitle'] ?? null) === null
					? $this->l10n->t('The lesson has ended.')
					: $this->l10n->t('The first is %1$s.', [$this->nameOf(value: $card['sessionTitle'])]),
			],
			FirstTodayRules::MISSING_HAND_INS => [
				$this->l10n->n('%n assignment is due today with hand-ins missing', '%n assignments are due today with hand-ins missing', $count),
				$this->l10n->n('%n hand-in is still missing.', '%n hand-ins are still missing.', (int) ($card['missing'] ?? 0)),
			],
			FirstTodayRules::UNAUTHORISED_ABSENCE => [
				$this->l10n->n('%n pupil of your class was absent without leave', '%n pupils of your class were absent without leave', $count),
				$this->l10n->t('In the last two weeks, and not yet handled.'),
			],
			FirstTodayRules::MENTOR_TALKS_CLOSING => [
				$this->l10n->n('%n pupil has not chosen a mentor talk yet', '%n pupils have not chosen a mentor talk yet', $count),
				$this->nameOf(value: $card['roundName'] ?? null) === null
					? $this->l10n->t('Booking closes soon.')
					: $this->l10n->t('Booking for %1$s closes soon.', [$this->nameOf(value: $card['roundName'])]),
			],
			FirstTodayRules::PLAN_ENDING => [
				$this->l10n->n('%n plan ends without an evaluation', '%n plans end without an evaluation', $count),
				$this->l10n->t('Plan the evaluation before the plan ends.'),
			],
			FirstTodayRules::SIGNAL_NOT_TAKEN_UP => [
				$this->l10n->n('%n signal has not been taken up', '%n signals have not been taken up', $count),
				$this->l10n->t('Pick one up to start the follow-up.'),
			],
			FirstTodayRules::LEAVE_PAST_HALF => [
				$this->l10n->n('%n leave request is past half its decision time', '%n leave requests are past half their decision time', $count),
				$this->l10n->t('Decide before the decision date.'),
			],
			FirstTodayRules::OVER_LIMIT_NOT_REPORTED => [
				$this->l10n->n('%n pupil is over the attendance limit and not reported', '%n pupils are over the attendance limit and not reported', $count),
				$this->l10n->t('Report them to the attendance officer.'),
			],
			FirstTodayRules::REGISTERS_NOT_FILLED_AT_930 => [
				$this->l10n->n('%n group has no register filled in at 9.30', '%n groups have no register filled in at 9.30', $count),
				$this->l10n->t(
					'%1$s of %2$s groups with lessons today filled theirs in.',
					[(string) max(0, (int) ($card['total'] ?? 0) - $count), (string) (int) ($card['total'] ?? 0)]
				),
			],
			default => null,
		};
	}//end lines()

	/**
	 * The context line of an open register: the parent reports in it, else that the first lesson started.
	 *
	 * @param array<string, mixed> $card The facts.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
	 */
	private function registerReason(array $card): string {
		$reports = (int) ($card['reportCount'] ?? 0);
		if ($reports > 0) {
			return $this->l10n->n('%n pupil was reported absent by a parent.', '%n pupils were reported absent by their parents.', $reports);
		}

		$cohort = $this->nameOf(value: $card['cohortName'] ?? null);
		if ($cohort === null) {
			return $this->l10n->t('The first lesson has started.');
		}

		return $this->l10n->t('The first lesson of %1$s has started.', [$cohort]);
	}//end registerReason()

	/**
	 * A name to put in a line, or null when there is none.
	 *
	 * @param mixed $value The value the facts carry.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/today-first-per-school-role/tasks.md#T1
	 */
	private function nameOf(mixed $value): ?string {
		if (is_string($value) === true && trim($value) !== '') {
			return trim($value);
		}

		return null;
	}//end nameOf()
}//end class
