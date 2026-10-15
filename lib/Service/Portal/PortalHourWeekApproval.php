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
 * @spec openspec/changes/internship-hours/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-is-a-record-of-its-own
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Checks, stamps and stores a trainer's approval of one week of hours.
 *
 * @spec openspec/changes/internship-hours/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-is-a-record-of-its-own
 */
class PortalHourWeekApproval {

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
	 * @spec openspec/changes/internship-hours/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-is-a-record-of-its-own
	 */
	public function approve(string $trainerRef, string $trust, array $body): PortalOutcome {
		return $this->decide(trainerRef: $trainerRef, trust: $trust, body: $body, sendBack: false);
	}//end approve()

	/**
	 * Send one week back to the student with a question (board esdoornveen,
	 * "Terugsturen met een vraag"): no hours approved, the question as the
	 * week's note, which the student reads on her hours page. She corrects
	 * the week and sends it again.
	 *
	 * @param string               $trainerRef The `practicalTrainerId` claim.
	 * @param string               $trust      The session's trust (`low`, `substantial`, `high`).
	 * @param array<string, mixed> $body       What the form sent: `hourWeekId` and `note`.
	 *
	 * @return PortalOutcome
	 *
	 * @spec openspec/changes/trainer-returns-hours-with-a-question/specs/bpv/spec.md#requirement-the-trainer-sends-a-week-back-with-a-question
	 */
	public function sendBack(string $trainerRef, string $trust, array $body): PortalOutcome {
		if ($this->text(value: ($body['note'] ?? null)) === '') {
			return new PortalOutcome(status: 422, body: ['error' => 'question_required'], reason: 'question-required');
		}

		return $this->decide(trainerRef: $trainerRef, trust: $trust, body: $body, sendBack: true);
	}//end sendBack()

	/**
	 * Decide one week for the trainer the assertion names: approve it, or
	 * send it back. The checks are the same for both.
	 *
	 * @param string               $trainerRef The `practicalTrainerId` claim.
	 * @param string               $trust      The session's trust.
	 * @param array<string, mixed> $body       What the form sent.
	 * @param bool                 $sendBack   True to send the week back.
	 *
	 * @return PortalOutcome
	 */
	private function decide(string $trainerRef, string $trust, array $body, bool $sendBack): PortalOutcome {
		$assurance = (self::TRUST_TO_ASSURANCE[$trust] ?? 'basic');
		$floor = $this->floor();
		if (self::ASSURANCE_ORDER[$assurance] < self::ASSURANCE_ORDER[$floor]) {
			return new PortalOutcome(status: 403, body: ['error' => 'assurance_too_low', 'required' => $floor], reason: 'assurance-too-low');
		}

		$weekId = $this->text(value: ($body['hourWeekId'] ?? null));
		if ($trainerRef === '' || $weekId === '') {
			return new PortalOutcome(status: 422, body: ['error' => 'incomplete'], reason: 'incomplete');
		}

		if ($sendBack === true) {
			$body['hoursApproved'] = 0;
		}

		try {
			$refusal = $this->refusal(trainerRef: $trainerRef, weekId: $weekId);
			if ($refusal instanceof PortalOutcome) {
				return $refusal;
			}

			[$week, $trainer] = $refusal;
			$saved = $this->objectService->saveObject(
				object: $this->stamped(week: $week, trainerRef: $trainerRef, trainer: $trainer, assurance: $assurance, body: $body),
				register: self::REGISTER,
				schema: self::WEEK_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[PortalHourWeekApproval] A week of hours could not be decided: {msg}',
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
	}//end decide()

	/**
	 * The week and the trainer when she may decide it, else the refusal.
	 *
	 * @param string $trainerRef The trainer's uuid.
	 * @param string $weekId     The week's uuid.
	 *
	 * @return PortalOutcome|array{0: array<string, mixed>, 1: array<string, mixed>}
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 */
	private function refusal(string $trainerRef, string $weekId): PortalOutcome|array {
		$week = $this->row(schema: self::WEEK_SCHEMA, id: $weekId);
		if ($week === null) {
			return new PortalOutcome(status: 404, body: ['error' => 'not_found'], reason: 'week-not-found');
		}

		if (($week['lifecycle'] ?? 'submitted') !== 'submitted') {
			return new PortalOutcome(status: 409, body: ['error' => 'already_decided'], reason: 'already-decided');
		}

		$trainer = $this->row(schema: self::TRAINER_SCHEMA, id: $trainerRef);
		if ($trainer === null) {
			return new PortalOutcome(status: 403, body: ['error' => 'unknown_trainer'], reason: 'unknown-trainer');
		}

		if ($this->ownsPlacement(trainerRef: $trainerRef, placementId: $this->text(value: ($week['bpvPlacementId'] ?? null))) === false) {
			return new PortalOutcome(status: 403, body: ['error' => 'not_your_student'], reason: 'not-your-student');
		}

		return [$week, $trainer];
	}//end refusal()

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
	 *
	 * @return array<string, mixed>
	 */
	private function stamped(array $week, string $trainerRef, array $trainer, string $assurance, array $body): array {
		$submitted = (float)($week['hoursSubmitted'] ?? 0);
		$approved = $submitted;
		if (is_numeric(($body['hoursApproved'] ?? null)) === true) {
			$approved = (float)$body['hoursApproved'];
		}

		$lifecycle = 'approved';
		if ($approved !== $submitted) {
			$lifecycle = 'corrected';
		}

		if ($approved <= 0.0) {
			$lifecycle = 'rejected';
		}

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
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$row = $object->jsonSerialize();
			if (is_array($row) === true) {
				return $row;
			}
		}

		return [];
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
