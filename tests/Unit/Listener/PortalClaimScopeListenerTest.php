<?php

/**
 * Tests that learniq refuses its employer claim for a company of another tenant.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-books-places-and-names-her-participants
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\PortalClaimScopeListener;
use OCA\Learniq\Portal\EmployerCompanyTenancy;
use OCA\Portaliq\Event\PortalClaimScopeEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Only learniq's `organisationRef` claim is checked, against the account's portal organisation.
 *
 * @covers \OCA\Learniq\Listener\PortalClaimScopeListener
 */
class PortalClaimScopeListenerTest extends TestCase {

	/**
	 * Every check the listener asked: company (or `location` and location) and portal organisation.
	 *
	 * @var list<list<string>>
	 */
	private array $asked = [];

	/**
	 * The listener over a check that holds only for co-a in school-a.
	 *
	 * @return PortalClaimScopeListener
	 */
	private function listener(): PortalClaimScopeListener {
		$tenancy = $this->createMock(originalClassName: EmployerCompanyTenancy::class);
		$tenancy->method('holds')->willReturnCallback(
			function (string $organisationRef, string $organisation): bool {
				$this->asked[] = [$organisationRef, $organisation];
				return $organisationRef === 'co-a' && $organisation === 'school-a';
			}
		);
		$tenancy->method('holdsLocation')->willReturnCallback(
			function (string $locationRef, string $organisation): bool {
				$this->asked[] = ['location', $locationRef, $organisation];
				return $locationRef === 'loc-a' && $organisation === 'school-a';
			}
		);

		return new PortalClaimScopeListener(tenancy: $tenancy);
	}//end listener()

	/**
	 * A claim check for one claim.
	 *
	 * @param string $appId     The app.
	 * @param string $claimName The claim.
	 * @param string $value     The company.
	 *
	 * @return PortalClaimScopeEvent
	 */
	private static function check(string $appId, string $claimName, string $value): PortalClaimScopeEvent {
		return new PortalClaimScopeEvent(claim: ['appId' => $appId, 'claimName' => $claimName], holder: ['value' => $value, 'organisation' => 'school-a']);
	}//end check()

	/**
	 * Another tenant's company is refused; the portal's own is not.
	 *
	 * @return void
	 */
	public function testAnotherTenantsCompanyIsRefused(): void {
		$listener = $this->listener();

		$foreign = self::check(appId: 'learniq', claimName: 'organisationRef', value: 'co-b');
		$listener->handle($foreign);
		$own = self::check(appId: 'learniq', claimName: 'organisationRef', value: 'co-a');
		$listener->handle($own);

		self::assertTrue(condition: $foreign->isRefused());
		self::assertFalse(condition: $own->isRefused());
		self::assertSame(expected: [['co-b', 'school-a'], ['co-a', 'school-a']], actual: $this->asked);
	}//end testAnotherTenantsCompanyIsRefused()

	/**
	 * A location claim naming another tenant's location is refused; the portal's own is not.
	 *
	 * @return void
	 */
	public function testAnotherTenantsLocationIsRefused(): void {
		$listener = $this->listener();

		$foreign = self::check(appId: 'learniq', claimName: 'editionLocationRef', value: 'loc-b');
		$listener->handle($foreign);
		$own = self::check(appId: 'learniq', claimName: 'editionLocationRef', value: 'loc-a');
		$listener->handle($own);
		$otherApp = self::check(appId: 'pipelinq', claimName: 'editionLocationRef', value: 'loc-b');
		$listener->handle($otherApp);

		self::assertTrue(condition: $foreign->isRefused());
		self::assertFalse(condition: $own->isRefused());
		self::assertFalse(condition: $otherApp->isRefused());
		self::assertSame(expected: [['location', 'loc-b', 'school-a'], ['location', 'loc-a', 'school-a']], actual: $this->asked);
	}//end testAnotherTenantsLocationIsRefused()

	/**
	 * Other apps' claims, learniq's other claims and other events are left alone.
	 *
	 * @return void
	 */
	public function testOtherClaimsAndEventsAreLeftAlone(): void {
		$listener = $this->listener();

		$otherApp = self::check(appId: 'pipelinq', claimName: 'organisationRef', value: 'co-b');
		$listener->handle($otherApp);
		$otherClaim = self::check(appId: 'learniq', claimName: 'organisationName', value: 'Ander BV');
		$listener->handle($otherClaim);
		$listener->handle(new Event());

		self::assertFalse(condition: $otherApp->isRefused());
		self::assertFalse(condition: $otherClaim->isRefused());
		self::assertSame(expected: [], actual: $this->asked);
	}//end testOtherClaimsAndEventsAreLeftAlone()
}//end class
