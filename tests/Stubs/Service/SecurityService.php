<?php

/**
 * Test stub for OCA\OpenRegister\Service\SecurityService.
 *
 * Only the static SSRF guard the store plane uses, which learniq's course
 * store publisher applies to its registry URL through CourseStoreUrlGuard
 * (lesson-sharing-via-store-plane). The real guard resolves the host and
 * refuses private, loopback, link-local and non-http(s) targets; this stub
 * keeps the scheme and literal-private-address checks so a test that forgets
 * to replace the guard still fails closed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Stubs\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use InvalidArgumentException;

/**
 * Stub for SecurityService.
 */
class SecurityService {

	/**
	 * Refuse a URL that is not a public http(s) address.
	 *
	 * @param string $url The URL to check.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the URL is not safe to fetch.
	 */
	public static function assertSafeFetchUrl(string $url): void {
		$parts = parse_url($url);
		if ($parts === false || empty($parts['scheme']) === true || empty($parts['host']) === true) {
			throw new InvalidArgumentException('Invalid URL.');
		}

		if (in_array(strtolower($parts['scheme']), ['http', 'https'], true) === false) {
			throw new InvalidArgumentException('Only http and https URLs are allowed.');
		}

		$host = $parts['host'];
		if (filter_var($host, FILTER_VALIDATE_IP) !== false
			&& filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
		) {
			throw new InvalidArgumentException('Private or reserved addresses are not allowed.');
		}
	}//end assertSafeFetchUrl()
}//end class
