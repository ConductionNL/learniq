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
 * @spec openspec/changes/lesson-sharing-via-store-plane/specs/course-management/spec.md#requirement-installing-a-shared-course-creates-an-independent-copy-that-keeps-the-credit
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CourseStore;

use OCA\Learniq\Service\CoursePackageImportService;
use OCP\IConfig;

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
	 * @param IConfig                    $config        Resolves the installer's tenant, as the upload import does.
	 */
	public function __construct(
		private readonly CoursePackageImportService $importService,
		private readonly IConfig $config,
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
	 * @spec openspec/changes/lesson-sharing-via-store-plane/specs/course-management/spec.md#requirement-installing-a-shared-course-creates-an-independent-copy-that-keeps-the-credit
	 */
	public function install(array $item, string $userId): array {
		$package = ($item['package'] ?? null);
		if (is_string($package) === true) {
			$package = json_decode($package, true);
		}

		if (is_array($package) === false || isset($package['course']) === false) {
			return $this->failure(message: 'The shared course carries no package to install.');
		}

		$path = tempnam(sys_get_temp_dir(), 'learniq_store_');
		if ($path === false) {
			return $this->failure(message: 'The package could not be staged for import.');
		}

		try {
			file_put_contents($path, (string)json_encode($package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
			$report = $this->importService->import(
				packagePath: $path,
				sourceFilename: ((string)($item['slug'] ?? 'shared-course')) . '.json',
				importedBy: $userId,
				tenantId: $this->tenantFor(userId: $userId)
			);
		} finally {
			if (is_file($path) === true) {
				unlink($path);
			}
		}

		return $this->toStoreReport(report: $report);

	}//end install()

	/**
	 * The installer's tenant: their per-user binding, else the instance id,
	 * the same resolution CoursePackageImportController applies to an upload.
	 *
	 * @param string $userId Nextcloud user id.
	 *
	 * @return string
	 */
	private function tenantFor(string $userId): string {
		$tenantId = (string)$this->config->getUserValue($userId, 'learniq', 'tenant_id', '');
		if ($tenantId !== '') {
			return $tenantId;
		}

		return (string)$this->config->getSystemValue('instanceid', '');

	}//end tenantFor()

	/**
	 * Reshape an import report for the store page.
	 *
	 * @param array<string, mixed> $report The persisted CoursePackageImportReport.
	 *
	 * @return array<string, mixed> `success`, `courseId`, `reportId`, `components` (name, status) and `message`.
	 */
	private function toStoreReport(array $report): array {
		$components = [];
		foreach ((array)($report['entries'] ?? []) as $entry) {
			$entry        = (array)$entry;
			$outcome      = (string)($entry['outcome'] ?? '');
			$components[] = [
				'name'   => (string)($entry['title'] ?? ($entry['resourceType'] ?? '')),
				'status' => (self::STATUS[$outcome] ?? 'refused'),
			];
		}

		$lifecycle = (string)($report['lifecycle'] ?? 'failed');
		$courseId  = ($report['courseId'] ?? null);
		$reportId  = ($report['id'] ?? ($report['uuid'] ?? null));

		if (is_string($courseId) === false || $courseId === '') {
			$courseId = null;
		}

		if (is_string($reportId) === false) {
			$reportId = null;
		}

		return [
			'success'    => ($lifecycle !== 'failed' && $courseId !== null),
			'courseId'   => $courseId,
			'reportId'   => $reportId,
			'components' => $components,
			'message'    => (string)($report['errorMessage'] ?? ''),
		];

	}//end toStoreReport()

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
