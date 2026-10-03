<?php

/**
 * Learniq CallerTenantResolver unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-29-fix-cross-tenant-idor-planid-lookups/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\CallerTenantResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Tests the caller tenant resolution and the row ownership check.
 */
class CallerTenantResolverTest extends TestCase {
	/**
	 * A bound user resolves to their binding; an unbound one to the default
	 * tenant, never to the instance id (not a UUID, and no seeded row carries it).
	 *
	 * @return void
	 */
	public function testResolveUsesTheBindingThenTheDefaultTenant(): void {
		self::assertSame('tenant-a', $this->resolver(binding: 'tenant-a')->resolve(user: $this->user()));
		self::assertSame(CallerTenantResolver::DEFAULT_TENANT, $this->resolver(binding: '')->resolve(user: $this->user()));
		self::assertSame(CallerTenantResolver::DEFAULT_TENANT, $this->resolver(binding: '   ')->resolve(user: $this->user()));
		self::assertNotSame('instance-x', $this->resolver(binding: '')->resolve(user: $this->user()));
	}//end testResolveUsesTheBindingThenTheDefaultTenant()

	/**
	 * The default tenant is the one the example sets carry, and it passes the
	 * `format: uuid` check OpenRegister applies (opis: 8-4-4-4-12 hex).
	 *
	 * @return void
	 */
	public function testTheDefaultTenantIsTheExampleSetTenantAndAUuid(): void {
		self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', CallerTenantResolver::DEFAULT_TENANT);

		$root = dirname(__DIR__, 3);
		foreach (glob($root . '/lib/Settings/profiles/*.json') as $profile) {
			$tenants = [];
			preg_match_all('/"tenant_id":\s*"([^"]*)"/', (string)file_get_contents($profile), $tenants);
			self::assertSame([CallerTenantResolver::DEFAULT_TENANT], array_values(array_unique($tenants[1])), basename($profile));
		}
	}//end testTheDefaultTenantIsTheExampleSetTenantAndAUuid()

	/**
	 * forUserId() resolves the same way for paths that hold only a uid.
	 *
	 * @return void
	 */
	public function testForUserIdMatchesResolve(): void {
		self::assertSame('tenant-a', $this->resolver(binding: 'tenant-a')->forUserId(userId: 'teacher-1'));
		self::assertSame(CallerTenantResolver::DEFAULT_TENANT, $this->resolver(binding: '')->forUserId(userId: 'teacher-1'));
		self::assertSame(CallerTenantResolver::DEFAULT_TENANT, $this->resolver(binding: 'tenant-a')->forUserId(userId: ''));
	}//end testForUserIdMatchesResolve()

	/**
	 * A row matches only when its tenant equals the caller's.
	 *
	 * @return void
	 */
	public function testOwnsComparesTheRowTenant(): void {
		$resolver = $this->resolver(binding: 'tenant-a');

		self::assertTrue($resolver->owns(user: $this->user(), row: ['tenant_id' => 'tenant-a']));
		self::assertFalse($resolver->owns(user: $this->user(), row: ['tenant_id' => 'tenant-b']));
	}//end testOwnsComparesTheRowTenant()

	/**
	 * A row without a tenant never matches, even for an unbound caller on an
	 * instance whose id cannot be read.
	 *
	 * @return void
	 */
	public function testARowWithoutATenantIsNeverOwned(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn('');
		$config->method('getSystemValue')->willReturn('');

		$resolver = new CallerTenantResolver($config, $this->createMock(ObjectService::class));

		self::assertFalse($resolver->owns(user: $this->user(), row: []));
		self::assertFalse($resolver->owns(user: $this->user(), row: ['tenant_id' => '']));
	}//end testARowWithoutATenantIsNeverOwned()

	/**
	 * findOwned() returns the caller's own row, and null for a foreign or unknown id.
	 *
	 * @return void
	 */
	public function testFindOwnedHidesForeignAndUnknownObjects(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			static function (string $id, mixed $register = null, mixed $schema = null): mixed {
				if ($id === 'missing') {
					throw new DoesNotExistException('no such object');
				}

				$tenant = 'tenant-b';
				if ($id === 'mine') {
					$tenant = 'tenant-a';
				}

				return OrEntityFactory::make(['id' => $id, 'tenant_id' => $tenant], (string)$schema);
			}
		);

		$resolver = $this->resolver(binding: 'tenant-a', objectService: $objectService);

		self::assertSame('mine', ($resolver->findOwned(user: $this->user(), id: 'mine', schema: 'rollover-plan')['id'] ?? null));
		self::assertNull($resolver->findOwned(user: $this->user(), id: 'theirs', schema: 'rollover-plan'));
		self::assertNull($resolver->findOwned(user: $this->user(), id: 'missing', schema: 'rollover-plan'));
	}//end testFindOwnedHidesForeignAndUnknownObjects()

	/**
	 * A resolver over a config with the given per-user binding.
	 *
	 * @param string $binding The user's tenant_id preference ('' for unbound).
	 * @param ObjectService|null $objectService OR double; a bare mock when null.
	 *
	 * @return CallerTenantResolver
	 */
	private function resolver(string $binding, ?ObjectService $objectService = null): CallerTenantResolver {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			static fn (string $userId, string $appName, string $key, mixed $default = ''): mixed => ($appName === 'learniq' && $key === 'tenant_id' ? $binding : $default)
		);
		$config->method('getSystemValue')->willReturnCallback(
			static fn (string $key, mixed $default = ''): mixed => ($key === 'instanceid' ? 'instance-x' : $default)
		);

		return new CallerTenantResolver($config, ($objectService ?? $this->createMock(ObjectService::class)));
	}//end resolver()

	/**
	 * A user double.
	 *
	 * @return IUser
	 */
	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('someone');

		return $user;
	}//end user()
}//end class
