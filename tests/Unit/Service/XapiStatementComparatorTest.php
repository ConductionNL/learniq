<?php

/**
 * XapiStatementComparator unit tests: when a re-sent statement is the same statement.
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

use OCA\Learniq\Service\XapiStatementComparator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Statement equivalence as the xAPI conflict check needs it.
 */
#[CoversClass(XapiStatementComparator::class)]
class XapiStatementComparatorTest extends TestCase {

	/**
	 * A base statement.
	 *
	 * @return array<string, mixed>
	 */
	private static function statement(): array {
		return [
			'id' => '6690e6c9-3ef0-4ed3-8b37-7f3964730bee',
			'actor' => ['mbox' => 'mailto:a@example.org', 'objectType' => 'Agent'],
			'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/completed'],
			'object' => ['id' => 'urn:course:1', 'objectType' => 'Activity', 'definition' => ['name' => ['en' => 'Course']]],
			'result' => ['success' => true, 'score' => ['scaled' => 0.8]],
			'timestamp' => '2026-11-13T10:00:00+01:00',
		];
	}//end statement()

	/**
	 * Fields the store adds, key order and the activity definition do not matter.
	 *
	 * @return void
	 */
	public function testStoredAndPresentationDifferencesDoNotMatter(): void {
		$stored = self::statement();
		$stored['stored'] = '2026-11-13T09:00:01Z';
		$stored['authority'] = ['mbox' => 'mailto:lrs@example.org'];
		$stored['version'] = '1.0.3';
		$stored['object'] = ['definition' => ['name' => ['nl' => 'Cursus']], 'objectType' => 'Activity', 'id' => 'urn:course:1'];
		$stored['result'] = ['score' => ['scaled' => 0.8], 'success' => true, 'response' => null];

		self::assertTrue((new XapiStatementComparator())->equivalent(incoming: self::statement(), stored: $stored));
	}//end testStoredAndPresentationDifferencesDoNotMatter()

	/**
	 * The same instant in another zone is the same timestamp; another instant is not.
	 *
	 * @return void
	 */
	public function testTimestampsCompareAsInstants(): void {
		$comparator = new XapiStatementComparator();
		$utc = self::statement();
		$utc['timestamp'] = '2026-11-13T09:00:00Z';
		$later = self::statement();
		$later['timestamp'] = '2026-11-13T09:00:00.001Z';

		self::assertTrue($comparator->equivalent(incoming: self::statement(), stored: $utc));
		self::assertFalse($comparator->equivalent(incoming: self::statement(), stored: $later));
	}//end testTimestampsCompareAsInstants()

	/**
	 * A changed result, verb or actor makes it another statement.
	 *
	 * @return void
	 */
	public function testAChangedCompareFieldIsAConflict(): void {
		$comparator = new XapiStatementComparator();
		$score = self::statement();
		$score['result']['score']['scaled'] = 0.9;
		$verb = self::statement();
		$verb['verb']['id'] = 'http://adlnet.gov/expapi/verbs/failed';
		$extra = self::statement();
		$extra['context'] = ['registration' => 'ec531277-b57b-4c15-8d91-d292c5b2b8f7'];

		self::assertFalse($comparator->equivalent(incoming: self::statement(), stored: $score));
		self::assertFalse($comparator->equivalent(incoming: self::statement(), stored: $verb));
		self::assertFalse($comparator->equivalent(incoming: self::statement(), stored: $extra));
	}//end testAChangedCompareFieldIsAConflict()

	/**
	 * UUIDs compare case-insensitively; other strings do not.
	 *
	 * @return void
	 */
	public function testUuidsIgnoreCaseButOtherStringsDoNot(): void {
		$comparator = new XapiStatementComparator();
		$lower = self::statement();
		$lower['context'] = ['registration' => 'ec531277-b57b-4c15-8d91-d292c5b2b8f7'];
		$upper = self::statement();
		$upper['context'] = ['registration' => 'EC531277-B57B-4C15-8D91-D292C5B2B8F7'];
		$otherCase = self::statement();
		$otherCase['verb']['id'] = 'http://adlnet.gov/expapi/verbs/Completed';

		self::assertTrue($comparator->equivalent(incoming: $lower, stored: $upper));
		self::assertFalse($comparator->equivalent(incoming: self::statement(), stored: $otherCase));
	}//end testUuidsIgnoreCaseButOtherStringsDoNot()

	/**
	 * Context activity definitions are ignored, a single activity equals a list of one,
	 * and group members compare in any order.
	 *
	 * @return void
	 */
	public function testContextActivitiesAndGroupMembers(): void {
		$incoming = self::statement();
		$incoming['context'] = ['contextActivities' => ['parent' => ['id' => 'urn:p', 'definition' => ['name' => ['en' => 'P']]]]];
		$incoming['actor'] = ['objectType' => 'Group', 'member' => [['mbox' => 'mailto:b@example.org'], ['mbox' => 'mailto:a@example.org']]];
		$stored = self::statement();
		$stored['context'] = ['contextActivities' => ['parent' => [['id' => 'urn:p']]]];
		$stored['actor'] = ['member' => [['mbox' => 'mailto:a@example.org'], ['mbox' => 'mailto:b@example.org']], 'objectType' => 'Group'];

		self::assertTrue((new XapiStatementComparator())->equivalent(incoming: $incoming, stored: $stored));
	}//end testContextActivitiesAndGroupMembers()

	/**
	 * A sub-statement keeps its definition-bearing object; list order elsewhere matters.
	 *
	 * @return void
	 */
	public function testNonActivityObjectsAndListOrderAreCompared(): void {
		$comparator = new XapiStatementComparator();
		$incoming = self::statement();
		$incoming['object'] = ['objectType' => 'Agent', 'mbox' => 'mailto:c@example.org', 'definition' => 'x'];
		$stored = $incoming;
		$stored['object']['definition'] = 'y';
		$first = self::statement();
		$first['context'] = ['contextActivities' => ['grouping' => [['id' => 'urn:a'], ['id' => 'urn:b']]]];
		$second = self::statement();
		$second['context'] = ['contextActivities' => ['grouping' => [['id' => 'urn:b'], ['id' => 'urn:a']]]];

		self::assertFalse($comparator->equivalent(incoming: $incoming, stored: $stored));
		self::assertFalse($comparator->equivalent(incoming: $first, stored: $second));
	}//end testNonActivityObjectsAndListOrderAreCompared()

	/**
	 * An unparseable timestamp is compared as text.
	 *
	 * @return void
	 */
	public function testAnUnparseableTimestampComparesAsText(): void {
		$comparator = new XapiStatementComparator();
		$bad = self::statement();
		$bad['timestamp'] = 'yesterday-ish';
		$same = $bad;
		$other = $bad;
		$other['timestamp'] = 'today-ish';

		self::assertTrue($comparator->equivalent(incoming: $bad, stored: $same));
		self::assertFalse($comparator->equivalent(incoming: $bad, stored: $other));
	}//end testAnUnparseableTimestampComparesAsText()
}//end class
