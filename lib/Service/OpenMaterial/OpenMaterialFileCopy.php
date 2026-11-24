<?php

/**
 * Learniq OpenMaterialFileCopy
 *
 * Keeps a copy of a source record's open file in Nextcloud: integriq fetches
 * the bytes (never the browser), learniq writes them into the teacher's
 * files under the tenant's materials folder.
 *
 * @category Service
 * @package  OCA\Learniq\Service\OpenMaterial
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\OpenMaterial;

use OCA\Learniq\Service\FolderFileWriter;
use OCP\Files\IRootFolder;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A record's open file, copied into Nextcloud.
 *
 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
 */
class OpenMaterialFileCopy {

	/**
	 * Constructor.
	 *
	 * @param OpenMaterialSourceClient $client     Fetches the file through integriq.
	 * @param MaterialLicenceRules     $rules      Names the file that may be copied.
	 * @param IRootFolder              $rootFolder Holds the teacher's files.
	 * @param LoggerInterface          $logger     Logs a copy that could not be made.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
	 */
	public function __construct(
		private readonly OpenMaterialSourceClient $client,
		private readonly MaterialLicenceRules $rules,
		private readonly IRootFolder $rootFolder,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Copy the record's open file into the teacher's files.
	 *
	 * @param IUser                $user     The teacher whose files hold the copy.
	 * @param array<string, mixed> $record   The normalised source record.
	 * @param string               $tenantId The tenant, '' for none.
	 *
	 * @return array{fileRef: string, mimeType: string}|null The copy, or null when none could be made.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
	 */
	public function copy(IUser $user, array $record, string $tenantId): ?array {
		$file = $this->rules->copyableFile(record: $record);
		if ($file === null) {
			return null;
		}

		$sourceId = (string)($record['sourceId'] ?? '');
		try {
			$fetched = $this->client->download(sourceId: $sourceId, url: (string)$file['url']);
			$content = base64_decode((string)($fetched['contentBase64'] ?? ''), true);
			if ($content === false || $content === '') {
				return null;
			}

			$path = self::folder(tenantId: $tenantId) . '/' . self::fileName(sourceId: $sourceId, name: (string)($fetched['name'] ?? ''));
			$files = new FolderFileWriter(userFolder: $this->rootFolder->getUserFolder($user->getUID()));
			$files->ensureFolder(path: self::folder(tenantId: $tenantId));
			$files->put(path: $path, content: $content);
		} catch (Throwable $error) {
			$this->logger->warning('[OpenMaterialFileCopy] No copy of {sourceId}: {msg}', ['sourceId' => $sourceId, 'msg' => $error->getMessage()]);
			return null;
		}

		return ['fileRef' => '/' . $path, 'mimeType' => (string)($fetched['mimeType'] ?? ($file['format'] ?? ''))];
	}//end copy()

	/**
	 * The tenant's materials folder, 'default' when there is no tenant.
	 *
	 * @param string $tenantId The tenant, or ''.
	 *
	 * @return string Folder path relative to the user's files.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
	 */
	public static function folder(string $tenantId): string {
		$segment = preg_replace('/[^A-Za-z0-9_-]+/', '-', $tenantId);
		if ($segment === null || trim($segment, '-') === '') {
			$segment = 'default';
		}

		return 'Learniq/' . $segment . '/materials';
	}//end folder()

	/**
	 * A file name unique per record: a short hash of the record, then the source's own name.
	 *
	 * @param string $sourceId The record identifier.
	 * @param string $name     The name integriq gave the file.
	 *
	 * @return string The file name.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
	 */
	public static function fileName(string $sourceId, string $name): string {
		$safe = (string)preg_replace('/[^A-Za-z0-9_.-]+/', '-', basename($name));
		if (trim($safe, '.-') === '') {
			$safe = 'material';
		}

		return substr(hash('sha256', $sourceId), 0, 8) . '-' . $safe;
	}//end fileName()
}//end class
