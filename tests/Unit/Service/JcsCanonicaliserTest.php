<?php

/**
 * JcsCanonicaliser unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\JcsCanonicaliser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Canonical JSON for signing: sorted object keys at every level, lists kept in order.
 */
#[CoversClass(JcsCanonicaliser::class)]
class JcsCanonicaliserTest extends TestCase {

	/**
	 * Object keys are sorted at every depth; list order is kept.
	 *
	 * @return void
	 */
	public function testObjectKeysAreSortedAndListsKeepTheirOrder(): void {
		$payload = ['b' => 1, 'a' => ['z' => true, 'y' => [3, 1, 2]], 'list' => [['d' => 1, 'c' => 2], 'x']];

		self::assertSame('{"a":{"y":[3,1,2],"z":true},"b":1,"list":[{"c":2,"d":1},"x"]}', JcsCanonicaliser::encode(payload: $payload));
	}//end testObjectKeysAreSortedAndListsKeepTheirOrder()

	/**
	 * Two payloads differing only in key order encode the same.
	 *
	 * @return void
	 */
	public function testKeyOrderDoesNotChangeTheEncoding(): void {
		self::assertSame(
			JcsCanonicaliser::encode(payload: ['id' => 'urn:x', 'name' => 'A', 'when' => ['to' => 2, 'from' => 1]]),
			JcsCanonicaliser::encode(payload: ['when' => ['from' => 1, 'to' => 2], 'name' => 'A', 'id' => 'urn:x'])
		);
	}//end testKeyOrderDoesNotChangeTheEncoding()

	/**
	 * Slashes and non-ASCII text are written as they are.
	 *
	 * @return void
	 */
	public function testSlashesAndUnicodeAreNotEscaped(): void {
		self::assertSame('{"name":"Ærø café","url":"https://example.org/a/b"}', JcsCanonicaliser::encode(payload: ['url' => 'https://example.org/a/b', 'name' => 'Ærø café']));
	}//end testSlashesAndUnicodeAreNotEscaped()

	/**
	 * A value JSON cannot hold makes the encoding fail rather than sign something else.
	 *
	 * @return void
	 */
	public function testAnUnencodableValueFails(): void {
		self::assertNull(JcsCanonicaliser::encode(payload: ['bad' => "\xB1\x31"]));
		self::assertNull(JcsCanonicaliser::encode(payload: ['nan' => NAN]));
	}//end testAnUnencodableValueFails()

	/**
	 * Mixed keys count as an object and are sorted as strings.
	 *
	 * @return void
	 */
	public function testMixedKeysAreSortedAsStrings(): void {
		self::assertSame(['10' => 'c', 2 => 'b', 'a' => 'x'], JcsCanonicaliser::canonicalise(payload: [2 => 'b', 'a' => 'x', 10 => 'c']));
		self::assertSame([2 => 'b', 0 => 'a'], JcsCanonicaliser::canonicalise(payload: [2 => 'b', 0 => 'a']));
	}//end testMixedKeysAreSortedAsStrings()
}//end class
