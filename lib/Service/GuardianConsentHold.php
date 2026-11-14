<?php

/**
 * Learniq GuardianConsentHold — whether a minor's own choice still waits for
 * a linked guardian's consent.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/school-structure/spec.md#requirement-a-minor-s-own-subject-choice-waits-for-a-guardian-s-consent
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Support\ObjectRow;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Reads the learner's profile for the age and the linked guardians.
 *
 * A learner is a minor when the profile's `birthDate` (else its `ageYears`)
 * says they are under 18 today. A minor's object waits until
 * `guardianConsentGiven` is true and `guardianConsentBy` is one of the
 * profile's `parentIds`. A learner whose age is not known is not held: the
 * submit guard already refuses a learner without a profile, and holding on a
 * missing date would block every adult whose date was never entered.
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/school-structure/spec.md#requirement-a-minor-s-own-subject-choice-waits-for-a-guardian-s-consent
 */
class GuardianConsentHold {

	/**
	 * The age from which a learner consents for themselves.
	 *
	 * @var int
	 */
	public const ADULT_AGE = 18;

	private const REGISTER = 'learniq';
	private const PROFILE_SCHEMA = 'learner-profile';

	/**
	 * Turns OpenRegister results into arrays.
	 *
	 * @var ObjectRow
	 */
	private readonly ObjectRow $objectRow;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param ITimeFactory  $time          Clock for today.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ITimeFactory $time,
	) {
		$this->objectRow = new ObjectRow();
	}//end __construct()

	/**
	 * Whether the object's learner is under 18 today.
	 *
	 * @param array<string, mixed> $object An object with `learnerId` and `tenant_id`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/school-structure/spec.md#scenario-a-15-year-old-submits-their-own-choice
	 */
	public function isMinor(array $object): bool {
		$profile = $this->profileOf(object: $object);

		return $profile !== null && self::isMinorProfile(profile: $profile, today: $this->today());
	}//end isMinor()

	/**
	 * Whether the object still waits for a guardian's consent: its learner is
	 * a minor and no linked guardian has consented.
	 *
	 * @param array<string, mixed> $object An object with `learnerId`, `tenant_id`, `guardianConsentGiven` and `guardianConsentBy`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/school-structure/spec.md#scenario-the-guardian-consents
	 */
	public function holds(array $object): bool {
		$profile = $this->profileOf(object: $object);
		if ($profile === null || self::isMinorProfile(profile: $profile, today: $this->today()) === false) {
			return false;
		}

		return self::guardianConsented(object: $object, profile: $profile) === false;
	}//end holds()

	/**
	 * Whether a linked guardian of the profile gave the object's consent.
	 *
	 * @param array<string, mixed> $object  The object.
	 * @param array<string, mixed> $profile The learner's profile.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/school-structure/spec.md#scenario-the-guardian-consents
	 */
	public static function guardianConsented(array $object, array $profile): bool {
		$by = ($object['guardianConsentBy'] ?? null);
		$parentIds = ($profile['parentIds'] ?? []);

		return ($object['guardianConsentGiven'] ?? false) === true
			&& is_string($by) === true
			&& is_array($parentIds) === true
			&& in_array($by, $parentIds, true) === true;
	}//end guardianConsented()

	/**
	 * Whether a profile's learner is under 18 on a day.
	 *
	 * @param array<string, mixed> $profile The learner's profile.
	 * @param DateTimeImmutable    $today   The day.
	 *
	 * @return bool False when the age cannot be read.
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/school-structure/spec.md#scenario-a-15-year-old-submits-their-own-choice
	 */
	public static function isMinorProfile(array $profile, DateTimeImmutable $today): bool {
		$age = self::ageOf(profile: $profile, today: $today);

		return $age !== null && $age < self::ADULT_AGE;
	}//end isMinorProfile()

	/**
	 * The learner's age in whole years: from `birthDate`, else `ageYears`.
	 *
	 * @param array<string, mixed> $profile The learner's profile.
	 * @param DateTimeImmutable    $today   The day.
	 *
	 * @return int|null
	 */
	private static function ageOf(array $profile, DateTimeImmutable $today): ?int {
		$birthDate = ($profile['birthDate'] ?? null);
		$born = false;
		if (is_string($birthDate) === true) {
			$born = DateTimeImmutable::createFromFormat('!Y-m-d', substr($birthDate, 0, 10), $today->getTimezone());
		}

		if ($born !== false) {
			$diff = $born->diff($today);
			// Born after today reads as no age yet, never as a negative one.
			if ($diff->invert === 1) {
				return 0;
			}

			return $diff->y;
		}

		$ageYears = ($profile['ageYears'] ?? null);
		if (is_int($ageYears) === false) {
			return null;
		}

		return $ageYears;
	}//end ageOf()

	/**
	 * The object's learner profile, read in its tenant.
	 *
	 * @param array<string, mixed> $object The object.
	 *
	 * @return array<string, mixed>|null
	 */
	private function profileOf(array $object): ?array {
		$learnerId = ($object['learnerId'] ?? null);
		if (is_string($learnerId) === false || $learnerId === '') {
			return null;
		}

		$filters = ['register' => self::REGISTER, 'schema' => self::PROFILE_SCHEMA, 'ncUserId' => $learnerId];
		$tenantId = ($object['tenant_id'] ?? null);
		if (is_string($tenantId) === true && $tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		// A server-side check: read past the caller's own rights, inside the object's tenant.
		$rows = $this->objectService->findAll(config: ['filters' => $filters, 'limit' => 5], _rbac: false, _multitenancy: false);
		foreach ($rows as $row) {
			$profile = $this->objectRow->asArray(object: $row);
			if (($profile['ncUserId'] ?? null) === $learnerId) {
				return $profile;
			}
		}

		return null;
	}//end profileOf()

	/**
	 * Today in the server's time zone.
	 *
	 * @return DateTimeImmutable
	 */
	private function today(): DateTimeImmutable {
		return (new DateTimeImmutable('@' . $this->time->getTime()))
			->setTimezone(new DateTimeZone(date_default_timezone_get()))
			->setTime(0, 0);
	}//end today()
}//end class
