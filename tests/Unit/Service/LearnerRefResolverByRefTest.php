<?php

/**
 * Learniq LearnerRefResolver unit tests: the portal half.
 *
 * The fake ObjectService answers the way OpenRegister does: `findAll()` reads
 * `register` and `schema` only from `filters`, and a filter key the
 * LearnerProfile schema does not declare matches no row. A call site that
 * passes them anywhere else reads nothing, so it cannot pass by accident.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Portal
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
 * @spec openspec/specs/assignments/spec.md#requirement-the-server-stamps-who-a-submission-belongs-to
 * @spec openspec/specs/grading/spec.md#requirement-one-resolver-finds-a-learners-profile
 * @spec openspec/specs/portal-contribution/spec.md#REQ-PCON-000
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for LearnerRefResolver's portal half: a profile by uuid, and a profile
 * uuid by user across tenants. These lived in Portal\LearnerProfileLookup,
 * which duplicated LearnerRefResolver::resolve() (#1068, #1096) and is gone.
 */
class LearnerRefResolverByRefTest extends TestCase {

	/**
	 * Properties the learner-profile schema declares that a lookup may filter on.
	 */
	private const DECLARED_FILTERS = ['register', 'schema', 'ncUserId', 'lifecycle', 'mergedInto', 'tenant_id'];

	/**
	 * LearnerProfile rows keyed by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $profiles = [];

	/**
	 * The configs findAll() was called with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $findAllConfigs = [];

	/**
	 * The `_multitenancy` flag of each findAll() call.
	 *
	 * @var array<int, bool>
	 */
	private array $multitenancy = [];

	/**
	 * Build the resolver over the fake store.
	 *
	 * @param bool $throws Whether every read throws.
	 *
	 * @return LearnerRefResolver
	 */
	private function makeLookup(bool $throws = false): LearnerRefResolver {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) use ($throws) {
				if ($throws === true) {
					throw new RuntimeException('database gone');
				}

				if ($register !== 'learniq' || $schema !== 'learner-profile' || isset($this->profiles[$id]) === false) {
					throw new DoesNotExistException('not found');
				}

				return OrEntityFactory::make(array_merge($this->profiles[$id], ['id' => $id]), 'learner-profile');
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use ($throws): array {
				$this->findAllConfigs[] = $config;
				$this->multitenancy[] = $_multitenancy;
				if ($throws === true) {
					throw new RuntimeException('database gone');
				}

				$filters = ($config['filters'] ?? []);
				if (($filters['register'] ?? null) !== 'learniq' || ($filters['schema'] ?? null) !== 'learner-profile') {
					return [];
				}

				foreach (array_keys($filters) as $key) {
					if (in_array($key, self::DECLARED_FILTERS, true) === false) {
						return [];
					}
				}

				$rows = [];
				foreach ($this->profiles as $uuid => $row) {
					if (($row['ncUserId'] ?? null) === ($filters['ncUserId'] ?? '')) {
						$rows[] = OrEntityFactory::make(array_merge($row, ['id' => $uuid]), 'learner-profile');
					}
				}

				return $rows;
			}
		);

		return new LearnerRefResolver(objectService: $objectService);
	}//end makeLookup()

	/**
	 * An active profile is returned by uuid, with its id.
	 *
	 * @return void
	 */
	public function testAnActiveProfileIsFoundByRef(): void {
		$this->profiles['lp-1'] = ['ncUserId' => 'pupil-1', 'tenant_id' => 't-1', 'lifecycle' => 'active'];

		$row = $this->makeLookup()->byRef(learnerRef: 'lp-1');

		self::assertNotNull($row);
		self::assertSame('lp-1', $row['id']);
		self::assertSame('pupil-1', $row['ncUserId']);
		self::assertSame('t-1', $row['tenant_id']);
	}//end testAnActiveProfileIsFoundByRef()

	/**
	 * A merged-away, deleted or unknown profile is not a learner to act for.
	 *
	 * @return void
	 */
	public function testMergedDeletedAndUnknownProfilesAreNull(): void {
		$this->profiles['lp-merged'] = ['ncUserId' => 'pupil-2', 'lifecycle' => 'merged', 'mergedInto' => 'lp-3'];
		$this->profiles['lp-moved'] = ['ncUserId' => 'pupil-2', 'lifecycle' => 'active', 'mergedInto' => 'lp-3'];
		$this->profiles['lp-deleted'] = ['ncUserId' => 'pupil-4', 'lifecycle' => 'deleted'];
		$this->profiles['lp-nouser'] = ['ncUserId' => '', 'lifecycle' => 'active'];
		$lookup = $this->makeLookup();

		self::assertNull($lookup->byRef(learnerRef: 'lp-merged'));
		self::assertNull($lookup->byRef(learnerRef: 'lp-moved'));
		self::assertNull($lookup->byRef(learnerRef: 'lp-deleted'));
		self::assertNull($lookup->byRef(learnerRef: 'lp-nouser'));
		self::assertNull($lookup->byRef(learnerRef: 'lp-missing'));
		self::assertNull($lookup->byRef(learnerRef: ''));
	}//end testMergedDeletedAndUnknownProfilesAreNull()

	/**
	 * A read error is not "no profile": it propagates.
	 *
	 * @return void
	 */
	public function testAReadErrorPropagates(): void {
		$this->expectException(RuntimeException::class);

		$this->makeLookup(throws: true)->byRef(learnerRef: 'lp-1');
	}//end testAReadErrorPropagates()

	/**
	 * The surviving profile wins over a merged-away one for the same user.
	 *
	 * @return void
	 */
	public function testTheMergeSurvivorWinsForAUser(): void {
		$this->profiles['lp-old'] = ['ncUserId' => 'pupil-1', 'mergedInto' => 'lp-new', 'lifecycle' => 'merged'];
		$this->profiles['lp-new'] = ['ncUserId' => 'pupil-1', 'mergedInto' => null, 'lifecycle' => 'active'];

		self::assertSame('lp-new', $this->makeLookup()->resolveAcrossTenants(learnerId: 'pupil-1'));
	}//end testTheMergeSurvivorWinsForAUser()

	/**
	 * A user without a profile, or no user at all, has no ref.
	 *
	 * @return void
	 */
	public function testAUserWithoutAProfileHasNoRef(): void {
		$lookup = $this->makeLookup();

		self::assertNull($lookup->resolveAcrossTenants(learnerId: 'pupil-9'));
		self::assertNull($lookup->resolveAcrossTenants(learnerId: ''));
	}//end testAUserWithoutAProfileHasNoRef()

	/**
	 * The user lookup nests register and schema under filters and filters on
	 * ncUserId, the only shape OpenRegister answers.
	 *
	 * @return void
	 */
	public function testTheUserLookupUsesTheShapeOpenRegisterReads(): void {
		$this->profiles['lp-1'] = ['ncUserId' => 'pupil-1', 'lifecycle' => 'active'];

		self::assertSame('lp-1', $this->makeLookup()->resolveAcrossTenants(learnerId: 'pupil-1'));
		self::assertSame('learniq', $this->findAllConfigs[0]['filters']['register']);
		self::assertSame('learner-profile', $this->findAllConfigs[0]['filters']['schema']);
		self::assertSame('pupil-1', $this->findAllConfigs[0]['filters']['ncUserId']);
		self::assertArrayNotHasKey('register', $this->findAllConfigs[0]);
	}//end testTheUserLookupUsesTheShapeOpenRegisterReads()

	/**
	 * A portal caller has no session, so its lookup reads across tenants; a
	 * signed-in caller keeps OpenRegister's tenant scoping, as before the fold.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/grading/spec.md#requirement-one-resolver-finds-a-learners-profile
	 */
	public function testOnlyTheAcrossTenantsLookupDropsTenantScoping(): void {
		$this->profiles['lp-1'] = ['ncUserId' => 'pupil-1', 'lifecycle' => 'active'];
		$resolver = $this->makeLookup();

		$resolver->resolve(learnerId: 'pupil-1');
		$resolver->resolveAcrossTenants(learnerId: 'pupil-1');

		self::assertSame([true, false], $this->multitenancy);
	}//end testOnlyTheAcrossTenantsLookupDropsTenantScoping()
}//end class
