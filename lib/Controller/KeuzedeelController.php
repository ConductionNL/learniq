<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * @spec openspec/changes/exam-committee-today-and-keuzedelen/specs/enrolment/spec.md#requirement-an-mbo-student-chooses-a-keuzedeel-inside-its-window
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Service\Exam\ExamProgressRestamp;
use OCA\Learniq\Service\Keuzedeel\KeuzedeelRules;
use OCA\Learniq\Service\Keuzedeel\KeuzedeelStore;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A student's keuzedelen: the offers open to her, her choices, and choosing one.
 *
 * The student is always the signed-in user, never a body or URL value; a
 * choice outside the window, for a full offer or a second one in the same
 * period is refused in words (KeuzedeelRules).
 *
 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T5
 */
class KeuzedeelController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest            $request       The request.
	 * @param IUserSession        $userSession   The signed-in student.
	 * @param KeuzedeelStore      $store         Reads offers and choices.
	 * @param ObjectService       $objectService Writes the choice.
	 * @param ExamProgressRestamp $restamp       Rewrites the student's page line.
	 * @param LoggerInterface     $logger        Logs a failed save.
	 * @param KeuzedeelRules      $rules         Decides whether she may choose.
	 *
	 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T5
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly KeuzedeelStore $store,
		private readonly ObjectService $objectService,
		private readonly ExamProgressRestamp $restamp,
		private readonly LoggerInterface $logger,
		private readonly KeuzedeelRules $rules = new KeuzedeelRules(),
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The offers open to the signed-in student, each with whether and why not she may choose it, and her line.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T5
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function mine(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$now    = self::now();
		$mine   = $this->store->choices(filters: ['learnerId' => $user->getUID()]);
		$offers = [];
		foreach ($this->store->offersFor(learnerUserId: $user->getUID()) as $offer) {
			$offerId  = (string) ($offer['id'] ?? ($offer['uuid'] ?? ''));
			$taken    = $this->store->choices(filters: ['offerId' => $offerId]);
			$offers[] = [
				'id'             => $offerId,
				'code'           => (string) ($offer['code'] ?? ''),
				'name'           => (string) ($offer['name'] ?? ''),
				'studyLoadHours' => $offer['studyLoadHours'] ?? null,
				'period'         => (string) ($offer['period'] ?? ''),
				'choiceClosesOn' => $offer['choiceClosesOn'] ?? null,
				'chosen'         => self::hasChosen(mine: $mine, offerId: $offerId),
				'refusal'        => $this->rules->refusal(offer: $offer, taken: $taken, mine: $mine, now: $now),
			];
		}

		return new JSONResponse(
			data: [
				'offers'  => $offers,
				'choices' => $mine,
				'line'    => $this->rules->line(offers: $this->store->offersFor(learnerUserId: $user->getUID()), mine: $mine, now: $now),
			]
		);
	}//end mine()

	/**
	 * Choose a keuzedeel as the signed-in student.
	 *
	 * @param string $offerId The offer.
	 *
	 * @return JSONResponse 201 with the choice, 404 for an offer not open to her, 422 with the reason.
	 *
	 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T5
	 */
	#[NoAdminRequired]
	public function choose(string $offerId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$learnerId = $user->getUID();
		$offer     = null;
		foreach ($this->store->offersFor(learnerUserId: $learnerId) as $candidate) {
			if ((string) ($candidate['id'] ?? ($candidate['uuid'] ?? '')) === $offerId) {
				$offer = $candidate;
			}
		}

		if ($offer === null) {
			return new JSONResponse(data: ['error' => 'Dit keuzedeel staat niet voor je open.'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$now     = self::now();
		$refusal = $this->rules->refusal(
			offer: $offer,
			taken: $this->store->choices(filters: ['offerId' => $offerId]),
			mine: $this->store->choices(filters: ['learnerId' => $learnerId]),
			now: $now
		);
		if ($refusal !== null) {
			return new JSONResponse(data: ['error' => $refusal], statusCode: Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		try {
			$saved = $this->objectService->saveObject(
				object: [
					'learnerId' => $learnerId,
					'offerId'   => $offerId,
					'period'    => (string) ($offer['period'] ?? ''),
					'offerName' => trim((string) ($offer['code'] ?? '') . ' ' . (string) ($offer['name'] ?? '')),
					'chosenAt'  => $now->format(DateTimeInterface::ATOM),
					'tenant_id' => (string) ($offer['tenant_id'] ?? ''),
				],
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: KeuzedeelStore::REGISTER, schema: 'keuzedeel-choice')),
				_rbac: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning('[KeuzedeelController] A keuzedeel choice could not be saved: {msg}', ['msg' => $exception->getMessage()]);
			return new JSONResponse(data: ['error' => 'Je keuze kon niet worden opgeslagen.'], statusCode: Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$this->restamp->restamp(learnerUserId: $learnerId);

		return new JSONResponse(data: (array) $saved->jsonSerialize(), statusCode: Http::STATUS_CREATED);
	}//end choose()

	/**
	 * Whether the student holds a choice of this offer.
	 *
	 * @param list<array<string, mixed>> $mine    Her choices.
	 * @param string                     $offerId The offer.
	 *
	 * @return bool
	 */
	private static function hasChosen(array $mine, string $offerId): bool {
		foreach ($mine as $choice) {
			if ((string) ($choice['offerId'] ?? '') === $offerId
				&& in_array($choice['lifecycle'] ?? 'chosen', KeuzedeelRules::OCCUPYING, true) === true
			) {
				return true;
			}
		}

		return false;
	}//end hasChosen()

	/**
	 * The moment, in UTC.
	 *
	 * @return DateTimeImmutable
	 */
	private static function now(): DateTimeImmutable {
		return new DateTimeImmutable('now', new DateTimeZone('UTC'));
	}//end now()
}//end class
