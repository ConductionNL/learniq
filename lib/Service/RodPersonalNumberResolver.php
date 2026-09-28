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
 * @spec openspec/changes/rod-bsn-and-school-advice/specs/data-exchange/spec.md#requirement-the-rod-learner-record-carries-the-personal-number-where-duo-expects-a-bsn
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
 * @spec openspec/changes/rod-bsn-and-school-advice/specs/data-exchange/spec.md#requirement-the-personal-number-leaves-learniq-only-in-a-rod-message-and-is-never-logged
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
	 * @spec openspec/changes/rod-bsn-and-school-advice/specs/data-exchange/spec.md#requirement-the-rod-learner-record-carries-the-personal-number-where-duo-expects-a-bsn
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
	 * @spec openspec/changes/rod-bsn-and-school-advice/specs/data-exchange/spec.md#requirement-a-school-advice-goes-to-rod-with-duos-aanleverenadviesvo-field-set
	 */
	public function forLearner(string $ncUserId, string $tenantId): array {
		if ($ncUserId === '') {
			return self::none();
		}

		$filters = ['register' => self::LEARNIQ_REGISTER, 'schema' => self::LEARNER_PROFILE_SCHEMA, 'ncUserId' => $ncUserId];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		try {
			$results = $this->objectService->findAll(
				config: ['filters' => $filters, 'limit' => 1],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->info(
				'[RodPersonalNumberResolver] the learner profile of a school advice could not be read ({class}).',
				['class' => $exception::class]
			);
			return self::none();
		}

		if (empty($results) === true) {
			return self::none();
		}

		$profile = $results[0];
		if (is_array($profile) === false) {
			$profile = (array)$profile->jsonSerialize();
		}

		return $this->pairOf(profile: $profile, tenantId: $tenantId);
	}//end forLearner()

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
	 * @spec openspec/changes/rod-bsn-and-school-advice/specs/data-exchange/spec.md#requirement-the-rod-learner-record-carries-the-personal-number-where-duo-expects-a-bsn
	 */
	public static function isValid(string $number, string $type): bool {
		if (preg_match('/^[0-9]{9}$/', $number) !== 1 || isset(self::DUO_TYPES[$type]) === false) {
			return false;
		}

		$sum = 0;
		for ($position = 0; $position < 8; $position++) {
			$sum += ((9 - $position) * (int)$number[$position]);
		}

		$sum -= (int)$number[8];
		$remainder = ((($sum % 11) + 11) % 11);

		if ($type === 'bsn') {
			return $remainder === 0 && $number !== '000000000';
		}

		return $remainder === 5;
	}//end isValid()

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
