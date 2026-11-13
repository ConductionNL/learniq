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
 * @uses   \OCA\Learniq\Service\CallerTenantResolver
 * @uses   \OCA\Learniq\Service\CourseStore\CourseStoreRequest
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

	/**
	 * An installer over an import service that answers with the given report.
	 *
	 * @param array<string, mixed>|\Throwable $answer The report, or the exception the import throws.
	 * @param array<string, mixed>            $seen   Filled with what the import saw.
	 *
	 * @return CourseStoreInstaller
	 */
	private function installer(array|\Throwable $answer, array &$seen = []): CourseStoreInstaller {
		$importService = $this->createMock(CoursePackageImportService::class);
		$importService->method('import')->willReturnCallback(
			function (string $path, string $filename, string $user, string $tenant) use ($answer, &$seen): array {
				$seen = ['path' => $path, 'raw' => (string)file_get_contents($path), 'filename' => $filename, 'tenant' => $tenant];
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);

		return new CourseStoreInstaller($importService, new CallerTenantResolver($this->config(), $this->createMock(ObjectService::class)));
	}//end installer()

	/**
	 * Refused packages name the reason and import nothing.
	 *
	 * @return void
	 */
	public function testRefusedPackagesNameTheReason(): void {
		$none = ['success' => false, 'courseId' => null, 'reportId' => null, 'components' => [], 'message' => 'The shared course carries no package to install.'];
		foreach ([['package' => ['lessons' => []]], ['package' => '{not json'], ['package' => '"text"'], ['package' => 7], []] as $item) {
			$seen = [];
			self::assertSame($none, $this->installer([], $seen)->install($item, 'docent-07'), json_encode($item));
			self::assertSame([], $seen);
		}
	}//end testRefusedPackagesNameTheReason()

	/**
	 * The staged file is the package as unescaped JSON, named after the slug or a default.
	 *
	 * @return void
	 */
	public function testTheStagedFileAndItsName(): void {
		$seen = [];
		$this->installer(['lifecycle' => 'imported', 'courseId' => 'c'], $seen)->install(['package' => ['course' => ['name' => 'Één/twee']]], 'docent-07');

		self::assertSame('{"course":{"name":"Één/twee"}}', $seen['raw']);
		self::assertSame('shared-course.json', $seen['filename']);
		self::assertSame('tenant-1', $seen['tenant']);
		self::assertFileDoesNotExist($seen['path']);
	}//end testTheStagedFileAndItsName()

	/**
	 * A throwing import still removes the staged file and passes the exception on.
	 *
	 * @return void
	 */
	public function testAThrowingImportRemovesTheStagedFile(): void {
		$seen = [];
		try {
			$this->installer(new \RuntimeException('boom'), $seen)->install(['slug' => 's', 'package' => ['course' => []]], 'docent-07');
			self::fail('The exception was swallowed.');
		} catch (\RuntimeException $exception) {
			self::assertSame('boom', $exception->getMessage());
		}

		self::assertFileDoesNotExist($seen['path']);
	}//end testAThrowingImportRemovesTheStagedFile()

	/**
	 * Report shapes map to the store answer field by field.
	 *
	 * @return void
	 */
	public function testReportShapes(): void {
		$item  = ['package' => ['course' => []]];
		$cases = [
			[
				['uuid' => 'r-2', 'courseId' => 'c-1', 'entries' => [['outcome' => 'unknown'], (object)['title' => 'T', 'outcome' => 'imported']]],
				['success' => false, 'courseId' => 'c-1', 'reportId' => 'r-2', 'components' => [['name' => '', 'status' => 'refused'], ['name' => 'T', 'status' => 'installed']], 'message' => ''],
			],
			[
				['id' => 5, 'courseId' => '', 'lifecycle' => 'imported'],
				['success' => false, 'courseId' => null, 'reportId' => null, 'components' => [], 'message' => ''],
			],
			[
				['id' => '', 'courseId' => 12, 'lifecycle' => 'imported', 'errorMessage' => 'x'],
				['success' => false, 'courseId' => null, 'reportId' => '', 'components' => [], 'message' => 'x'],
			],
			[
				['courseId' => 'c-2', 'lifecycle' => 'imported', 'entries' => 'none'],
				['success' => true, 'courseId' => 'c-2', 'reportId' => null, 'components' => [['name' => '', 'status' => 'refused']], 'message' => ''],
			],
		];
		foreach ($cases as [$report, $expected]) {
			self::assertSame($expected, $this->installer($report)->install($item, 'docent-07'));
		}
	}//end testReportShapes()
}//end class
