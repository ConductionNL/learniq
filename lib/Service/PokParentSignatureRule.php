<?php

/**
 * Learniq POK Parent Signature Rule
 *
 * Decides whether a parent or guardian signs a praktijkovereenkomst (POK),
 * and which accounts may. A student under 18 cannot bind themselves to a
 * work placement agreement alone, so their parent co-signs.
 *
 * The age that counts is the student's age on the day of their own
 * signature: that consent is the one that needs a representative, so a
 * student who signs at 17 and turns 18 before activation still needs the
 * parent. Before the student has signed, today counts. No date of birth, or
 * no learner reachable through the placement, fails closed: the parent signs.
 *
 * A parent is anyone in the learner's `LearnerProfile.parentIds`, the same
 * rule LearningPlanSignatureGuard applies to learning plans (#180).
 *
 * Reads run without RBAC: the coordinator activating a POK may not read the
 * learner's profile, and only the birth date and parentIds leave this class.
 *
 * Consumed by PokActivationGuard (enforcement) and
 * PokParentSignatureStampAction (the `parentSignatureRequired` read surface).
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/changes/pok-signature-parent-role/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-every-required-signature
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use Exception;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Whether a parent signs a POK, why, and who may.
 *
 * @spec openspec/changes/pok-signature-parent-role/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-every-required-signature
 */
class PokParentSignatureRule {

	/**
	 * The student was under 18 on the day that counts.
	 */
	public const REASON_MINOR = 'minor';

	/**
	 * The student's age cannot be established.
	 */
	public const REASON_UNKNOWN_AGE = 'unknown-age';

	/**
	 * The student was 18 or older on the day that counts.
	 */
	public const REASON_ADULT = 'adult';

	private const LEARNIQ_REGISTER = 'learniq';
	private const PLACEMENT_SCHEMA = 'bpv-placement';
	private const PROFILE_SCHEMA = 'learner-profile';
	private const AGE_OF_MAJORITY = 18;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param LearnerRefResolver $learnerRefs Finds a learner's profile uuid on their user id.
	 * @param ITimeFactory $timeFactory Today, for a POK the student has not signed yet.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LearnerRefResolver $learnerRefs,
		private readonly ITimeFactory $timeFactory,
	) {
	}//end __construct()

	/**
	 * Whether a parent signs this POK.
	 *
	 * @param array<string, mixed> $pok The Praktijkovereenkomst.
	 * @param string|null $studentSignedAt The student's PokSignature.signedAt, or null when they have not signed.
	 *
	 * @return array{required: bool, reason: string, parentIds: array<int, string>}
	 *
	 * @spec openspec/changes/pok-signature-parent-role/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-every-required-signature
	 */
	public function evaluate(array $pok, ?string $studentSignedAt): array {
		$profile = $this->learnerProfile(placementId: (string)($pok['bpvPlacementId'] ?? ''));
		if ($profile === null) {
			return ['required' => true, 'reason' => self::REASON_UNKNOWN_AGE, 'parentIds' => []];
		}

		$parentIds = array_values(array_filter(array_map('strval', (array)($profile['parentIds'] ?? [])), static fn (string $id): bool => $id !== ''));
		$age = $this->ageOn(birthDate: $profile['birthDate'] ?? null, day: $this->signingDay(studentSignedAt: $studentSignedAt));

		if ($age === null) {
			return ['required' => true, 'reason' => self::REASON_UNKNOWN_AGE, 'parentIds' => $parentIds];
		}

		if ($age < self::AGE_OF_MAJORITY) {
			return ['required' => true, 'reason' => self::REASON_MINOR, 'parentIds' => $parentIds];
		}

		return ['required' => false, 'reason' => self::REASON_ADULT, 'parentIds' => $parentIds];
	}//end evaluate()

	/**
	 * The student's earliest signature time among a version's PokSignatures,
	 * or null. The earliest gives the youngest age, so a doubt falls on the
	 * side of the parent signing.
	 *
	 * @param array<int, array<string, mixed>> $signatures PokSignature rows.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/pok-signature-parent-role/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-every-required-signature
	 */
	public function studentSignedAt(array $signatures): ?string {
		$earliest = null;
		foreach ($signatures as $row) {
			$signedAt = $row['signedAt'] ?? null;
			if (($row['signerRole'] ?? null) !== 'student' || is_string($signedAt) === false || $signedAt === '') {
				continue;
			}

			if ($earliest === null || strtotime($signedAt) < strtotime($earliest)) {
				$earliest = $signedAt;
			}
		}

		return $earliest;
	}//end studentSignedAt()

	/**
	 * The learner's profile row, through the placement's learnerRef or else
	 * the learner's user id; null when either cannot be found.
	 *
	 * @param string $placementId The POK's bpvPlacementId.
	 *
	 * @return array<string, mixed>|null
	 */
	private function learnerProfile(string $placementId): ?array {
		$placement = $this->row(id: $placementId, schema: self::PLACEMENT_SCHEMA);
		if ($placement === null) {
			return null;
		}

		$learnerRef = (string)($placement['learnerRef'] ?? '');
		if ($learnerRef === '') {
			$learnerRef = (string)($this->learnerRefs->resolve(learnerId: (string)($placement['learnerId'] ?? '')) ?? '');
		}

		return $this->row(id: $learnerRef, schema: self::PROFILE_SCHEMA);
	}//end learnerProfile()

	/**
	 * One learniq object by id, read without RBAC, or null when it does not exist.
	 *
	 * @param string $id Object uuid.
	 * @param string $schema Schema slug.
	 *
	 * @return array<string, mixed>|null
	 */
	private function row(string $id, string $schema): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$object = $this->objectService->find(
				id: $id,
				register: self::LEARNIQ_REGISTER,
				schema: $schema,
				_rbac: false,
				_multitenancy: false
			);
		} catch (DoesNotExistException $exception) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $object->jsonSerialize();
	}//end row()

	/**
	 * The day whose age counts: the student's signing day, or today.
	 *
	 * @param string|null $studentSignedAt The student's signedAt, or null.
	 *
	 * @return DateTimeImmutable
	 */
	private function signingDay(?string $studentSignedAt): DateTimeImmutable {
		if ($studentSignedAt !== null && $studentSignedAt !== '') {
			try {
				return new DateTimeImmutable($studentSignedAt);
			} catch (Exception $exception) {
				// An unreadable timestamp falls back to today, never to "adult".
			}
		}

		return $this->timeFactory->now();
	}//end signingDay()

	/**
	 * Whole years between a date of birth and a day, or null when the date of
	 * birth is missing or unreadable.
	 *
	 * @param mixed $birthDate LearnerProfile.birthDate (Y-m-d).
	 * @param DateTimeImmutable $day The day that counts, in its own time zone.
	 *
	 * @return int|null
	 */
	private function ageOn(mixed $birthDate, DateTimeImmutable $day): ?int {
		if (is_string($birthDate) === false || preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate) !== 1) {
			return null;
		}

		try {
			$born = new DateTimeImmutable($birthDate, $day->getTimezone());
		} catch (Exception $exception) {
			return null;
		}

		$signedOn = new DateTimeImmutable($day->format('Y-m-d'), $day->getTimezone());

		return $born->diff($signedOn)->y;
	}//end ageOn()
}//end class
