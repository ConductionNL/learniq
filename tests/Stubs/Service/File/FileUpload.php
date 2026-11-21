<?php

/**
 * OpenRegister FileUpload stub.
 *
 * Mirrors openregister main's lib/Service/File/FileUpload.php, the file a
 * FileService write takes, for the unit suite that runs without openregister.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Stub
 * @package  OCA\OpenRegister\Service\File
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

/**
 * The file name, content, share toggle and tags of one file write.
 */
final class FileUpload {

	/**
	 * Constructor.
	 *
	 * @param string $fileName The file name.
	 * @param mixed  $content  The file content.
	 * @param bool   $share    Whether to share a new file.
	 * @param array  $tags     Tags for the file.
	 */
	public function __construct(
		public string $fileName,
		public mixed $content,
		public bool $share,
		public array $tags = [],
	) {
	}//end __construct()
}//end class
