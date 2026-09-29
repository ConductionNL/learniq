<?php

/**
 * Unit tests for CourseStoreInstaller: a shared course goes through the
 * existing import as a copy, and the report is reshaped for the store page.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\CourseStore
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-28-lesson-sharing-via-store-plane/tasks.md#task-2-install-as-a-copy-that-keeps-the-credit
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\CourseStore;

use OCA\Learniq\Service\CallerTenantResolver;
use OCA\Learniq\Service\CoursePackageImportService;
use OCA\Learniq\Service\CourseStore\CourseStoreInstaller;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\CourseStore\CourseStoreInstaller
 */
class CourseStoreInstallerTest extends TestCase {

	/**
	 * A config that binds docent-07 to tenant-1.
	 *
	 * @return IConfig
	 */
	private function config(): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			static fn (string $user, string $app, string $key, mixed $default=''): string => ($user === 'docent-07' ? 'tenant-1' : '')
		);
		$config->method('getSystemValue')->willReturn('instance-1');
		return $config;
	}//end config()

	/**
	 * The package is imported from a temp file that is gone afterwards, with
	 * the installer's tenant, and entries become components.
	 *
	 * @return void
	 */
	public function testThePackageIsImportedAsACopy(): void {
		$seen          = [];
		$importService = $this->createMock(CoursePackageImportService::class);
		$importService->expects(self::once())->method('import')->willReturnCallback(
			function (string $path, string $filename, string $user, string $tenant) use (&$seen): array {
				$seen = [
					'path'     => $path,
					'package'  => json_decode((string)file_get_contents($path), true),
					'filename' => $filename,
					'user'     => $user,
					'tenant'   => $tenant,
				];
				return [
					'id'        => 'report-1',
					'courseId'  => 'course-new',
					'lifecycle' => 'partial',
					'entries'   => [
						['title' => 'Betoog', 'outcome' => 'imported'],
						['title' => 'Video', 'outcome' => 'degraded'],
						['resourceType' => 'lti', 'outcome' => 'dropped'],
					],
				];
			}
		);

		$package = ['course' => ['name' => 'Betoog'], 'lessons' => []];
		$report  = (new CourseStoreInstaller($importService, new CallerTenantResolver($this->config(), $this->createMock(ObjectService::class))))->install(
			['slug' => 'course-package-betoog-1a2b3c4d', 'package' => $package],
			'docent-07'
		);

		self::assertSame($package, $seen['package']);
		self::assertSame('course-package-betoog-1a2b3c4d.json', $seen['filename']);
		self::assertSame('docent-07', $seen['user']);
		self::assertSame('tenant-1', $seen['tenant']);
		self::assertFileDoesNotExist($seen['path']);

		self::assertTrue($report['success']);
		self::assertSame('course-new', $report['courseId']);
		self::assertSame('report-1', $report['reportId']);
		self::assertSame(
			[
				['name' => 'Betoog', 'status' => 'installed'],
				['name' => 'Video', 'status' => 'degraded'],
				['name' => 'lti', 'status' => 'refused'],
			],
			$report['components']
		);
	}//end testThePackageIsImportedAsACopy()

	/**
	 * An item without a package writes nothing.
	 *
	 * @return void
	 */
	public function testAnItemWithoutAPackageWritesNothing(): void {
		$importService = $this->createMock(CoursePackageImportService::class);
		$importService->expects(self::never())->method('import');

		$report = (new CourseStoreInstaller($importService, new CallerTenantResolver($this->config(), $this->createMock(ObjectService::class))))->install(['slug' => 'course-package-x-1'], 'docent-07');

		self::assertFalse($report['success']);
		self::assertSame([], $report['components']);
	}//end testAnItemWithoutAPackageWritesNothing()

	/**
	 * A failed import is reported as a failure, and the instance id is the
	 * tenant of a user without a binding.
	 *
	 * @return void
	 */
	public function testAFailedImportIsAFailure(): void {
		$tenant        = '';
		$importService = $this->createMock(CoursePackageImportService::class);
		$importService->method('import')->willReturnCallback(
			function (string $path, string $filename, string $user, string $tenantId) use (&$tenant): array {
				$tenant = $tenantId;
				return ['courseId' => null, 'lifecycle' => 'failed', 'entries' => [], 'errorMessage' => 'Not a package.'];
			}
		);

		$report = (new CourseStoreInstaller($importService, new CallerTenantResolver($this->config(), $this->createMock(ObjectService::class))))->install(
			['slug' => 'course-package-x-1', 'package' => json_encode(['course' => ['name' => 'X']])],
			'someone-else'
		);

		self::assertFalse($report['success']);
		self::assertSame('Not a package.', $report['message']);
		// An unbound installer lands in the default tenant, not the instance id.
		self::assertSame(CallerTenantResolver::DEFAULT_TENANT, $tenant);
	}//end testAFailedImportIsAFailure()
}//end class
