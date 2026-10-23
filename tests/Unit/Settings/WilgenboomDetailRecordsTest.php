<?php

/**
 * The primary school example set carries one record for each detail page the
 * boards draw and the set had nothing for: an aanmelding, a certificate, an
 * assessment item, a peer review and a cmi5 lesson. Their uuids are fixed, so
 * the boards name them directly.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/exset-po-records-for-detail-boards/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Reads lib/Settings/profiles/po.json.
 */
class WilgenboomDetailRecordsTest extends TestCase {

	private const PROFILE = __DIR__ . '/../../../lib/Settings/profiles/po.json';

	/**
	 * The set's objects by uuid.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $byUuid = null;

	/**
	 * The set's objects by uuid, each with its bucket.
	 *
	 * @return array<string, array<string, mixed>> The objects
	 */
	private static function byUuid(): array {
		if (self::$byUuid === null) {
			$set          = json_decode((string)file_get_contents(self::PROFILE), true);
			self::$byUuid = [];
			foreach ($set['x-openregister']['seedData']['objects'] as $bucket => $rows) {
				foreach ($rows as $row) {
					self::$byUuid[$row['uuid']] = $row + ['_bucket' => $bucket];
				}
			}
		}

		return self::$byUuid;
	}//end byUuid()

	/**
	 * One object, asserted to be of a bucket.
	 *
	 * @param string $uuid   The uuid.
	 * @param string $bucket The schema bucket.
	 *
	 * @return array<string, mixed> The object
	 */
	private function object(string $uuid, string $bucket): array {
		$object = (self::byUuid()[$uuid] ?? null);
		$this->assertNotNull(actual: $object, message: $bucket . ' ' . $uuid . ' is not in the set');
		$this->assertSame(expected: $bucket, actual: $object['_bucket']);
		return $object;
	}//end object()

	/**
	 * The aanmelding waits for its intake in the open round for groep 1.
	 *
	 * @return void
	 */
	public function testTheAanmeldingWaitsForItsIntake(): void {
		$admission = $this->object(uuid: 'ee010024-0000-4000-8000-000000000001', bucket: 'admission');
		$round     = $this->object(uuid: $admission['admissionsRoundId'], bucket: 'admissions-round');

		$this->assertSame(expected: ['intake-scheduled', 'open'], actual: [$admission['lifecycle'], $round['lifecycle']]);
		$this->assertSame(expected: $round['programmeId'], actual: $admission['programmeId']);
	}//end testTheAanmeldingWaitsForItsIntake()

	/**
	 * The certificate is issued to a pupil of the set.
	 *
	 * @return void
	 */
	public function testTheCertificateBelongsToAPupil(): void {
		$credential = $this->object(uuid: 'ee010025-0000-4000-8000-000000000001', bucket: 'credential');

		$this->assertSame(expected: ['certificate', 'issued'], actual: [$credential['kind'], $credential['lifecycle']]);
		$this->object(uuid: $credential['learnerId'], bucket: 'learner-profile');
	}//end testTheCertificateBelongsToAPupil()

	/**
	 * The item sits in its bank, and the bank lists it.
	 *
	 * @return void
	 */
	public function testTheItemSitsInItsBank(): void {
		$item = $this->object(uuid: 'ee010027-0000-4000-8000-000000000001', bucket: 'item');
		$bank = $this->object(uuid: $item['itemBankId'], bucket: 'item-bank');

		$this->assertSame(expected: [$item['uuid']], actual: $bank['itemIds']);
	}//end testTheItemSitsInItsBank()

	/**
	 * The peer review is by another pupil than the one who handed in.
	 *
	 * @return void
	 */
	public function testThePeerReviewIsByAnotherPupil(): void {
		$review     = $this->object(uuid: 'ee010028-0000-4000-8000-000000000001', bucket: 'peer-review');
		$submission = $this->object(uuid: $review['submissionId'], bucket: 'submission');

		$this->assertSame(expected: 'ee01001e-0000-4000-8000-000000000010', actual: $review['assignmentId']);
		$this->assertSame(expected: $review['assignmentId'], actual: $submission['assignmentId']);
		$this->assertNotContains(needle: $review['reviewerId'], haystack: $submission['learnerIds']);
	}//end testThePeerReviewIsByAnotherPupil()

	/**
	 * The e-learning lesson is a cmi5 lesson in the rekenen course.
	 *
	 * @return void
	 */
	public function testTheLessonIsDeliveredAsCmi5(): void {
		$lesson = $this->object(uuid: 'ee010029-0000-4000-8000-000000000001', bucket: 'lesson');

		$this->assertSame(expected: ['cmi5', 'ee010004-0000-4000-8000-000000000002'], actual: [$lesson['contentType'], $lesson['courseId']]);
	}//end testTheLessonIsDeliveredAsCmi5()
}//end class
