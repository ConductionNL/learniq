<?php

/**
 * The participant's portal: his next course day and his certificates.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Portal
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
 * @spec openspec/changes/participant-portal/specs/portal-contribution/spec.md#requirement-a-participant-lands-on-his-next-course-day
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;

/**
 * The participant audience.
 */
class ParticipantSitePagesTest extends TestCase {

	/**
	 * The participant's manifest.
	 *
	 * @return array<string, mixed>
	 */
	private static function manifest(): array {
		return (new PortalContributionProvider())->getContribution(['audience' => 'participant']);
	}//end manifest()

	/**
	 * Every read is his own, matched on his learnerRef claim; nothing is joined, and no write is offered.
	 *
	 * @return void
	 */
	public function testEveryReadIsHisOwn(): void {
		self::assertContains('participant', (new PortalContributionProvider())->getAudiences());
		$manifest = self::manifest();
		self::assertSame([], $manifest['actions']);
		foreach ($manifest['collections'] as $collection) {
			self::assertSame('learnerRef', $collection['scopeClaim'], $collection['id']);
			self::assertArrayNotHasKey('via', $collection);
			$expected = 'learnerRef';
			if ($collection['schema'] === 'credential') {
				$expected = 'learnerId';
			}

			self::assertSame($expected, $collection['scopeField'], $collection['id']);
			self::assertContains($expected, $collection['fields'], $collection['id']);
		}
	}//end testEveryReadIsHisOwn()

	/**
	 * The overview opens with the greeting and the next course day as a highlight,
	 * and every field a block reads is projected.
	 *
	 * @return void
	 */
	public function testTheOverviewShowsTheNextCourseDayFirst(): void {
		$manifest = self::manifest();
		$collections = array_column($manifest['collections'], null, 'id');
		$pages = array_column($manifest['pages'], null, 'id');
		self::assertTrue($pages['participantOverview']['home']);
		self::assertSame(['greeting', 'tasks', 'collection', 'collection'], array_column($pages['participantOverview']['blocks'], 'type'));
		self::assertSame(['upcoming' => true], $collections['participantComingDays']['filter']);
		self::assertSame(['lifecycle' => 'issued', 'kind' => 'certificate'], $collections['participantCertificates']['filter']);
		foreach ($manifest['pages'] as $page) {
			foreach ($page['blocks'] as $block) {
				if (isset($block['collection']) === false) {
					continue;
				}

				$fields = $collections[$block['collection']]['fields'];
				$read = array_merge(($block['titleFields'] ?? []), ($block['subtitleFields'] ?? []));
				foreach (['dateField', 'subtitleField', 'statusField', 'statusNoteField', 'quoteField', 'dueField'] as $key) {
					if (isset($block[$key]) === true) {
						$read[] = $block[$key];
					}
				}

				self::assertSame([], array_diff($read, $fields), $page['id'] . ' ' . $block['collection']);
			}
		}
	}//end testTheOverviewShowsTheNextCourseDayFirst()

	/**
	 * Tom's enrolments in the training set carry his course days.
	 *
	 * @return void
	 */
	public function testTomsCourseDaysAreOnHisEnrolments(): void {
		$set = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/profiles/training.json'), true);
		$objects = $set['x-openregister']['seedData']['objects'];
		$tom = array_values(array_filter($objects['learner-profile'], static fn (array $p): bool => ($p['fullName'] ?? '') === 'Tom Verbeek'))[0];
		$days = [];
		foreach ($objects['enrolment'] as $enrolment) {
			if ($enrolment['learnerRef'] === $tom['uuid'] && ($enrolment['upcoming'] ?? false) === true) {
				$days[$enrolment['firstDay']] = [$enrolment['courseName'], $enrolment['dayLabel'], $enrolment['timeLabel'], $enrolment['placeLabel'], $enrolment['trainerName']];
			}
		}

		ksort($days);
		self::assertSame(
			[
				'2026-10-08' => ['F-gassen: herhaling en examen', 'donderdag 8 oktober', '08.30 tot 16.30 uur', 'Praktijkhal Zuiddrecht, Energieweg 8', 'Henk Dekker'],
				'2026-11-03' => ['Lucht-water warmtepomp: ontwerp en inbedrijfstelling', '3, 4 en 10 november', '08.30 tot 16.30 uur', 'Praktijkhal Zuiddrecht, Energieweg 8', 'Henk Dekker'],
			],
			$days
		);
	}//end testTomsCourseDaysAreOnHisEnrolments()
}//end class
