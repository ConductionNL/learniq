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
	 * The sentence per rule, singular and plural, with `%n` for the count.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const TITLES = [
		FirstTodayRules::BOOKINGS_TO_CONFIRM => [
			'%n conference booking waits for your confirmation',
			'%n conference bookings wait for your confirmation',
		],
		FirstTodayRules::NEARING_LIMIT => [
			'%n pupil is nearing the attendance limit',
			'%n pupils are nearing the attendance limit',
		],
		FirstTodayRules::LESSON_REGISTER_OPEN => [
			'%n lesson of yours today has no register yet',
			'%n lessons of yours today have no register yet',
		],
		FirstTodayRules::MISSING_HAND_INS => [
			'%n assignment is due today with hand-ins missing',
			'%n assignments are due today with hand-ins missing',
		],
		FirstTodayRules::UNAUTHORISED_ABSENCE => [
			'%n pupil of your class was absent without leave',
			'%n pupils of your class were absent without leave',
		],
		FirstTodayRules::MENTOR_TALKS_CLOSING => [
			'%n pupil has not chosen a mentor talk yet',
			'%n pupils have not chosen a mentor talk yet',
		],
		FirstTodayRules::PLAN_ENDING => [
			'%n plan ends without an evaluation',
			'%n plans end without an evaluation',
		],
		FirstTodayRules::SIGNAL_NOT_TAKEN_UP => [
			'%n signal has not been taken up',
			'%n signals have not been taken up',
		],
		FirstTodayRules::LEAVE_PAST_HALF => [
			'%n leave request is past half its decision time',
			'%n leave requests are past half their decision time',
		],
		FirstTodayRules::OVER_LIMIT_NOT_REPORTED => [
			'%n pupil is over the attendance limit and not reported',
			'%n pupils are over the attendance limit and not reported',
		],
		FirstTodayRules::REGISTERS_NOT_FILLED_AT_930 => [
			'%n group has no register filled in at 9.30',
			'%n groups have no register filled in at 9.30',
		],
		FirstTodayRules::PLACEMENT_WITHOUT_COMPANY => [
			'%n student of yours has no BPV company yet',
			'%n students of yours have no BPV company yet',
		],
		FirstTodayRules::VISIT_WITHOUT_DATE => [
			'%n company visit of yours has no date yet',
			'%n company visits of yours have no date yet',
		],
		FirstTodayRules::HOURS_WAITING => [
			'%n hour week has waited more than a week for its company',
			'%n hour weeks have waited more than a week for their company',
		],
	];

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
			if (is_array($raw) === false) {
				$raw = [];
			}

			foreach ($raw as $rule => $page) {
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
		$title = $this->title(rule: $rule, count: (int) ($card['count'] ?? 0));
		if ($title === null) {
			return null;
		}

		return [$title, $this->reason(rule: $rule, card: $card)];
	}//end lines()

	/**
	 * The card's sentence: what waits, with its count.
	 *
	 * @param string $rule  The rule that won.
	 * @param int    $count How many rows it found.
	 *
	 * @return string|null Null for a rule Today writes no card for.
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
	 */
	private function title(string $rule, int $count): ?string {
		if ($rule === FirstTodayRules::REGISTER_NOT_FILLED) {
			return $this->l10n->t('Today\'s register is not filled in yet');
		}

		$forms = (self::TITLES[$rule] ?? null);
		if ($forms === null) {
			return null;
		}

		return $this->l10n->n($forms[0], $forms[1], $count);
	}//end title()

	/**
	 * The card's line of context under the sentence.
	 *
	 * @param string               $rule The rule that won.
	 * @param array<string, mixed> $card Its facts.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/today-first-per-school-role/specs/dashboard/spec.md#requirement-today-shows-one-first-today-card-per-role-from-the-roles-ordered-rules
	 */
	private function reason(string $rule, array $card): string {
		$l10n = $this->l10n;

		return match ($rule) {
			FirstTodayRules::REGISTER_NOT_FILLED => $this->registerReason(card: $card),
			FirstTodayRules::BOOKINGS_TO_CONFIRM => $l10n->t('Confirm them so the parents know the time.'),
			FirstTodayRules::NEARING_LIMIT => $l10n->t('Talk to them before the limit is reached.'),
			FirstTodayRules::LESSON_REGISTER_OPEN => $this->lessonReason(title: $this->nameOf(value: $card['sessionTitle'] ?? null)),
			FirstTodayRules::MISSING_HAND_INS => $l10n->n('%n hand-in is still missing.', '%n hand-ins are still missing.', (int) ($card['missing'] ?? 0)),
			FirstTodayRules::UNAUTHORISED_ABSENCE => $l10n->t('In the last two weeks, and not yet handled.'),
			FirstTodayRules::MENTOR_TALKS_CLOSING => $this->roundReason(round: $this->nameOf(value: $card['roundName'] ?? null)),
			FirstTodayRules::PLAN_ENDING => $l10n->t('Plan the evaluation before the plan ends.'),
			FirstTodayRules::SIGNAL_NOT_TAKEN_UP => $l10n->t('Pick one up to start the follow-up.'),
			FirstTodayRules::LEAVE_PAST_HALF => $l10n->t('Decide before the decision date.'),
			FirstTodayRules::OVER_LIMIT_NOT_REPORTED => $l10n->t('Report them to the attendance officer.'),
			FirstTodayRules::PLACEMENT_WITHOUT_COMPANY => $this->lateReason(late: $this->nameOf(value: $card['lateLabel'] ?? null)),
			FirstTodayRules::VISIT_WITHOUT_DATE => $l10n->t('Propose a date to the workplace trainer.'),
			FirstTodayRules::HOURS_WAITING => $this->hoursReason(hours: (float) ($card['hours'] ?? 0), week: $this->nameOf(value: $card['isoWeek'] ?? null)),
			default => $this->groupsReason(count: (int) ($card['count'] ?? 0), total: (int) ($card['total'] ?? 0)),
		};
	}//end reason()

	/**
	 * The line under an open lesson register: the first lesson by name, else that it ended.
	 *
	 * @param string|null $title The lesson's title.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/today-first-per-school-role/tasks.md#T1
	 */
	private function lessonReason(?string $title): string {
		if ($title === null) {
			return $this->l10n->t('The lesson has ended.');
		}

		return $this->l10n->t('The first is %1$s.', [$title]);
	}//end lessonReason()

	/**
	 * The line under mentor talks that close: the round by name, else without it.
	 *
	 * @param string|null $round The round's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/today-first-per-school-role/tasks.md#T1
	 */
	private function roundReason(?string $round): string {
		if ($round === null) {
			return $this->l10n->t('Booking closes soon.');
		}

		return $this->l10n->t('Booking for %1$s closes soon.', [$round]);
	}//end roundReason()

	/**
	 * The line under the groups without a register at 9.30: how many of the groups with lessons filled theirs in.
	 *
	 * @param int $count The groups without one.
	 * @param int $total The groups with lessons today.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/today-first-per-school-role/tasks.md#T1
	 */
	private function groupsReason(int $count, int $total): string {
		return $this->l10n->t('%1$s of %2$s groups with lessons today filled theirs in.', [(string) max(0, $total - $count), (string) $total]);
	}//end groupsReason()

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
	 * The line under a student without a company: how late, when the placement says so.
	 *
	 * @param string|null $late The placement's late line, such as "5 weken te laat".
	 *
	 * @return string
	 *
	 * @spec openspec/changes/bpv-coach-today-and-placement-board/tasks.md#T5
	 */
	private function lateReason(?string $late): string {
		if ($late === null) {
			return $this->l10n->t('The BPV period is running.');
		}

		return $this->l10n->t('The BPV period is running: %1$s.', [$late]);
	}//end lateReason()

	/**
	 * The line under hours that wait: how many hours, and the week that waits longest.
	 *
	 * @param float       $hours The hours waiting over those weeks.
	 * @param string|null $week  The longest waiting week, such as "2026-W40".
	 *
	 * @return string
	 *
	 * @spec openspec/changes/bpv-coach-today-and-placement-board/tasks.md#T5
	 */
	private function hoursReason(float $hours, ?string $week): string {
		$amount = rtrim(rtrim(number_format($hours, 1, '.', ''), '0'), '.');
		if ($week === null) {
			return $this->l10n->t('%1$s hours wait for approval.', [$amount]);
		}

		return $this->l10n->t('%1$s hours wait for approval, the longest since %2$s.', [$amount, $week]);
	}//end hoursReason()

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
