<?php

/**
 * Learniq ROD Personal Number Resolver
 *
 * Reads a learner's persoonsgebonden nummer (BSN or onderwijsnummer) for a
 * ROD record, and for nothing else.
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
 * @spec openspec/specs/data-exchange/spec.md#requirement-the-rod-learner-record-carries-the-personal-number-where-duo-expects-a-bsn
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The one reader of LearnerProfile.personalNumber (design D2).
 *
 * The gate runs from integriq's event without a user, so an authorised read
 * would strip the property; this reads as trusted internal code
 * (`_rbac: false`, which OpenRegister still decrypts) with the tenant checked
 * here. Only the payload builder calls it, and only for the two ROD mappings.
 *
 * 🔴 NOTHING ON THIS PATH MAY LOG A VALUE. A failure logs the profile id and
 * the exception class, never its message: an OpenRegister message can quote
 * object data.
 *
 * @spec openspec/specs/data-exchange/spec.md#requirement-the-personal-number-leaves-learniq-only-in-a-rod-message-and-is-never-logged
 */
class RodPersonalNumberResolver {

	public const NUMBER_KEY = 'persoonsgebondenNummer';
	public const TYPE_KEY = 'persoonsgebondenNummerType';

	private const LEARNIQ_REGISTER = 'learniq';
	private const LEARNER_PROFILE_SCHEMA = 'learner-profile';

	/**
	 * Learniq's kind to DUO's choice element.
	 *
	 * @var array<string, string>
	 */
	private const DUO_TYPES = [
		'bsn' => 'burgerservicenummer',
		'onderwijsnummer' => 'onderwijsnummer',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OR object access.
	 * @param LoggerInterface $logger        Logger; never handed a value.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The ROD pair for a LearnerProfile by its uuid.
	 *
	 * @param string $profileId The LearnerProfile uuid.
	 * @param string $tenantId  The tenant the job runs for; empty skips the check.
	 *
	 * @return array{persoonsgebondenNummer: string|null, persoonsgebondenNummerType: string|null} The pair, nulls when absent or invalid.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-the-rod-learner-record-carries-the-personal-number-where-duo-expects-a-bsn
	 */
	public function forProfile(string $profileId, string $tenantId): array {
		if ($profileId === '') {
			return self::none();
		}

		try {
			$profile = $this->objectService->find(
				id: $profileId,
				register: self::LEARNIQ_REGISTER,
				schema: self::LEARNER_PROFILE_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->info(
				'[RodPersonalNumberResolver] learner profile {id} could not be read ({class}).',
				['id' => $profileId, 'class' => $exception::class]
			);
			return self::none();
		}

		if ($profile === null) {
			return self::none();
		}

		return $this->pairOf(profile: (array)$profile->jsonSerialize(), tenantId: $tenantId);
	}//end forProfile()

	/**
	 * The ROD pair for a learner by Nextcloud user id.
	 *
	 * @param string $ncUserId The learner's Nextcloud user id.
	 * @param string $tenantId The tenant the job runs for; empty skips the filter.
	 *
	 * @return array{persoonsgebondenNummer: string|null, persoonsgebondenNummerType: string|null} The pair, nulls when absent or invalid.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-a-school-advice-goes-to-rod-with-duos-aanleverenadviesvo-field-set
	 */
	public function forLearner(string $ncUserId, string $tenantId): array {
		if ($ncUserId === '') {
			return self::none();
		}

		$results = $this->readProfiles(ncUserId: $ncUserId, tenantId: $tenantId);
		if (empty($results) === true) {
			return self::none();
		}

		return $this->pairOf(profile: $this->rowOf(result: $results[0]), tenantId: $tenantId);
	}//end forLearner()

	/**
	 * The learner's profile rows (at most one), or null when the read failed.
	 *
	 * @param string $ncUserId The learner's Nextcloud user id.
	 * @param string $tenantId The tenant the job runs for; empty skips the filter.
	 *
	 * @return array<int, mixed>|null The rows, or null; the failure is logged without a value.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-a-school-advice-goes-to-rod-with-duos-aanleverenadviesvo-field-set
	 */
	private function readProfiles(string $ncUserId, string $tenantId): ?array {
		try {
			return $this->objectService->findAll(
				config: ['filters' => $this->profileFilters(ncUserId: $ncUserId, tenantId: $tenantId), 'limit' => 1],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->info(
				'[RodPersonalNumberResolver] the learner profile of a school advice could not be read ({class}).',
				['class' => $exception::class]
			);
			return null;
		}
	}//end readProfiles()

	/**
	 * The filters that find one learner's profile, scoped to the tenant when there is one.
	 *
	 * @param string $ncUserId The learner's Nextcloud user id.
	 * @param string $tenantId The tenant the job runs for; empty skips the filter.
	 *
	 * @return array<string, string> The filters.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-a-school-advice-goes-to-rod-with-duos-aanleverenadviesvo-field-set
	 */
	private function profileFilters(string $ncUserId, string $tenantId): array {
		$filters = ['register' => self::LEARNIQ_REGISTER, 'schema' => self::LEARNER_PROFILE_SCHEMA, 'ncUserId' => $ncUserId];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		return $filters;
	}//end profileFilters()

	/**
	 * A found profile as a row, whether the store returned an array or an entity.
	 *
	 * @param mixed $result The first findAll result.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function rowOf($result): array {
		if (is_array($result) === false) {
			return (array)$result->jsonSerialize();
		}

		return $result;
	}//end rowOf()

	/**
	 * Whether a nine-digit number passes the elfproef (BSN) or the adapted one (onderwijsnummer).
	 *
	 * Both weigh the first eight digits 9 down to 2 and subtract the ninth; a
	 * BSN leaves remainder 0 modulo 11, an onderwijsnummer remainder 5.
	 *
	 * @param string $number The number.
	 * @param string $type   `bsn` or `onderwijsnummer`.
	 *
	 * @return bool True when valid.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-the-rod-learner-record-carries-the-personal-number-where-duo-expects-a-bsn
	 */
	public static function isValid(string $number, string $type): bool {
		if (preg_match('/^[0-9]{9}$/', $number) !== 1 || isset(self::DUO_TYPES[$type]) === false) {
			return false;
		}

		$remainder = self::elfproefRemainder(number: $number);
		if ($type === 'bsn') {
			return $remainder === 0 && $number !== '000000000';
		}

		return $remainder === 5;
	}//end isValid()

	/**
	 * The remainder modulo 11 of a nine-digit number's weighted sum: the first
	 * eight digits weighed 9 down to 2, the ninth subtracted, kept in 0..10.
	 *
	 * @param string $number The nine-digit number.
	 *
	 * @return integer The remainder.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-the-rod-learner-record-carries-the-personal-number-where-duo-expects-a-bsn
	 */
	private static function elfproefRemainder(string $number): int {
		$sum = 0;
		for ($position = 0; $position < 8; $position++) {
			$sum += ((9 - $position) * (int)$number[$position]);
		}

		$sum -= (int)$number[8];

		return ((($sum % 11) + 11) % 11);
	}//end elfproefRemainder()

	/**
	 * The pair from a profile row, checked.
	 *
	 * @param array<string, mixed> $profile  The profile row.
	 * @param string               $tenantId The job's tenant; empty skips the check.
	 *
	 * @return array{persoonsgebondenNummer: string|null, persoonsgebondenNummerType: string|null} The pair.
	 */
	private function pairOf(array $profile, string $tenantId): array {
		if ($tenantId !== '' && (string)($profile['tenant_id'] ?? '') !== $tenantId) {
			return self::none();
		}

		$number = $profile['personalNumber'] ?? null;
		$type = (string)($profile['personalNumberType'] ?? 'bsn');
		if (is_string($number) === false || self::isValid(number: $number, type: $type) === false) {
			return self::none();
		}

		return [self::NUMBER_KEY => $number, self::TYPE_KEY => self::DUO_TYPES[$type]];
	}//end pairOf()

	/**
	 * The empty pair, which the gate's completeness check refuses.
	 *
	 * @return array{persoonsgebondenNummer: null, persoonsgebondenNummerType: null} The pair.
	 */
	private static function none(): array {
		return [self::NUMBER_KEY => null, self::TYPE_KEY => null];
	}//end none()
}//end class
