<?php

/**
 * Learniq PortalLearnerResolver unit tests.
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
 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Portal;

use OCA\Learniq\Service\Portal\LearnerProfileLookup;
use OCA\Learniq\Service\Portal\PortalLearnerResolver;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PortalLearnerResolver::resolve().
 */
class PortalLearnerResolverTest extends TestCase {

	/**
	 * Build the resolver over one profile and one account.
	 *
	 * @param array<string, mixed>|null $profile What byRef returns.
	 * @param bool $accountExists Whether the profile's account exists.
	 *
	 * @return PortalLearnerResolver
	 */
	private function makeResolver(?array $profile, bool $accountExists = true): PortalLearnerResolver {
		$lookup = $this->createMock(LearnerProfileLookup::class);
		$lookup->method('byRef')->willReturn($profile);

		$user = $this->createMock(IUser::class);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			static fn (string $uid): ?IUser => ($accountExists === true && $uid === 'pupil-1') ? $user : null
		);

		return new PortalLearnerResolver(profiles: $lookup, userManager: $users);
	}//end makeResolver()

	/**
	 * An active profile with an account becomes the pupil to act for.
	 *
	 * @return void
	 */
	public function testAProfileWithAnAccountResolves(): void {
		$learner = $this->makeResolver(['id' => 'lp-1', 'ncUserId' => 'pupil-1', 'tenant_id' => 't-1'])->resolve(learnerRef: 'lp-1');

		self::assertNotNull($learner);
		self::assertSame('lp-1', $learner->profileRef);
		self::assertSame('pupil-1', $learner->ncUserId);
		self::assertSame('t-1', $learner->tenantId);
	}//end testAProfileWithAnAccountResolves()

	/**
	 * No active profile, no pupil.
	 *
	 * @return void
	 */
	public function testAnUnknownProfileDoesNotResolve(): void {
		self::assertNull($this->makeResolver(null)->resolve(learnerRef: 'lp-9'));
	}//end testAnUnknownProfileDoesNotResolve()

	/**
	 * A profile whose Nextcloud account does not exist is refused, never run as
	 * a user-less principal.
	 *
	 * @return void
	 */
	public function testAProfileWithoutAnAccountDoesNotResolve(): void {
		self::assertNull(
			$this->makeResolver(['id' => 'lp-1', 'ncUserId' => 'pupil-1'], accountExists: false)->resolve(learnerRef: 'lp-1')
		);
	}//end testAProfileWithoutAnAccountDoesNotResolve()
}//end class
