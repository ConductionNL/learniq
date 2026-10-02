<?php

/**
 * An upload row is matched on the learner's personal number (live pass D6).
 *
 * `LearnerProfile.personalNumber` was flagged `x-openregister-encrypted`. On
 * the instance OpenRegister gave it no column: a PATCH echoed the number and
 * the next GET read null; OpenRegister's newer MagicMapper stores it but
 * rejects a filter on it. ExternalTrainingLearnerMatch filters on it, so the
 * personal-number path of the external-training upload could never match
 * (livepass/learniq/compliance-external-training-spreadsheet-upload,
 * profile-patch.json). Ruben decided (DECISIONS row 54) that the number is a
 * normal, stored, filterable field. This test stores it through a store that
 * treats an encrypted property the way OpenRegister does, after validating the
 * payload with Opis against the shipped learner-profile schema.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
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
 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\ExternalTrainingLearnerMatch;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\Learniq\Tests\Support\RegisterSchemaPayloads;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Store, read back and match a personal number.
 */
class PersonalNumberMatchTest extends TestCase {
	use RegisterSchemaPayloads;

	private const TENANT = '11111111-1111-4111-8111-111111111111';

	/**
	 * A store that handles an `x-openregister-encrypted` property the way
	 * OpenRegister does: no stored value (the instance) and no filter on it
	 * (MagicSearchHandler).
	 *
	 * @return RegisterFaithfulStore
	 */
	private static function store(): RegisterFaithfulStore {
		$encrypted = [];
		foreach ((self::shippedSchema('learner-profile')['properties'] ?? []) as $name => $property) {
			if (($property['x-openregister-encrypted'] ?? false) === true) {
				$encrypted[] = $name;
			}
		}

		return new class ($encrypted) extends RegisterFaithfulStore {

			/**
			 * Constructor.
			 *
			 * @param array<int,string> $encrypted Encrypted learner-profile properties.
			 */
			public function __construct(
				private readonly array $encrypted,
			) {
			}//end __construct()

			/**
			 * Refuse a filter on an encrypted property.
			 *
			 * @param array<string,mixed> $config The config.
			 * @param bool $rbac The flag.
			 * @param bool $multitenancy The flag.
			 *
			 * @return array<int,ObjectEntity>
			 */
			public function findAll(array $config, bool $rbac = true, bool $multitenancy = true): array {
				foreach (array_keys($config['filters'] ?? []) as $key) {
					if (in_array($key, $this->encrypted, true) === true) {
						throw new RuntimeException('Filtering on encrypted property ' . $key . ' is not supported.');
					}
				}

				return parent::findAll($config, $rbac, $multitenancy);
			}//end findAll()

			/**
			 * Keep no value for an encrypted property.
			 *
			 * @param string $schema The schema.
			 * @param array<string,mixed> $object The object.
			 * @param string|null $uuid The uuid.
			 *
			 * @return ObjectEntity
			 */
			public function save(string $schema, array $object, ?string $uuid): ObjectEntity {
				foreach ($this->encrypted as $name) {
					unset($object[$name]);
				}

				return parent::save($schema, $object, $uuid);
			}//end save()
		};
	}//end store()

	/**
	 * PATCH the number, GET it back, match an upload row on it.
	 *
	 * @return void
	 */
	public function testAnUploadRowMatchesOnTheStoredPersonalNumber(): void {
		$store = self::store();
		$profile = ['ncUserId' => 'lp-learner', 'tenant_id' => self::TENANT, 'personalNumber' => 'LP-0001', 'personalNumberType' => 'onderwijsnummer'];
		self::assertNull(self::schemaError('learner-profile', $profile));
		$store->rows['learner-profile'] = [['id' => '10744162-0000-4000-8000-000000000001', 'ncUserId' => 'lp-learner', 'tenant_id' => self::TENANT]];

		// PATCH: OpenRegister merges the change into the stored object and saves it.
		$store->save('learner-profile', array_merge($store->rows['learner-profile'][0], ['personalNumber' => 'LP-0001']), '10744162-0000-4000-8000-000000000001');

		// GET reads it back.
		self::assertSame('LP-0001', $store->rows['learner-profile'][0]['personalNumber'] ?? null, 'The number was not stored.');

		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $store->findAll($config, $_rbac, $_multitenancy)
		);
		$match = new ExternalTrainingLearnerMatch($objects, $this->createMock(IUserManager::class));

		$result = $match->match('LP-0001', self::TENANT);
		self::assertSame('10744162-0000-4000-8000-000000000001', $result['id'] ?? null, (string)($result['reason'] ?? ''));
		self::assertSame('lp-learner', $result['userId'] ?? null);

		self::assertNull($match->match('LP-0001', '22222222-2222-4222-8222-222222222222')['id'] ?? null, 'Another tenant never matches.');
	}//end testAnUploadRowMatchesOnTheStoredPersonalNumber()
}//end class
