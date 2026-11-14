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
use OCA\Learniq\Service\Portal\PortalEmployerBookings;
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
		// credential-wallet-offer-for-the-learner: the open wallet offer as a QR code with its link.
		self::assertContains('walletOfferUri', $collections['participantCertificates']['fields']);
		self::assertContains(
			['field' => 'walletOfferUri', 'label' => 'Wallet', 'render' => 'qr', 'linkLabel' => 'Add to wallet'],
			$collections['participantCertificates']['columns']
		);
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

	/**
	 * "Uw volgende cursusdag" once: the highlight shows only the next day, and
	 * the list under it is "Daarna", the days after it (REPORT-2, item 10).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/guardian-and-participant-pages-follow-the-boards/specs/portal-contribution/spec.md#requirement-the-participant-reads-his-next-course-day-once
	 */
	public function testTheNextCourseDayShowsOnce(): void {
		$overview = array_column(self::manifest()['pages'], null, 'id')['participantOverview'];
		$coming   = array_values(array_filter($overview['blocks'], static fn (array $b): bool => ($b['collection'] ?? '') === 'participantComingDays'));

		self::assertCount(2, $coming);
		self::assertSame(['tasks', 1], [$coming[0]['type'], $coming[0]['limit']]);
		self::assertSame(['After that', 1, 4], [$coming[1]['label'], $coming[1]['skip'], $coming[1]['limit']]);
		self::assertSame(['field' => 'firstDay', 'direction' => 'asc'], $coming[1]['sort']);
	}//end testTheNextCourseDayShowsOnce()

	/**
	 * When his employer sends a colleague instead, his portal tells him: one rule
	 * on his course days, addressed by his own claim, worded only for the
	 * substitution reason, and every placeholder it fills is a field he may read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/employer-substitutes-a-colleague/specs/portal-contribution/spec.md#requirement-an-employer-sends-a-colleague-in-place-of-a-participant-before-the-deadline
	 */
	public function testHeIsToldWhenAColleagueGoesInHisPlace(): void {
		$manifest = self::manifest();
		$rules    = array_column($manifest['notifications'], null, 'ruleKey');
		self::assertArrayHasKey(key: 'enrolment.substituted', array: $rules);
		$rule = $rules['enrolment.substituted'];

		self::assertSame(expected: ['field' => 'withdrawReason', 'operator' => 'changed'], actual: $rule['on']);
		self::assertSame(expected: ['field' => 'learnerRef', 'claim' => 'learnerRef'], actual: $rule['recipients']);
		self::assertSame(expected: [PortalEmployerBookings::SUBSTITUTED], actual: array_keys($rule['messages']));
		self::assertSame(expected: 'vervangen door collega', actual: PortalEmployerBookings::SUBSTITUTED);

		$collection = array_column($manifest['collections'], null, 'id')[$rule['collection']];
		self::assertSame(expected: 'enrolment', actual: $collection['schema']);
		self::assertContains(needle: $rule['titleField'], haystack: $collection['fields']);
		$message = $rule['messages'][PortalEmployerBookings::SUBSTITUTED];
		self::assertSame(expected: 'Een collega gaat in uw plaats', actual: $message['subject']['nl']);
		foreach (['nl', 'en'] as $language) {
			preg_match_all('/\{(\w+)/', $message['body'][$language], $placeholders);
			self::assertNotEmpty(actual: $placeholders[1]);
			foreach ($placeholders[1] as $field) {
				self::assertContains(needle: $field, haystack: $collection['fields'], message: $language.': '.$field);
			}
		}
	}//end testHeIsToldWhenAColleagueGoesInHisPlace()
}//end class
