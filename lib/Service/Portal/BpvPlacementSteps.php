<?php

/**
 * Learniq BpvPlacementSteps
 *
 * The five steps of a work placement, as the student and the workplace
 * trainer read them on the placement (board esdoornveen Detail: "Waar sta
 * je?"): the agreement signed, the work plan made, the midterm review, the
 * final review, the placement finished. Every step is read from what the
 * school already records, so nothing is typed twice:
 *
 * - signed: the last signature on the placement's agreement (pok-signature);
 * - work plan: the first finished progress visit (bpv-visit-report
 *   `voortgangsbezoek`);
 * - midterm and final review: the visit reports `tussentijds-gesprek` and
 *   `eindgesprek`, done once finalised, planned while still a draft;
 * - finished: the placement's state `completed`, on `periodTo`.
 *
 * Portaliq asks the provider for them with the placement's id, after it
 * checked that the reader may see that placement (portaliq REQ-SMO-022)
 * (placement-steps-and-assessment-draft).
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
 * @spec openspec/changes/site-workplace-trainer-portal-design/specs/portal-contribution/spec.md#requirement-new-a-placement-shows-where-it-stands
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Answers a placement's steps.
 *
 * @spec openspec/changes/site-workplace-trainer-portal-design/specs/portal-contribution/spec.md#requirement-new-a-placement-shows-where-it-stands
 */
class BpvPlacementSteps {

	private const REGISTER = 'learniq';

	private const ZONE = 'Europe/Amsterdam';

	private const MONTHS = [
		'januari',
		'februari',
		'maart',
		'april',
		'mei',
		'juni',
		'juli',
		'augustus',
		'september',
		'oktober',
		'november',
		'december',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService Reads the placement and what belongs to it.
	 * @param IFactory        $l10n          The labels in the reader's language.
	 * @param LoggerInterface $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IFactory $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The steps of one placement, or none when it cannot be read.
	 *
	 * @param string $placementId The placement's uuid.
	 *
	 * @return array<int, array<string, string>>
	 *
	 * @spec openspec/changes/site-workplace-trainer-portal-design/specs/portal-contribution/spec.md#requirement-new-a-placement-shows-where-it-stands
	 */
	public function forPlacement(string $placementId): array {
		try {
			$placement = $this->rows(schema: 'bpv-placement', filters: [], ids: [$placementId])[0] ?? null;
			if ($placement === null) {
				return [];
			}

			$signatures = [];
			foreach ($this->rows(schema: 'praktijkovereenkomst', filters: ['bpvPlacementId' => $placementId]) as $agreement) {
				$signatures = array_merge($signatures, $this->rows(schema: 'pok-signature', filters: ['subjectId' => (string)($agreement['id'] ?? '')]));
			}

			$visits = $this->rows(schema: 'bpv-visit-report', filters: ['bpvPlacementId' => $placementId]);
		} catch (Throwable $exception) {
			$this->logger->warning('[BpvPlacementSteps] Could not read a placement: {msg}', ['msg' => $exception->getMessage()]);
			return [];
		}

		return $this->stepsOf(placement: $placement, signatures: $signatures, visits: $visits, l10n: $this->l10n->get('learniq'));
	}//end forPlacement()

	/**
	 * The steps the placement is at, from its rows.
	 *
	 * @param array<string, mixed>             $placement  The placement.
	 * @param array<int, array<string, mixed>> $signatures The signatures on its agreement.
	 * @param array<int, array<string, mixed>> $visits     Its visit reports.
	 * @param IL10N|null                       $l10n       The labels' language, or null for English.
	 *
	 * @return array<int, array<string, string>>
	 *
	 * @spec openspec/changes/site-workplace-trainer-portal-design/specs/portal-contribution/spec.md#requirement-new-a-placement-shows-where-it-stands
	 */
	public function stepsOf(array $placement, array $signatures, array $visits, ?IL10N $l10n): array {
		$signedAt = $this->latest(rows: $signatures, field: 'signedAt');
		$workplan = $this->visit(visits: $visits, kind: 'voortgangsbezoek', finalisedOnly: true);
		$midterm = $this->visit(visits: $visits, kind: 'tussentijds-gesprek', finalisedOnly: false);
		$final = $this->visit(visits: $visits, kind: 'eindgesprek', finalisedOnly: false);
		$end = $this->date(value: ($placement['periodTo'] ?? null));
		$completed = ($placement['lifecycle'] ?? '') === 'completed';

		$finalLine = '';
		if ($final === null && $end !== null) {
			$finalLine = self::MONTHS[((int)$end->format('n') - 1)] . ' ' . $end->format('Y');
		}

		$steps = [
			[$this->text(l10n: $l10n, text: 'Agreement signed'), $signedAt !== null, $this->longDate(day: $signedAt), (string)$signedAt?->format('Y-m-d')],
			[$this->text(l10n: $l10n, text: 'Work plan made'), $this->done(visit: $workplan), $this->visitLine(visit: $workplan), ''],
			[$this->text(l10n: $l10n, text: 'Midterm review'), $this->done(visit: $midterm), $this->visitLine(visit: $midterm), ''],
			[$this->text(l10n: $l10n, text: 'Final review'), $this->done(visit: $final), $this->visitLine(visit: $final) . $finalLine, ''],
			[$this->text(l10n: $l10n, text: 'Placement finished'), $completed, $this->longDate(day: $end), ''],
		];

		return $this->withStates(steps: $steps);
	}//end stepsOf()

	/**
	 * Done steps keep `done`; the first open one is `current`; the rest `todo`.
	 *
	 * @param array<int, array{0: string, 1: bool, 2: string, 3: string}> $steps Label, done, line, date.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function withStates(array $steps): array {
		$out = [];
		$currentGiven = false;
		foreach ($steps as [$label, $done, $line, $date]) {
			$state = 'todo';
			if ($done === true) {
				$state = 'done';
			} else if ($currentGiven === false) {
				$state = 'current';
				$currentGiven = true;
			}

			$step = ['label' => $label, 'state' => $state];
			if ($line !== '') {
				$step['description'] = $line;
			}

			if ($date !== '') {
				$step['date'] = $date;
			}

			$out[] = $step;
		}

		return $out;
	}//end withStates()

	/**
	 * The visit report of a kind: the first finalised one, or with
	 * `finalisedOnly` false the first finalised else the first planned one.
	 *
	 * @param array<int, array<string, mixed>> $visits        The visit reports.
	 * @param string                           $kind          The visit kind.
	 * @param bool                             $finalisedOnly Only a finished visit counts.
	 *
	 * @return array<string, mixed>|null
	 */
	private function visit(array $visits, string $kind, bool $finalisedOnly): ?array {
		$ofKind = array_values(array_filter($visits, static fn (array $v): bool => ($v['visitKind'] ?? '') === $kind));
		usort($ofKind, static fn (array $a, array $b): int => strcmp((string)($a['visitDate'] ?? ''), (string)($b['visitDate'] ?? '')));
		foreach ($ofKind as $visit) {
			if (($visit['lifecycle'] ?? '') === 'finalized') {
				return $visit;
			}
		}

		if ($finalisedOnly === true) {
			return null;
		}

		return ($ofKind[0] ?? null);
	}//end visit()

	/**
	 * Whether a visit has happened and is written up.
	 *
	 * @param array<string, mixed>|null $visit The visit report.
	 *
	 * @return bool
	 */
	private function done(?array $visit): bool {
		return ($visit['lifecycle'] ?? '') === 'finalized';
	}//end done()

	/**
	 * "13 oktober 2026" for a visit, or ''.
	 *
	 * @param array<string, mixed>|null $visit The visit report.
	 *
	 * @return string
	 */
	private function visitLine(?array $visit): string {
		return $this->longDate(day: $this->date(value: ($visit['visitDate'] ?? null)));
	}//end visitLine()

	/**
	 * The latest date-time of a field among rows, or null.
	 *
	 * @param array<int, array<string, mixed>> $rows  The rows.
	 * @param string                           $field The field.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function latest(array $rows, string $field): ?DateTimeImmutable {
		$latest = null;
		foreach ($rows as $row) {
			$date = $this->date(value: ($row[$field] ?? null));
			if ($date !== null && ($latest === null || $date > $latest)) {
				$latest = $date;
			}
		}

		return $latest;
	}//end latest()

	/**
	 * "27 augustus 2026", or ''.
	 *
	 * @param DateTimeImmutable|null $day The day.
	 *
	 * @return string
	 */
	private function longDate(?DateTimeImmutable $day): string {
		if ($day === null) {
			return '';
		}

		return $day->format('j') . ' ' . self::MONTHS[((int)$day->format('n') - 1)] . ' ' . $day->format('Y');
	}//end longDate()

	/**
	 * A date in the school's zone, or null.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function date(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($value, new DateTimeZone(self::ZONE)))->setTimezone(new DateTimeZone(self::ZONE));
		} catch (\Exception) {
			return null;
		}
	}//end date()

	/**
	 * A label in the reader's language.
	 *
	 * @param IL10N|null $l10n The language.
	 * @param string     $text The English text.
	 *
	 * @return string
	 */
	private function text(?IL10N $l10n, string $text): string {
		if ($l10n === null) {
			return $text;
		}

		return $l10n->t($text);
	}//end text()

	/**
	 * Rows of a schema by filters and optionally by ids.
	 *
	 * @param string                $schema  The schema slug.
	 * @param array<string, string> $filters Field equals value.
	 * @param array<int, string>    $ids     Object ids, or none.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 */
	private function rows(string $schema, array $filters, array $ids=[]): array {
		if (in_array('', $filters, true) === true || in_array('', $ids, true) === true) {
			return [];
		}

		$config = ['filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters), 'limit' => 100];
		if ($ids !== []) {
			$config['ids'] = $ids;
		}

		$objects = $this->objectService->findAll(config: $config, _rbac: false, _multitenancy: false);
		$rows = array_map(fn (mixed $object): array => $this->toRow(object: $object), $objects);
		if ($ids === []) {
			return $rows;
		}

		return array_values(array_filter($rows, static fn (array $row): bool => in_array(($row['id'] ?? null), $ids, true)));
	}//end rows()

	/**
	 * An OpenRegister row as an array.
	 *
	 * @param mixed $object An array or a serialisable entity.
	 *
	 * @return array<string, mixed>
	 */
	private function toRow(mixed $object): array {
		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$object = $object->jsonSerialize();
		}

		if (is_array($object) === false) {
			return [];
		}

		return $object;
	}//end toRow()
}//end class
