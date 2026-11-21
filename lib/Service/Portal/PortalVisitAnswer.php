<?php

/**
 * Learniq PortalVisitAnswer
 *
 * The praktijkopleider's answer to a visit date the school coach proposed,
 * forwarded from her portal: she accepts the moment, and the visit is
 * planned for it, or she answers with a note, and the visit goes back to the
 * coach unplanned with her answer on it.
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
 * @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-a-school-coach-plans-visits-and-the-company-agrees-the-date
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use OCA\Learniq\Kernel\Contracts\PortalVisitAnswerInterface;
use OCA\Learniq\Support\SerialisedRow;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Checks and stores a trainer's answer to a proposed visit date.
 *
 * @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-a-school-coach-plans-visits-and-the-company-agrees-the-date
 */
class PortalVisitAnswer implements PortalVisitAnswerInterface {

	private const REGISTER = 'learniq';

	private const VISIT_SCHEMA = 'bpv-visit';

	private const PLACEMENT_SCHEMA = 'bpv-placement';

	private const TRAINER_SCHEMA = 'praktijkopleider';

	/**
	 * Accepting plans the visit for the proposed moment.
	 */
	public const OUTCOME_ACCEPT = 'accept';

	/**
	 * Answering sends the visit back to the coach with her note.
	 */
	public const OUTCOME_ANSWER = 'answer';

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService Reads the visit, its placement and the trainer; writes the answer.
	 * @param LoggerInterface $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Accept or answer a proposed visit date for the trainer the assertion names.
	 *
	 * @param string               $trainerRef The `practicalTrainerId` claim.
	 * @param array<string, mixed> $body       What the form sent.
	 *
	 * @return PortalOutcome
	 *
	 * @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-a-school-coach-plans-visits-and-the-company-agrees-the-date
	 */
	public function answer(string $trainerRef, array $body): PortalOutcome {
		$visitId  = $this->text(value: ($body['bpvVisitId'] ?? null));
		$outcome  = $this->text(value: ($body['outcome'] ?? null));
		$response = $this->text(value: ($body['trainerResponse'] ?? null));
		$answer   = ['trainerRef' => $trainerRef, 'visitId' => $visitId, 'outcome' => $outcome, 'response' => $response];
		$refusal  = self::requestRefusal(answer: $answer);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$checked = $this->checkedVisit(visitId: $visitId, trainerRef: $trainerRef);
			if ($checked instanceof PortalOutcome) {
				return $checked;
			}

			$saved = $this->objectService->saveObject(
				object: self::answered(visit: $checked, answer: $answer),
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: self::REGISTER, schema: self::VISIT_SCHEMA), uuid: $visitId),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[PortalVisitAnswer] A visit answer could not be stored: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
			return new PortalOutcome(status: 502, body: ['error' => 'downstream_error'], reason: 'downstream');
		}//end try

		$row = (new SerialisedRow())->asArray(object: $saved);

		return new PortalOutcome(
			status: 200,
			body: [
				'bpvVisitId' => (string)($row['id'] ?? ($row['uuid'] ?? $visitId)),
				'lifecycle' => (string)($row['lifecycle'] ?? ''),
				'plannedAt' => ($row['plannedAt'] ?? null),
			]
		);
	}//end answer()

	/**
	 * Why the request is refused before any read, or null.
	 *
	 * @param array{trainerRef: string, visitId: string, outcome: string, response: string} $answer The portal answer.
	 *
	 * @return PortalOutcome|null
	 */
	private static function requestRefusal(array $answer): ?PortalOutcome {
		['trainerRef' => $trainerRef, 'visitId' => $visitId, 'outcome' => $outcome, 'response' => $response] = $answer;
		if ($trainerRef === '' || $visitId === '') {
			return new PortalOutcome(status: 422, body: ['error' => 'incomplete'], reason: 'incomplete');
		}

		if (in_array($outcome, [self::OUTCOME_ACCEPT, self::OUTCOME_ANSWER], true) === false) {
			return new PortalOutcome(
				status: 422,
				body: ['error' => 'unknown_outcome', 'message' => 'Accept the proposed moment, or answer it with a note.'],
				reason: 'unknown-outcome'
			);
		}

		if ($outcome === self::OUTCOME_ANSWER && $response === '') {
			return new PortalOutcome(
				status: 422,
				body: ['error' => 'answer_needs_note', 'message' => 'Write which moment suits you, or why this one does not.'],
				reason: 'answer-needs-note'
			);
		}

		return null;
	}//end requestRefusal()

	/**
	 * The visit, or the refusal when it is missing, not waiting for her, or not hers.
	 *
	 * @param string $visitId    The visit.
	 * @param string $trainerRef The trainer.
	 *
	 * @return array<string, mixed>|PortalOutcome
	 */
	private function checkedVisit(string $visitId, string $trainerRef): array|PortalOutcome {
		$visit = $this->row(schema: self::VISIT_SCHEMA, id: $visitId);
		if ($visit === null) {
			return new PortalOutcome(status: 404, body: ['error' => 'not_found'], reason: 'visit-not-found');
		}

		if (($visit['lifecycle'] ?? '') !== 'proposed') {
			return new PortalOutcome(status: 409, body: ['error' => 'not_proposed'], reason: 'not-proposed');
		}

		if ($this->row(schema: self::TRAINER_SCHEMA, id: $trainerRef) === null) {
			return new PortalOutcome(status: 403, body: ['error' => 'unknown_trainer'], reason: 'unknown-trainer');
		}

		$placementId = $this->text(value: ($visit['bpvPlacementId'] ?? null));
		$placement   = null;
		if ($placementId !== '') {
			$placement = $this->row(schema: self::PLACEMENT_SCHEMA, id: $placementId);
		}

		if ($placement === null || (string)($placement['practicalTrainerId'] ?? '') !== $trainerRef) {
			return new PortalOutcome(status: 403, body: ['error' => 'not_your_student'], reason: 'not-your-student');
		}

		return $visit;
	}//end checkedVisit()

	/**
	 * The visit as it is stored after her answer.
	 *
	 * Accepting plans it for the proposed moment and clears an earlier note;
	 * answering sends it back unplanned with her note, and the proposed moment
	 * stays so the coach reads what she answered to.
	 *
	 * @param array<string, mixed>                                                         $visit  The stored visit.
	 * @param array{trainerRef: string, visitId: string, outcome: string, response: string} $answer The portal answer.
	 *
	 * @return array<string, mixed>
	 */
	private static function answered(array $visit, array $answer): array {
		['trainerRef' => $trainerRef, 'outcome' => $outcome, 'response' => $response] = $answer;
		$visit['practicalTrainerId'] = $trainerRef;
		if ($outcome === self::OUTCOME_ACCEPT) {
			$visit['plannedAt']       = ($visit['proposedAt'] ?? null);
			$visit['trainerResponse'] = null;
			$visit['lifecycle']       = 'planned';
			return $visit;
		}

		$visit['plannedAt']       = null;
		$visit['trainerResponse'] = $response;
		$visit['lifecycle']       = 'unplanned';

		return $visit;
	}//end answered()

	/**
	 * One row by uuid, RBAC off (the receiver has no session), or null.
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
			$row = (new SerialisedRow())->asArray(object: $object);
			if (($row['id'] ?? ($row['uuid'] ?? null)) === $id) {
				return $row;
			}
		}

		return null;
	}//end row()

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
