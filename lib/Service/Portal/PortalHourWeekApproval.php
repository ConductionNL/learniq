<?php

/**
 * Learniq PortalHourWeekApproval
 *
 * A workplace trainer approves one week of her student's BPV hours, or
 * approves another number and says why.
 *
 * WHY THE TWO NUMBERS BOTH STAY. The hours the student entered and the hours
 * the trainer approved are separate fields on `BpvHourWeek`. A correction
 * therefore never overwrites what the student said: the school, the school
 * coach and the student herself can all read that she wrote 32 and that 30
 * were approved, with the trainer's note beside it.
 *
 * WHY THE TRAINER'S WORD IS FINAL. The alternative was considered: a corrected
 * week waits for the student to acknowledge it before it counts. It was not
 * taken, because hours a trainer has corrected are the hours the leerbedrijf
 * will stand behind, and a week stuck between the two helps nobody at the end
 * of a placement. What the student gets instead is sight of it: the correction,
 * the note and who made it are on her own page, so she is never overruled
 * silently and can raise it with her school coach.
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
 * @spec openspec/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-is-a-record-of-its-own
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use DateTimeImmutable;
use OCA\Learniq\Kernel\Contracts\PortalHourWeekApprovalInterface;
use OCA\Learniq\Service\HourDayLines;
use OCA\Learniq\Service\HourReturnSubject;
use OCA\Learniq\Support\SerialisedRow;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Checks, stamps and stores a trainer's approval of one week of hours.
 *
 * @spec openspec/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-is-a-record-of-its-own
 */
class PortalHourWeekApproval implements PortalHourWeekApprovalInterface {

	private const REGISTER = 'learniq';

	private const WEEK_SCHEMA = 'bpv-hour-week';

	private const PLACEMENT_SCHEMA = 'bpv-placement';

	private const TRAINER_SCHEMA = 'praktijkopleider';

	/**
	 * The school's floor for the assurance of an approval, shared with the
	 * assessment floor: both are the trainer's word about a student's record.
	 */
	public const MIN_ASSURANCE_KEY = 'bpv_assessment_min_assurance';

	/**
	 * What a portal session's trust means on the eIDAS ladder.
	 *
	 * @var array<string, string>
	 */
	private const TRUST_TO_ASSURANCE = ['low' => 'basic', 'substantial' => 'substantial', 'high' => 'high'];

	/**
	 * The ladder, in order, so two levels can be compared.
	 *
	 * @var array<string, int>
	 */
	private const ASSURANCE_ORDER = ['none' => 0, 'basic' => 1, 'substantial' => 2, 'high' => 3];

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService Reads the week, its placement and the trainer; writes the approval.
	 * @param IAppConfig      $appConfig     Holds the school's assurance floor.
	 * @param LoggerInterface $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Approve one week for the trainer the assertion names.
	 *
	 * @param string               $trainerRef The `practicalTrainerId` claim.
	 * @param string               $trust      The session's trust (`low`, `substantial`, `high`).
	 * @param array<string, mixed> $body       What the form sent.
	 *
	 * @return PortalOutcome
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-is-a-record-of-its-own
	 */
	public function approve(string $trainerRef, string $trust, array $body): PortalOutcome {
		$assurance = (self::TRUST_TO_ASSURANCE[$trust] ?? 'basic');
		$weekId = $this->text(value: ($body['hourWeekId'] ?? null));
		$refusal = $this->requestRefusal(assurance: $assurance, request: ['trainerRef' => $trainerRef, 'weekId' => $weekId]);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$checked = $this->checkedWeek(weekId: $weekId, trainerRef: $trainerRef);
			if ($checked['refusal'] !== null) {
				return $checked['refusal'];
			}

			$return = $this->returnOf(week: $checked['week'], body: $body);
			if ($return instanceof PortalOutcome) {
				return $return;
			}

			$saved = $this->objectService->saveObject(
				object: $this->stamped(
					week: $checked['week'],
					trainerRef: $trainerRef,
					trainer: $checked['trainer'],
					assurance: $assurance,
					body: $body,
					return: $return
				),
				register: self::REGISTER,
				schema: self::WEEK_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[PortalHourWeekApproval] A week of hours could not be approved: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
			return new PortalOutcome(status: 502, body: ['error' => 'downstream_error'], reason: 'downstream');
		}//end try

		$row = $this->toRow(object: $saved);

		return new PortalOutcome(
			status: 200,
			body: [
				'hourWeekId' => (string)($row['id'] ?? ($row['uuid'] ?? $weekId)),
				'hoursApproved' => ($row['hoursApproved'] ?? null),
				'lifecycle' => (string)($row['lifecycle'] ?? ''),
				'assuranceLevel' => $assurance,
			]
		);
	}//end approve()

	/**
	 * Why the request is refused before any read: assurance below the floor, or an incomplete request.
	 *
	 * @param string               $assurance The caller's assurance level.
	 * @param array<string, mixed> $request   trainerRef and weekId.
	 *
	 * @return PortalOutcome|null Null when the request may proceed.
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-is-a-record-of-its-own
	 */
	private function requestRefusal(string $assurance, array $request): ?PortalOutcome {
		$floor = $this->floor();
		if (self::ASSURANCE_ORDER[$assurance] < self::ASSURANCE_ORDER[$floor]) {
			return new PortalOutcome(status: 403, body: ['error' => 'assurance_too_low', 'required' => $floor], reason: 'assurance-too-low');
		}

		if ($request['trainerRef'] === '' || $request['weekId'] === '') {
			return new PortalOutcome(status: 422, body: ['error' => 'incomplete'], reason: 'incomplete');
		}

		return null;
	}//end requestRefusal()

	/**
	 * The week and the trainer, or the refusal when the week is missing or decided,
	 * the trainer unknown, or the student not the trainer's.
	 *
	 * @param string $weekId     The week of hours.
	 * @param string $trainerRef The approving trainer.
	 *
	 * @return array{week: array<string, mixed>|null, trainer: array<string, mixed>|null, refusal: PortalOutcome|null}
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-is-a-record-of-its-own
	 */
	private function checkedWeek(string $weekId, string $trainerRef): array {
		$week = $this->row(schema: self::WEEK_SCHEMA, id: $weekId);
		if ($week === null) {
			return [
				'week' => null,
				'trainer' => null,
				'refusal' => new PortalOutcome(status: 404, body: ['error' => 'not_found'], reason: 'week-not-found'),
			];
		}

		if (($week['lifecycle'] ?? 'submitted') !== 'submitted') {
			return [
				'week' => $week,
				'trainer' => null,
				'refusal' => new PortalOutcome(status: 409, body: ['error' => 'already_decided'], reason: 'already-decided'),
			];
		}

		$trainer = $this->row(schema: self::TRAINER_SCHEMA, id: $trainerRef);
		if ($trainer === null) {
			return [
				'week' => $week,
				'trainer' => null,
				'refusal' => new PortalOutcome(status: 403, body: ['error' => 'unknown_trainer'], reason: 'unknown-trainer'),
			];
		}

		if ($this->ownsPlacement(trainerRef: $trainerRef, placementId: $this->text(value: ($week['bpvPlacementId'] ?? null))) === false) {
			return [
				'week' => $week,
				'trainer' => $trainer,
				'refusal' => new PortalOutcome(status: 403, body: ['error' => 'not_your_student'], reason: 'not-your-student'),
			];
		}

		return ['week' => $week, 'trainer' => $trainer, 'refusal' => null];
	}//end checkedWeek()

	/**
	 * The week as it is stored once the trainer has decided: her number, her
	 * note, her name and the assurance of her session. The student's own
	 * `hoursSubmitted` is never touched.
	 *
	 * @param array<string, mixed> $week       The stored week.
	 * @param string               $trainerRef The trainer's own uuid.
	 * @param array<string, mixed> $trainer    The Praktijkopleider record.
	 * @param string               $assurance  The eIDAS level of the session.
	 * @param array<string, mixed> $body       What the form sent.
	 * @param array{days: array<int, array<string, mixed>>|null, question: string, date: string}|null $return The return, when she sends the week back.
	 *
	 * @return array<string, mixed>
	 */
	private function stamped(array $week, string $trainerRef, array $trainer, string $assurance, array $body, ?array $return=null): array {
		$submitted = (float)($week['hoursSubmitted'] ?? 0);
		$approved = self::approvedHours(submitted: $submitted, body: $body);
		if ($return !== null) {
			// A week sent back approves nothing yet: the student changes the day first.
			$approved = 0.0;
			$body['note'] = $return['question'];
			if ($return['days'] !== null) {
				$week['days'] = $return['days'];
			}

			// The line the student reads in her messages (bpv-hours-per-day).
			$week['returnSubject'] = HourReturnSubject::of(date: $return['date'], isoWeek: $this->text(value: ($week['isoWeek'] ?? null)));
		}

		$lifecycle = self::lifecycleOf(approved: $approved, submitted: $submitted);
		$name = trim(((string)($trainer['givenName'] ?? '')) . ' ' . ((string)($trainer['familyName'] ?? '')));

		return array_merge(
			$week,
			[
				'hoursApproved' => $approved,
				'approvedBy' => $trainerRef,
				'approvedByName' => $name,
				'approvedAt' => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
				'assuranceLevel' => $assurance,
				'note' => $this->text(value: ($body['note'] ?? ($week['note'] ?? null))),
				'lifecycle' => $lifecycle,
			]
		);
	}//end stamped()

	/**
	 * The hours the trainer approves: the number she sent, else the hours the
	 * student submitted.
	 *
	 * @param float                $submitted The hours the student submitted.
	 * @param array<string, mixed> $body      What the form sent.
	 *
	 * @return float
	 */
	private static function approvedHours(float $submitted, array $body): float {
		if (is_numeric(($body['hoursApproved'] ?? null)) === true) {
			return (float)$body['hoursApproved'];
		}

		return $submitted;
	}//end approvedHours()

	/**
	 * The week's lifecycle after the decision: rejected when nothing is
	 * approved, corrected when the number differs from what was submitted,
	 * else approved.
	 *
	 * @param float $approved  The approved hours.
	 * @param float $submitted The submitted hours.
	 *
	 * @return string
	 */
	private static function lifecycleOf(float $approved, float $submitted): string {
		if ($approved <= 0.0) {
			return 'rejected';
		}

		if ($approved !== $submitted) {
			return 'corrected';
		}

		return 'approved';
	}//end lifecycleOf()

	/**
	 * The return the trainer asks for, null when she approves, or the refusal.
	 *
	 * She returns a week by sending `outcome: returned`, or by approving no
	 * hours of a week that has day lines. A week with day lines is returned
	 * about one of its days (`returnedDate`) with a question; the question is
	 * written on that day line. A week without day lines needs the question
	 * only. Approving no hours of a week without day lines stays the plain
	 * rejection it was.
	 *
	 * @param array<string, mixed> $week The stored week.
	 * @param array<string, mixed> $body What the form sent.
	 *
	 * @return array{days: array<int, array<string, mixed>>|null, question: string, date: string}|PortalOutcome|null
	 *
	 * @spec openspec/changes/bpv-hours-per-day/specs/bpv/spec.md#requirement-a-returned-week-names-the-day-and-the-question
	 */
	private function returnOf(array $week, array $body): array|PortalOutcome|null {
		$days = ($week['days'] ?? null);
		$hasDays = is_array($days) === true && $days !== [];
		if ($this->returnAsked(body: $body, hasDays: $hasDays) === false) {
			return null;
		}

		$question = $this->text(value: ($body['question'] ?? null));
		$date = $this->text(value: ($body['returnedDate'] ?? null));
		if (self::returnIncomplete(question: $question, date: $date, hasDays: $hasDays) === true) {
			return new PortalOutcome(
				status: 422,
				body: ['error' => 'return_needs_day_and_question', 'message' => 'Name the day you have a question about, and write your question.'],
				reason: 'return-incomplete'
			);
		}

		if ($hasDays === false) {
			return ['days' => null, 'question' => $question, 'date' => ''];
		}

		return self::returnedDays(days: $days, date: $date, question: $question);
	}//end returnOf()

	/**
	 * Whether the trainer returns the week: she asked to, or she approves no
	 * hours of a week that has day lines.
	 *
	 * @param array<string, mixed> $body    What the form sent.
	 * @param bool                 $hasDays Whether the week has day lines.
	 *
	 * @return bool
	 */
	private function returnAsked(array $body, bool $hasDays): bool {
		$asked = $this->text(value: ($body['outcome'] ?? null)) === 'returned';
		$none = is_numeric(($body['hoursApproved'] ?? null)) === true && (float)$body['hoursApproved'] <= 0.0;

		return $asked === true || ($none === true && $hasDays === true);
	}//end returnAsked()

	/**
	 * Whether a return lacks its question, or the day of a week with day lines.
	 *
	 * @param string $question The question.
	 * @param string $date     The day the question is about.
	 * @param bool   $hasDays  Whether the week has day lines.
	 *
	 * @return bool
	 */
	private static function returnIncomplete(string $question, string $date, bool $hasDays): bool {
		return $question === '' || ($hasDays === true && $date === '');
	}//end returnIncomplete()

	/**
	 * The return of a week with day lines: the lines with the question on the
	 * named day, or the refusal when no line holds that day.
	 *
	 * @param array<int|string, mixed> $days     The day lines.
	 * @param string                   $date     The day the question is about.
	 * @param string                   $question The question.
	 *
	 * @return array{days: array<int, array<string, mixed>>, question: string, date: string}|PortalOutcome
	 */
	private static function returnedDays(array $days, string $date, string $question): array|PortalOutcome {
		$lines = HourDayLines::withQuestion(days: $days, date: $date, question: $question);
		if ($lines === null) {
			return new PortalOutcome(
				status: 422,
				body: ['error' => 'returned_day_unknown', 'message' => 'The day you named is not one of the days of this week.'],
				reason: 'return-day-unknown'
			);
		}

		return ['days' => $lines, 'question' => $question, 'date' => $date];
	}//end returnedDays()

	/**
	 * The school's floor for the assurance of an approval.
	 *
	 * @return string
	 */
	private function floor(): string {
		$floor = $this->appConfig->getValueString('learniq', self::MIN_ASSURANCE_KEY, 'basic');
		if (isset(self::ASSURANCE_ORDER[$floor]) === false) {
			return 'basic';
		}

		return $floor;
	}//end floor()

	/**
	 * Whether the placement is this trainer's own.
	 *
	 * @param string $trainerRef  The trainer's uuid.
	 * @param string $placementId The placement's uuid.
	 *
	 * @return bool
	 */
	private function ownsPlacement(string $trainerRef, string $placementId): bool {
		if ($placementId === '') {
			return false;
		}

		$placement = $this->row(schema: self::PLACEMENT_SCHEMA, id: $placementId);

		return $placement !== null && (string)($placement['practicalTrainerId'] ?? '') === $trainerRef;
	}//end ownsPlacement()

	/**
	 * One row by uuid, RBAC off (the receiver has no session), or null.
	 *
	 * Every caller has already refused an empty id: `approve()` answers 422
	 * before it reads anything, and `ownsPlacement()` answers false. There is
	 * therefore no empty-id guard here, because there was no caller that could
	 * reach it.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function row(string $schema, string $id): ?array {
		$objects = $this->objectService->findAll(
			config: [
				'filters' => ['register' => self::REGISTER, 'schema' => $schema],
				'ids' => [$id],
				'limit' => 1,
			],
			_rbac: false,
			_multitenancy: false
		);

		foreach ($objects as $object) {
			$row = $this->toRow(object: $object);
			if (($row['id'] ?? ($row['uuid'] ?? null)) === $id) {
				return $row;
			}
		}

		return null;
	}//end row()

	/**
	 * An OpenRegister row as an array.
	 *
	 * @param mixed $object The row.
	 *
	 * @return array<string, mixed>
	 */
	private function toRow(mixed $object): array {
		return (new SerialisedRow())->asArray(object: $object);
	}//end toRow()

	/**
	 * A string value, or '' for anything else.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function text(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end text()
}//end class
