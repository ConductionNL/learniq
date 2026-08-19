<?php

/**
 * Scholiq Learning Record Import Intake Service
 *
 * Owns everything `LearningRecordImportController::upload()` does once the
 * multipart request itself has been validated: tenant resolution, writing the
 * uploaded bytes into the caller's nc:files home, creating the
 * `LearningRecordImport` object, and dispatching its `parse` lifecycle
 * transition. Extracted so the controller stays the thin HTTP shell ADR-022
 * asks for — it now only reads the request, authorizes, and maps outcomes to
 * status codes — and so both classes stay within this app's PHPMD coupling
 * budget.
 *
 * Legitimate PHP per ADR-031 §"External-format import": storing an uploaded
 * bundle and driving the parse transition cannot be expressed declaratively.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#requirement-a-coordinator-can-upload-another-institution-s-record-as-evidence-during-application-intake
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * Stores a prior-institution learning-record upload and creates the
 * `LearningRecordImport` object that tracks its parse.
 *
 * @spec openspec/changes/portable-learning-record/tasks.md#task-4-2
 */
class LearningRecordImportIntakeService {

	private const SCHOLIQ_REGISTER = 'learniq';
	private const SCHEMA = 'learning-record-import';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object create/read service.
	 * @param TransitionEngine $transitionEngine OR lifecycle engine used to dispatch the `parse` transition.
	 * @param IRootFolder $rootFolder NC root folder for writing the uploaded bytes.
	 * @param IConfig $config Nextcloud config for tenant resolution.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly TransitionEngine $transitionEngine,
		private readonly IRootFolder $rootFolder,
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the requesting tenant's ID.
	 *
	 * The authenticated user's own tenant binding wins; `instanceid` is only the
	 * fallback, because it is the same for every tenant on the instance.
	 *
	 * @param IUser $user Authenticated user whose tenant binding is read.
	 *
	 * @return string Tenant UUID, or the instance id when unbound.
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#requirement-a-coordinator-can-upload-another-institution-s-record-as-evidence-during-application-intake
	 */
	public function resolveTenantId(IUser $user): string {
		$userTenantId = $this->config->getUserValue(
			userId: $user->getUID(),
			appName: 'learniq',
			key: 'tenant_id',
			default: ''
		);

		if ($userTenantId !== '') {
			return $userTenantId;
		}

		return (string)$this->config->getSystemValue('instanceid', '');
	}//end resolveTenantId()

	/**
	 * Write the raw uploaded bytes into the caller's nc:files home, mirroring
	 * `CoursePackageImportService::writeBytesToFiles()`'s destination
	 * convention (`Scholiq/{tenant}/...`).
	 *
	 * @param string $tmpPath Absolute path to the uploaded tmp file.
	 * @param string $ownerUid Nextcloud user id who will own the file.
	 * @param string $tenantId Tenant UUID, used to namespace the destination folder.
	 *
	 * @return string|null The nc:files path (relative, no leading slash), or null on failure.
	 *
	 * @spec openspec/changes/portable-learning-record/tasks.md#task-4-2
	 */
	public function storeUpload(string $tmpPath, string $ownerUid, string $tenantId): ?string {
		try {
			$content = (string)file_get_contents($tmpPath);

			$tenantSegment = 'default';
			if ($tenantId !== '') {
				$tenantSegment = $tenantId;
			}

			$ncBaseDir = 'Scholiq/' . $tenantSegment . '/learning-record-imports';
			$ncPath = $ncBaseDir . '/' . bin2hex(random_bytes(8)) . '.json';

			$userFolder = $this->rootFolder->getUserFolder($ownerUid);

			$current = '';
			foreach (array_filter(explode('/', $ncBaseDir)) as $segment) {
				$prefix = '';
				if ($current !== '') {
					$prefix = $current . '/';
				}

				$current = $prefix . $segment;

				try {
					$userFolder->get($current);
				} catch (NotFoundException $e) {
					$userFolder->newFolder($current);
				}
			}

			$userFolder->newFile($ncPath, $content);

			return $ncPath;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[LearningRecordImportIntakeService] Could not write uploaded bundle: {msg}',
				['msg' => $e->getMessage()]
			);
			return null;
		}//end try

	}//end storeUpload()

	/**
	 * Create the `LearningRecordImport` object for a stored upload and drive
	 * its `parse` transition.
	 *
	 * A transition that raises is logged and swallowed: the import row itself
	 * is already persisted, so the caller still gets the (then `uploaded`)
	 * record back rather than a lost upload.
	 *
	 * @param string $applicationId UUID of the Application this import is evidence for.
	 * @param string $sourceFilename Original filename of the uploaded bundle.
	 * @param string $sourceFormat `scholiq-learning-record` | `elm-europass`.
	 * @param string $uploadedBy Nextcloud user id of the uploader.
	 * @param string $sourceRef nc:files path the bytes were stored at.
	 * @param string $tenantId Tenant UUID to stamp on the created object.
	 *
	 * @return array<string,mixed>|null The created (now `parsed`, or `uploaded`+errorMessage) record, or
	 *                                  null when it could not be created.
	 *
	 * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#scenario-a-coordinator-uploads-a-prior-scholiq-export-during-intake-and-sees-a-verified-coverage-report
	 */
	public function createImport(
		string $applicationId,
		string $sourceFilename,
		string $sourceFormat,
		string $uploadedBy,
		string $sourceRef,
		string $tenantId,
	): ?array {
		$uploadedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

		$created = $this->objectService->saveObject(
			register: self::SCHOLIQ_REGISTER,
			schema: self::SCHEMA,
			object: [
				'applicationId' => $applicationId,
				'sourceFilename' => $sourceFilename,
				'sourceFormat' => $sourceFormat,
				'uploadedBy' => $uploadedBy,
				'uploadedAt' => $uploadedAt,
				'sourceRef' => $sourceRef,
				'lifecycle' => 'uploaded',
				'tenant_id' => $tenantId,
			]
		);

		$createdId = $this->extractId(saved: $created);
		if ($createdId === null) {
			return null;
		}

		try {
			$this->transitionEngine->transition($createdId, 'parse');
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[LearningRecordImportIntakeService] parse transition for {id} raised: {msg}',
				['id' => $createdId, 'msg' => $e->getMessage()]
			);
		}

		$final = $this->objectService->find(id: $createdId, register: self::SCHOLIQ_REGISTER, schema: self::SCHEMA);

		return $this->toArray(row: $final);
	}//end createImport()

	/**
	 * Extract a created object's UUID from an `ObjectService::saveObject()` return value.
	 *
	 * @param mixed $saved Return value of `saveObject()` (array or an ObjectEntity-like object).
	 *
	 * @return string|null The UUID, or null if it could not be resolved.
	 */
	private function extractId(mixed $saved): ?string {
		$data = $this->toArray(row: $saved);

		$id = $data['id'] ?? ($data['uuid'] ?? null);

		if (is_string($id) === true) {
			return $id;
		}

		return null;
	}//end extractId()

	/**
	 * Normalise an OR result row (a raw array or an ObjectEntity-like object) to a plain array.
	 *
	 * @param mixed $row Raw row from ObjectService.
	 *
	 * @return array<string,mixed>
	 */
	private function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$serialized = $row->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return [];
	}//end toArray()
}//end class
