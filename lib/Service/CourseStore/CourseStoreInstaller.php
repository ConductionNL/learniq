<?php

/**
 * Learniq Course Store Installer
 *
 * Installs a shared course from the course store as a copy: the resolved
 * registry object's `package` goes through the existing
 * CoursePackageImportService as learniq JSON, exactly like an uploaded file,
 * so every write is a new object under the caller's RBAC and nothing local is
 * overwritten. The import report is reshaped into the per-component report
 * the shared store page renders.
 *
 * @category Service
 * @package  OCA\Learniq\Service\CourseStore
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
 * @spec openspec/specs/course-management/spec.md#requirement-installing-a-shared-course-creates-an-independent-copy-that-keeps-the-credit
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CourseStore;

use OCA\Learniq\Service\CallerTenantResolver;
use OCA\Learniq\Service\CoursePackageImportService;

/**
 * Registry object in, imported copy and report out.
 */
class CourseStoreInstaller {

	/**
	 * Import outcome => store page component status.
	 */
	private const STATUS = ['imported' => 'installed', 'degraded' => 'degraded', 'dropped' => 'refused'];

	/**
	 * Constructor.
	 *
	 * @param CoursePackageImportService $importService The existing course package importer.
	 * @param CallerTenantResolver $tenants Resolves the tenant: the per-user binding, else the default tenant.
	 */
	public function __construct(
		private readonly CoursePackageImportService $importService,
		private readonly CallerTenantResolver $tenants,
	) {

	}//end __construct()

	/**
	 * Install a resolved registry object.
	 *
	 * @param array<string, mixed> $item   The full registry object from the store plane.
	 * @param string               $userId Nextcloud user id of the installer.
	 *
	 * @return array<string, mixed> `success`, `courseId`, `reportId`, `components` (name, status) and `message`.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-installing-a-shared-course-creates-an-independent-copy-that-keeps-the-credit
	 */
	public function install(array $item, string $userId): array {
		$request = new CourseStoreRequest(item: $item, userId: $userId);
		if (is_array($request->package) === false || isset($request->package['course']) === false) {
			return $this->failure(message: 'The shared course carries no package to install.');
		}

		$path = tempnam(sys_get_temp_dir(), 'learniq_store_');
		if ($path === false) {
			return $this->failure(message: 'The package could not be staged for import.');
		}

		return $this->toStoreReport(report: $this->importStaged(path: $path, request: $request));
	}//end install()

	/**
	 * Write the package to the staged file and import it in the installer's
	 * tenant; the file is removed whatever the import does.
	 *
	 * @param string             $path    The staged file.
	 * @param CourseStoreRequest $request The item, its package and the installer.
	 *
	 * @return array<string, mixed> The persisted CoursePackageImportReport.
	 */
	private function importStaged(string $path, CourseStoreRequest $request): array {
		try {
			file_put_contents($path, (string)json_encode($request->package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
			return $this->importService->import(
				packagePath: $path,
				sourceFilename: ((string)($request->item['slug'] ?? 'shared-course')) . '.json',
				importedBy: $request->userId,
				tenantId: $this->tenantFor(userId: $request->userId)
			);
		} finally {
			$this->discard(path: $path);
		}
	}//end importStaged()

	/**
	 * Remove a staged file when it is still there.
	 *
	 * @param string $path The staged file.
	 *
	 * @return void
	 */
	private function discard(string $path): void {
		if (is_file($path) === true) {
			unlink($path);
		}
	}//end discard()

	/**
	 * The installer's tenant: their per-user binding, else the default tenant,
	 * the same resolution CoursePackageImportController applies to an upload.
	 *
	 * @param string $userId Nextcloud user id.
	 *
	 * @return string
	 */
	private function tenantFor(string $userId): string {
		return $this->tenants->forUserId(userId: $userId);

	}//end tenantFor()

	/**
	 * Reshape an import report for the store page.
	 *
	 * @param array<string, mixed> $report The persisted CoursePackageImportReport.
	 *
	 * @return array<string, mixed> `success`, `courseId`, `reportId`, `components` (name, status) and `message`.
	 */
	private function toStoreReport(array $report): array {
		$components = $this->componentsOf(report: $report);
		$lifecycle  = (string)($report['lifecycle'] ?? 'failed');
		$courseId   = $this->courseIdOf(report: $report);

		return [
			'success'    => ($lifecycle !== 'failed' && $courseId !== null),
			'courseId'   => $courseId,
			'reportId'   => $this->reportIdOf(report: $report),
			'components' => $components,
			'message'    => (string)($report['errorMessage'] ?? ''),
		];
	}//end toStoreReport()

	/**
	 * The report's entries as store page components (name, status).
	 *
	 * @param array<string, mixed> $report The persisted CoursePackageImportReport.
	 *
	 * @return array<int, array{name: string, status: string}>
	 */
	private function componentsOf(array $report): array {
		$components = [];
		foreach ((array)($report['entries'] ?? []) as $entry) {
			$entry        = (array)$entry;
			$outcome      = (string)($entry['outcome'] ?? '');
			$components[] = [
				'name'   => (string)($entry['title'] ?? ($entry['resourceType'] ?? '')),
				'status' => (self::STATUS[$outcome] ?? 'refused'),
			];
		}

		return $components;
	}//end componentsOf()

	/**
	 * The imported course's id, or null when the report names none.
	 *
	 * @param array<string, mixed> $report The persisted CoursePackageImportReport.
	 *
	 * @return string|null
	 */
	private function courseIdOf(array $report): ?string {
		$courseId = ($report['courseId'] ?? null);
		if (is_string($courseId) === false || $courseId === '') {
			return null;
		}

		return $courseId;
	}//end courseIdOf()

	/**
	 * The report's own id (`id`, else `uuid`), or null when it is not text.
	 *
	 * @param array<string, mixed> $report The persisted CoursePackageImportReport.
	 *
	 * @return string|null
	 */
	private function reportIdOf(array $report): ?string {
		$reportId = ($report['id'] ?? ($report['uuid'] ?? null));
		if (is_string($reportId) === false) {
			return null;
		}

		return $reportId;
	}//end reportIdOf()

	/**
	 * A failed install that wrote nothing.
	 *
	 * @param string $message Why.
	 *
	 * @return array<string, mixed> A report with `success` false and no components.
	 */
	private function failure(string $message): array {
		return ['success' => false, 'courseId' => null, 'reportId' => null, 'components' => [], 'message' => $message];

	}//end failure()
}//end class
