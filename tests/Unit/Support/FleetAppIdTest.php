<?php

/**
 * Unit tests for FleetAppId.
 *
 * The resolver decides which id a cross-app URL is built with while the fleet
 * rename is in flight, and every one of its answers is duck-typed: a wrong id
 * does not error, it silently addresses an app that is not there. Nothing
 * covered it until now, and the path builder in particular used to reach into
 * the global server for its app manager, so no test could say what it saw.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Support
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Support;

use OCA\Learniq\Support\FleetAppId;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests the fleet app id resolver and the paths built from it.
 */
class FleetAppIdTest extends TestCase {

	/**
	 * An app manager that reports exactly the ids named as installed.
	 *
	 * @param array<int, string> $installed The ids this instance has.
	 *
	 * @return IAppManager The app manager double.
	 */
	private function appManager(array $installed): IAppManager {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static fn (string $id): bool => in_array($id, $installed, true)
		);

		return $appManager;
	}//end appManager()

	/**
	 * A migrated instance resolves to the new id.
	 *
	 * @return void
	 */
	public function testTheNewIdWinsWhenTheInstanceHasIt(): void {
		$path = FleetAppId::path($this->appManager(['integriq']), 'integriq', 'api/sources');

		$this->assertSame('/apps/integriq/api/sources', $path);
	}//end testTheNewIdWinsWhenTheInstanceHasIt()

	/**
	 * A beta/main instance still running the old id resolves to that one.
	 *
	 * This is the case a hard swap to the new name breaks: the routes are
	 * mounted under the registered id, so `/apps/integriq/...` on an instance
	 * running `openconnector` is a 404, not an error anyone sees here.
	 *
	 * @return void
	 */
	public function testTheOldIdIsUsedWhenThatIsWhatIsInstalled(): void {
		$path = FleetAppId::path($this->appManager(['openconnector']), 'integriq', 'api/sources');

		$this->assertSame('/apps/openconnector/api/sources', $path);
	}//end testTheOldIdIsUsedWhenThatIsWhatIsInstalled()

	/**
	 * With both ids present the newest candidate wins, which is the order the
	 * CANDIDATES list encodes.
	 *
	 * @return void
	 */
	public function testTheNewestCandidateWinsWhenBothArePresent(): void {
		$path = FleetAppId::path($this->appManager(['integriq', 'openconnector']), 'integriq');

		$this->assertSame('/apps/integriq', $path);
	}//end testTheNewestCandidateWinsWhenBothArePresent()

	/**
	 * No candidate installed falls back to the canonical id rather than
	 * returning something a caller would have to special-case.
	 *
	 * @return void
	 */
	public function testAnAbsentAppFallsBackToTheCanonicalId(): void {
		$path = FleetAppId::path($this->appManager([]), 'filinq', 'api/documents');

		$this->assertSame('/apps/filinq/api/documents', $path);
	}//end testAnAbsentAppFallsBackToTheCanonicalId()

	/**
	 * An app manager that throws for one candidate must not abort the search:
	 * the next candidate may still resolve.
	 *
	 * @return void
	 */
	public function testAThrowingCandidateDoesNotStopTheSearch(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static function (string $id): bool {
				if ($id === 'filinq') {
					throw new RuntimeException('app manager cannot answer for this id');
				}

				return $id === 'docudesk';
			}
		);

		$this->assertSame('/apps/docudesk', FleetAppId::path($appManager, 'filinq'));
	}//end testAThrowingCandidateDoesNotStopTheSearch()

	/**
	 * A leading slash on the suffix does not produce a doubled separator.
	 *
	 * @return void
	 */
	public function testALeadingSlashOnTheSuffixIsNotDoubled(): void {
		$path = FleetAppId::path($this->appManager(['integriq']), 'integriq', '/api/sources');

		$this->assertSame('/apps/integriq/api/sources', $path);
	}//end testALeadingSlashOnTheSuffixIsNotDoubled()

	/**
	 * appPath() differs from path() only in what a miss means: it declines to
	 * build a URL for an app that is not installed.
	 *
	 * @return void
	 */
	public function testAppPathReturnsNullForAnAbsentApp(): void {
		$this->assertNull(FleetAppId::appPath($this->appManager([]), 'integriq', 'api/sources'));
		$this->assertSame(
			'/apps/openconnector/api/sources',
			FleetAppId::appPath($this->appManager(['openconnector']), 'integriq', 'api/sources')
		);
	}//end testAppPathReturnsNullForAnAbsentApp()

	/**
	 * isEnabledForUser() asks about the id the instance actually has, not the
	 * canonical name it may never have registered.
	 *
	 * @return void
	 */
	public function testIsEnabledForUserAsksAboutTheResolvedId(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static fn (string $id): bool => $id === 'openconnector'
		);
		$appManager->expects($this->once())
			->method('isEnabledForUser')
			->with('openconnector')
			->willReturn(true);

		$this->assertTrue(FleetAppId::isEnabledForUser($appManager, 'integriq'));
	}//end testIsEnabledForUserAsksAboutTheResolvedId()
}//end class
