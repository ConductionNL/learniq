<?php

/**
 * Symfony HeaderUtils stub for pure-unit test runs.
 *
 * OCP\AppFramework\Http\DownloadResponse builds its Content-Disposition header
 * with Symfony's HeaderUtils, which Nextcloud server ships and the nextcloud/ocp
 * Composer package does not. Without it, constructing any DataDownloadResponse
 * outside a server checkout errors, so a controller that returns a download
 * could not be tested end to end. The bootstrap loads this only when the real
 * class is absent.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Stubs
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/controller-test-coverage-security-critical/tasks.md#task-3
 */

declare(strict_types=1);

namespace Symfony\Component\HttpFoundation;

/**
 * The one HeaderUtils method DownloadResponse calls.
 */
class HeaderUtils {
	public const DISPOSITION_ATTACHMENT = 'attachment';
	public const DISPOSITION_INLINE = 'inline';

	/**
	 * Build a Content-Disposition value (RFC 6266), as the real method does.
	 *
	 * @param string $disposition attachment or inline.
	 * @param string $filename The filename, possibly non-ASCII.
	 * @param string $filenameFallback ASCII fallback filename.
	 *
	 * @return string The header value.
	 */
	public static function makeDisposition(string $disposition, string $filename, string $filenameFallback = ''): string {
		if ($filenameFallback === '') {
			$filenameFallback = $filename;
		}

		$value = $disposition . '; filename="' . str_replace('"', '\\"', $filenameFallback) . '"';
		if ($filename !== $filenameFallback) {
			$value .= "; filename*=utf-8''" . rawurlencode($filename);
		}

		return $value;
	}//end makeDisposition()
}//end class
