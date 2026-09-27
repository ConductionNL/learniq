<?php

/**
 * Learniq Course Store URL Guard
 *
 * One seam around OpenRegister's SSRF guard, `SecurityService::assertSafeFetchUrl()`,
 * the same check the store plane runs before every registry read. The publisher
 * calls it before every registry write; tests replace this class instead of
 * resolving real host names.
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
 * @spec openspec/changes/lesson-sharing-via-store-plane/specs/course-management/spec.md#requirement-publishing-sends-a-gated-package-to-the-registry
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CourseStore;

use OCA\OpenRegister\Service\SecurityService;

/**
 * Refuses registry URLs that are not public http(s) addresses.
 */
class CourseStoreUrlGuard {

	/**
	 * Throw when the URL is private, reserved, loopback, unresolvable or not http(s).
	 *
	 * @param string $url The registry URL.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the URL is unsafe.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SecurityService::assertSafeFetchUrl is static upstream;
	 *   calling it directly is how the store plane applies it too.
	 *
	 * @spec openspec/changes/lesson-sharing-via-store-plane/specs/course-management/spec.md#requirement-publishing-sends-a-gated-package-to-the-registry
	 */
	public function assertSafe(string $url): void {
		SecurityService::assertSafeFetchUrl($url);

	}//end assertSafe()
}//end class
