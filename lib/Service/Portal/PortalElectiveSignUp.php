<?php

/**
 * Learniq PortalElectiveSignUp
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A pupil signs herself up from her portal for the next lesson of an open
 * optional-lesson offer of her school ("Inschrijven voor dinsdag 6 oktober").
 *
 * The receiver resolved the pupil from portaliq's signed forward; this
 * service only ever writes a sign-up in her own name. It finds the offer,
 * keeps an offer she may not see out of reach as "not found", takes the
 * first lesson that has not started and whose window is open, and saves the
 * sign-up as the pupil, so ElectiveSignUpRules applies every rule it applies
 * to any writer: the window, the capacity and one sign-up per lesson. A
 * refusal of those rules comes back as a reason in words.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-reads-her-schools-open-support-lessons-and-signs-herself-up
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use DateTimeImmutable;
use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\ElectiveServiceInterface;
use OCA\Learniq\Kernel\Contracts\PortalElectiveSignUpInterface;
use OCA\Learniq\Listener\ElectiveSignUpRules;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IUser;
use OCP\L10N\IFactory;
use Throwable;

/**
 * Signs a portal pupil up for an offer's next lesson.
 *
 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-reads-her-schools-open-support-lessons-and-signs-herself-up
 */
class PortalElectiveSignUp implements PortalElectiveSignUpInterface {

	/**
	 * English source text per reason; translated through l10n.
	 */
	private const TEXTS = [
		'not-found' => 'This support lesson could not be found.',
		'full' => 'This support lesson is full.',
		'closed' => 'Sign-up for this support lesson is closed.',
		'double' => 'You are already signed up for this lesson.',
		'unchecked' => 'Your sign-up could not be checked. Try again later.',
		'no-account' => 'Your school account is not ready for this yet. Ask your school.',
	];

	/**
	 * The rules' refusal per reason.
	 */
	private const VETOES = [
		ElectiveSignUpRules::FULL => 'full',
		ElectiveSignUpRules::CLOSED => 'closed',
		ElectiveSignUpRules::OFFER_NOT_OPEN => 'closed',
		ElectiveSignUpRules::DOUBLE => 'double',
		ElectiveSignUpRules::NOT_ELIGIBLE => 'not-found',
		ElectiveSignUpRules::NOT_IN_OFFER => 'not-found',
	];

	/**
	 * Constructor.
	 *
	 * @param ElectiveServiceInterface $electives   Offers, lessons and windows.
	 * @param ObjectService            $objects     Saves the sign-up as the pupil.
	 * @param IFactory                 $l10nFactory Translations.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ElectiveServiceInterface $electives,
		private readonly ObjectService $objects,
		private readonly IFactory $l10nFactory,
	) {
	}//end __construct()

	/**
	 * Sign the pupil up for the next lesson of an offer.
	 *
	 * @param PortalLearner          $learner The pupil.
	 * @param string                 $offerId The offer.
	 * @param DateTimeImmutable|null $now     Now; the current time when null.
	 *
	 * @return PortalOutcome 200 `{signUpId, offerId, startsAt}`, or 404 / 422 with a reason.
	 *
	 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-reads-her-schools-open-support-lessons-and-signs-herself-up
	 */
	public function signUp(PortalLearner $learner, string $offerId, ?DateTimeImmutable $now=null): PortalOutcome {
		$now = ($now ?? new DateTimeImmutable());
		$offer = $this->visibleOffer(learner: $learner, offerId: $offerId);
		if ($offer === null) {
			return $this->refusal(status: Http::STATUS_NOT_FOUND, reason: 'not-found');
		}

		$lesson = $this->nextOpenLesson(offer: $offer, now: $now);
		if ($lesson === null) {
			return $this->refusal(status: Http::STATUS_UNPROCESSABLE_ENTITY, reason: 'closed');
		}

		$signUp = [
			'offerId' => $offerId,
			'learnerId' => $learner->ncUserId,
			'learnerRef' => $learner->profileRef,
			'offerName' => (string)($offer['name'] ?? ''),
			'lessonStartsAt' => (string)$lesson['startsAt'],
			'status' => 'signed-up',
			'tenant_id' => (string)($offer['tenant_id'] ?? $learner->tenantId),
		];
		if ($lesson['sessionId'] !== null) {
			$signUp['sessionId'] = $lesson['sessionId'];
		} else {
			$signUp['timetableSessionRef'] = $lesson['timetableSessionRef'];
		}

		try {
			$saved = $this->objects->runAs(
				user: $learner->user,
				operation: fn () => $this->objects->saveObject(
					object: $signUp,
					register: ElectiveServiceInterface::REGISTER,
					schema: ElectiveServiceInterface::SIGN_UP_SCHEMA,
					_rbac: false
				)
			);
		} catch (Throwable $exception) {
			$veto = $this->vetoReason(exception: $exception);
			if ($veto === null) {
				throw $exception;
			}

			return $this->refusal(status: Http::STATUS_UNPROCESSABLE_ENTITY, reason: $veto);
		}

		return new PortalOutcome(
			status: Http::STATUS_OK,
			body: ['signUpId' => $this->uuid(saved: $saved), 'offerId' => $offerId, 'startsAt' => $lesson['startsAt']]
		);
	}//end signUp()

	/**
	 * The pupil-facing message for a reason, in her language.
	 *
	 * @param string     $reason The reason.
	 * @param IUser|null $user   The pupil's account, if any.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-reads-her-schools-open-support-lessons-and-signs-herself-up
	 */
	public function message(string $reason, ?IUser $user): string {
		$l10n = $this->l10nFactory->get(AppIdentity::APP_ID, $this->l10nFactory->getUserLanguage($user));

		return $l10n->t(self::TEXTS[$reason] ?? self::TEXTS['not-found']);
	}//end message()

	/**
	 * The offer when it is open and the pupil is one of its learners, else null.
	 *
	 * @param PortalLearner $learner The pupil.
	 * @param string        $offerId The offer.
	 *
	 * @return array<string, mixed>|null
	 */
	private function visibleOffer(PortalLearner $learner, string $offerId): ?array {
		if ($offerId === '') {
			return null;
		}

		$offer = $this->electives->offer(offerId: $offerId);
		if ($offer === null || ($offer['lifecycle'] ?? '') !== 'open') {
			return null;
		}

		$eligible = $this->electives->eligibleLearners(offer: $offer);
		if ($eligible !== null && in_array($learner->ncUserId, $eligible, true) === false) {
			return null;
		}

		return $offer;
	}//end visibleOffer()

	/**
	 * The first lesson that has not started and whose window is open now.
	 *
	 * @param array<string, mixed> $offer The offer.
	 * @param DateTimeImmutable    $now   Now.
	 *
	 * @return array<string, mixed>|null
	 */
	private function nextOpenLesson(array $offer, DateTimeImmutable $now): ?array {
		foreach ($this->electives->lessons(offer: $offer) as $lesson) {
			try {
				$start = new DateTimeImmutable((string)$lesson['startsAt']);
			} catch (Throwable $exception) {
				continue;
			}

			if ($start <= $now) {
				continue;
			}

			$window = $this->electives->window(offer: $offer, startsAt: (string)$lesson['startsAt']);
			if ($this->electives->isOpen(window: $window, now: $now) === true) {
				return $lesson;
			}
		}

		return null;
	}//end nextOpenLesson()

	/**
	 * The reason a rules veto stands for, or null when the exception is no veto.
	 *
	 * @param Throwable $exception What the save threw.
	 *
	 * @return string|null
	 */
	private function vetoReason(Throwable $exception): ?string {
		if (method_exists($exception, 'getErrors') === false) {
			return null;
		}

		$errors = $exception->getErrors();
		if (is_array($errors) === false || is_string($errors['message'] ?? null) === false) {
			return null;
		}

		return (self::VETOES[$errors['message']] ?? 'unchecked');
	}//end vetoReason()

	/**
	 * A refusal outcome.
	 *
	 * @param int    $status The HTTP status.
	 * @param string $reason The reason.
	 *
	 * @return PortalOutcome
	 */
	private function refusal(int $status, string $reason): PortalOutcome {
		return new PortalOutcome(status: $status, body: ['error' => $reason], reason: $reason);
	}//end refusal()

	/**
	 * The uuid of the saved sign-up, '' when the save returned no entity.
	 *
	 * @param mixed $saved What saveObject() returned.
	 *
	 * @return string
	 */
	private function uuid(mixed $saved): string {
		if (is_object($saved) === true && method_exists($saved, 'getUuid') === true) {
			return (string)$saved->getUuid();
		}

		return '';
	}//end uuid()
}//end class
