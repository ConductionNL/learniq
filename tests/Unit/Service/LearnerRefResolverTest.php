<?php

/**
 * Learniq LearnerRefResolver unit tests.
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
 * @spec openspec/specs/grading/spec.md#requirement-every-gradeentry-carries-a-server-stamped-learnerref
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LearnerRefResolver::resolve(), over a store that answers like
 * OpenRegister (nested register/schema, undeclared filter keys match nothing).
 */
class LearnerRefResolverTest extends TestCase {

	/**
	 * The fake OpenRegister store.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * Build the resolver over the fake store.
	 *
	 * @return LearnerRefResolver
	 */
	private function makeResolver(): LearnerRefResolver {
		$this->store = new RegisterFaithfulStore();
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);

		return new LearnerRefResolver(objectService: $objectService);
	}//end makeResolver()

	/**
	 * A profile is found by its ncUserId.
	 *
	 * @return void
	 */
	public function testResolvesTheProfileOfTheUser(): void {
		$resolver = $this->makeResolver();
		$this->store->rows['learner-profile'] = [
			['id' => 'lp-1', 'ncUserId' => 'pupil-1'],
			['id' => 'lp-2', 'ncUserId' => 'pupil-2'],
		];

		self::assertSame('lp-1', $resolver->resolve(learnerId: 'pupil-1'));
		self::assertSame('lp-2', $resolver->resolve(learnerId: 'pupil-2'));
	}//end testResolvesTheProfileOfTheUser()

	/**
	 * The lookup filters on ncUserId, nests register/schema, and bypasses RBAC.
	 *
	 * @return void
	 */
	public function testTheLookupUsesTheShapeOpenRegisterReads(): void {
		$resolver = $this->makeResolver();
		$resolver->resolve(learnerId: 'pupil-1');

		self::assertCount(1, $this->store->reads);
		$read = $this->store->reads[0];
		self::assertSame('learniq', $read['config']['filters']['register']);
		self::assertSame('learner-profile', $read['config']['filters']['schema']);
		self::assertSame('pupil-1', $read['config']['filters']['ncUserId']);
		self::assertArrayNotHasKey('learnerId', $read['config']['filters']);
		self::assertFalse($read['rbac']);
	}//end testTheLookupUsesTheShapeOpenRegisterReads()

	/**
	 * The fake itself refuses a learnerId filter on LearnerProfile, which is
	 * what made the older copies of this lookup return nothing.
	 *
	 * @return void
	 */
	public function testTheStoreAnswersAnUndeclaredFilterWithNothing(): void {
		$this->makeResolver();
		$this->store->rows['learner-profile'] = [['id' => 'lp-1', 'ncUserId' => 'pupil-1']];

		$rows = $this->store->findAll(
			['filters' => ['register' => 'learniq', 'schema' => 'learner-profile', 'learnerId' => 'pupil-1']]
		);
		$topLevel = $this->store->findAll(
			['register' => 'learniq', 'schema' => 'learner-profile', 'filters' => ['ncUserId' => 'pupil-1']]
		);

		self::assertSame([], $rows);
		self::assertSame([], $topLevel);
	}//end testTheStoreAnswersAnUndeclaredFilterWithNothing()

	/**
	 * The merge survivor wins over the merged-away profile, whatever the order.
	 *
	 * @return void
	 */
	public function testTheMergeSurvivorWins(): void {
		$resolver = $this->makeResolver();
		$this->store->rows['learner-profile'] = [
			['id' => 'lp-old', 'ncUserId' => 'pupil-1', 'mergedInto' => 'lp-new'],
			['id' => 'lp-new', 'ncUserId' => 'pupil-1', 'mergedInto' => null],
		];

		self::assertSame('lp-new', $resolver->resolve(learnerId: 'pupil-1'));
	}//end testTheMergeSurvivorWins()

	/**
	 * With only merged-away profiles, the first one is still returned.
	 *
	 * @return void
	 */
	public function testOnlyMergedProfilesFallBackToTheFirst(): void {
		$resolver = $this->makeResolver();
		$this->store->rows['learner-profile'] = [
			['id' => 'lp-a', 'ncUserId' => 'pupil-1', 'mergedInto' => 'lp-x'],
			['id' => 'lp-b', 'ncUserId' => 'pupil-1', 'mergedInto' => 'lp-y'],
		];

		self::assertSame('lp-a', $resolver->resolve(learnerId: 'pupil-1'));
	}//end testOnlyMergedProfilesFallBackToTheFirst()

	/**
	 * No profile, or no user id, resolves to null; an empty id never queries.
	 *
	 * @return void
	 */
	public function testNoProfileOrNoUserIsNull(): void {
		$resolver = $this->makeResolver();
		$this->store->rows['learner-profile'] = [['id' => 'lp-1', 'ncUserId' => 'pupil-1']];

		self::assertNull($resolver->resolve(learnerId: 'pupil-9'));
		self::assertNull($resolver->resolve(learnerId: ''));
		self::assertCount(1, $this->store->reads);
	}//end testNoProfileOrNoUserIsNull()

	/**
	 * Within a tenant, only that tenant's profile answers, and the read
	 * drops the session's tenant scoping to narrow on `tenant_id` itself.
	 *
	 * @return void
	 */
	public function testTheTenantLookupFindsOnlyThatTenantsProfile(): void {
		$resolver = $this->makeResolver();
		$this->store->rows['learner-profile'] = [
			['id' => 'lp-a', 'ncUserId' => 'pupil-1', 'tenant_id' => 'tenant-a'],
			['id' => 'lp-b', 'ncUserId' => 'pupil-1', 'tenant_id' => 'tenant-b'],
		];

		self::assertSame('lp-b', $resolver->resolveInTenant(learnerId: 'pupil-1', tenantId: 'tenant-b'));
		self::assertNull($resolver->resolveInTenant(learnerId: 'pupil-1', tenantId: 'tenant-c'));
		self::assertNull($resolver->resolveInTenant(learnerId: 'pupil-1', tenantId: ''));
		self::assertCount(2, $this->store->reads);
		self::assertFalse($this->store->reads[0]['multitenancy']);
	}//end testTheTenantLookupFindsOnlyThatTenantsProfile()
}//end class
