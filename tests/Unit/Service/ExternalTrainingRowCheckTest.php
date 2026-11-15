<?php

/**
 * Tests for ExternalTrainingRowCheck: every refusal reason in its order, the
 * date formats it reads, and the record fields it builds.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
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
 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#scenario-an-invalid-date-is-refused-per-row
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Service\ExternalTrainingRowCheck;
use PHPUnit\Framework\TestCase;

/**
 * Pins the row check's reasons, dates and record.
 */
class ExternalTrainingRowCheckTest extends TestCase {

	/**
	 * A valid row with the given fields replaced.
	 *
	 * @param array<string, mixed> $extra Replaced fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function row(array $extra = []): array {
		return $extra + [
			'learner'     => 'P1',
			'title'       => 'First aid',
			'provider'    => 'Red Cross',
			'completedAt' => '2026-09-15',
		];
	}//end row()

	/**
	 * The check at a fixed instant.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<string, mixed>
	 */
	private static function check(array $row): array {
		return (new ExternalTrainingRowCheck())->check($row, new DateTimeImmutable('2026-10-01T12:00:00', new DateTimeZone('UTC')));
	}//end check()

	/**
	 * Each invalid field names its reason, the first one winning.
	 *
	 * @return void
	 */
	public function testEachReasonInItsOrder(): void {
		$cases = [
			[['learner' => ' '], 'The learner is missing.'],
			[['learner' => null, 'title' => ''], 'The learner is missing.'],
			[['title' => ['x']], 'The title is missing.'],
			[['title' => '', 'provider' => ''], 'The title is missing.'],
			[['provider' => ''], 'The provider is missing.'],
			[['provider' => '', 'kind' => 'webinar'], 'The provider is missing.'],
			[['kind' => 'webinar', 'completedAt' => ''], 'The kind must be one of: {kinds}.'],
			[['completedAt' => 'yesterday'], 'The completed on date is missing or not a date.'],
			[['completedAt' => '31-02-2026'], 'The completed on date is missing or not a date.'],
			[['completedAt' => '2026-10-02', 'validUntil' => 'soon'], 'The completed on date is in the future.'],
			[['validUntil' => 'soon'], 'The valid until date is not a date.'],
			[['validUntil' => '2026-09-15'], 'The valid until date must be after the completed on date.'],
			[['validUntil' => '2026-09-01'], 'The valid until date must be after the completed on date.'],
		];
		foreach ($cases as [$extra, $reason]) {
			$result = self::check(self::row($extra));
			self::assertSame($reason, $result['reason'], json_encode($extra));
			self::assertSame([], $result['record']);
		}
	}//end testEachReasonInItsOrder()

	/**
	 * Only the kind reason carries the list of kinds, and the learner is kept on a refusal.
	 *
	 * @return void
	 */
	public function testTheKindReasonCarriesTheKinds(): void {
		self::assertSame(
			['reason' => 'The kind must be one of: {kinds}.', 'params' => ['kinds' => 'classroom, external-elearning, conference, on-the-job, other'], 'learner' => 'P1', 'record' => []],
			self::check(self::row(['kind' => 'webinar']))
		);
		self::assertSame(
			['reason' => 'The title is missing.', 'params' => [], 'learner' => 'P1', 'record' => []],
			self::check(self::row(['title' => '']))
		);
	}//end testTheKindReasonCarriesTheKinds()

	/**
	 * A minimal row becomes a classroom record with only the required fields.
	 *
	 * @return void
	 */
	public function testAMinimalRowBuildsTheRequiredFields(): void {
		self::assertSame(
			[
				'reason'  => null,
				'params'  => [],
				'learner' => 'P1',
				'record'  => ['title' => 'First aid', 'provider' => 'Red Cross', 'kind' => 'classroom', 'completedAt' => '2026-09-15T00:00:00+00:00'],
			],
			self::check(self::row(['kind' => ' ']))
		);
	}//end testAMinimalRowBuildsTheRequiredFields()

	/**
	 * A full row keeps its expiry and optional texts in a fixed key order.
	 *
	 * @return void
	 */
	public function testAFullRowKeepsItsOptionalFields(): void {
		$result = self::check(
			[
				'evidenceNote'   => ' Certificate 12 ',
				'regulationSlug' => 'bhv',
				'validUntil'     => '5/9/2027',
				'kind'           => 'conference',
				'completedAt'    => '2026-09-15T10:00:00Z',
				'provider'       => ' Red Cross ',
				'title'          => 'First aid',
				'learner'        => 'P1',
			]
		);

		self::assertSame(
			[
				'title'          => 'First aid',
				'provider'       => 'Red Cross',
				'kind'           => 'conference',
				'completedAt'    => '2026-09-15T00:00:00+00:00',
				'validUntil'     => '2027-09-05T00:00:00+00:00',
				'regulationSlug' => 'bhv',
				'evidenceNote'   => 'Certificate 12',
			],
			$result['record']
		);
	}//end testAFullRowKeepsItsOptionalFields()

	/**
	 * The date formats read, and the ones refused.
	 *
	 * @return void
	 */
	public function testTheDateFormats(): void {
		$read = ['15-09-2026' => '2026-09-15', '15/09/2026' => '2026-09-15', '5-9-2026' => '2026-09-05', '2026-09-15' => '2026-09-15', 20260915 => null, '2026-9-15' => null, '2026-02-30' => null];
		foreach ($read as $text => $expected) {
			$result = self::check(self::row(['completedAt' => $text]));
			$actual = null;
			if ($result['reason'] === null) {
				$actual = substr($result['record']['completedAt'], 0, 10);
			}

			self::assertSame($expected, $actual, (string)$text);
		}
	}//end testTheDateFormats()
}//end class
