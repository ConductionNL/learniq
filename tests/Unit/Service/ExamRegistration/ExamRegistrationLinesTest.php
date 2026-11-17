<?php

/**
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\ExamRegistration;

use OCA\Learniq\Service\ExamRegistration\ExamRegistrationLines;
use PHPUnit\Framework\TestCase;

/**
 * The rules of an exam registration list.
 *
 * @covers \OCA\Learniq\Service\ExamRegistration\ExamRegistrationLines
 * @uses   \OCA\Learniq\Service\CsvCellSanitizer
 */
class ExamRegistrationLinesTest extends TestCase {

	private const YOUSSEF = ['id' => 'p-youssef', 'givenName' => 'Youssef', 'familyName' => 'El Amrani', 'placeOfBirth' => 'Utrecht'];

	private const TASK = 'Vul de geboortedatum van Youssef El Amrani in';

	private const JANSEN = ['id' => 'o-jansen', 'name' => 'Jansen Installatietechniek', 'contactName' => 'Linda Jansen'];

	public function testABodyRequiresTheDefaultFieldsUnlessItNamesKnownOnes(): void {
		self::assertSame(['fullName', 'birthDate', 'placeOfBirth'], ExamRegistrationLines::required(required: []));
		self::assertSame(['fullName', 'birthDate', 'placeOfBirth'], ExamRegistrationLines::required(required: ['shoeSize']));
		self::assertSame(['birthDate', 'fullName'], ExamRegistrationLines::required(required: ['birthDate', 'fullName', 'birthDate', 'shoeSize']));
	}//end testABodyRequiresTheDefaultFieldsUnlessItNamesKnownOnes()

	public function testAMissingBirthDateNamesTheFieldAndTellsTheEmployerOnce(): void {
		$line = ExamRegistrationLines::line(
			enrolment: ['id' => 'e-youssef', 'openTask' => self::TASK],
			profile: self::YOUSSEF,
			organisation: self::JANSEN,
			state: ['required' => ExamRegistrationLines::DEFAULT_REQUIRED, 'previous' => null, 'now' => '2026-10-05T07:30:00+02:00']
		);

		self::assertSame(['birthDate'], $line['missing']);
		self::assertFalse($line['complete']);
		self::assertSame('Youssef El Amrani', $line['learnerName']);
		self::assertSame('Linda Jansen', $line['contactName']);
		self::assertSame('2026-10-05T07:30:00+02:00', $line['employerNotifiedAt']);
		self::assertSame(self::TASK, $line['employerTask']);
		self::assertArrayNotHasKey('birthDate', $line, 'the list never carries the birth date');
		self::assertSame('Van Youssef El Amrani (Jansen Installatietechniek) ontbreekt de geboortedatum.', ExamRegistrationLines::missingSentence(line: $line));

		// A later refresh keeps the moment the employer was first told.
		$again = ExamRegistrationLines::line(
			enrolment: ['id' => 'e-youssef', 'openTask' => self::TASK],
			profile: self::YOUSSEF,
			organisation: self::JANSEN,
			state: ['required' => ExamRegistrationLines::DEFAULT_REQUIRED, 'previous' => array_merge($line, ['result' => 'pass']), 'now' => '2026-10-06T10:00:00+02:00']
		);
		self::assertSame('2026-10-05T07:30:00+02:00', $again['employerNotifiedAt']);
		self::assertSame('pass', $again['result']);
	}//end testAMissingBirthDateNamesTheFieldAndTellsTheEmployerOnce()

	public function testACompleteLineClearsTheTaskAndWithoutAPortalTaskNobodyIsTold(): void {
		$complete = ExamRegistrationLines::line(
			enrolment: ['id' => 'e-1', 'openTask' => self::TASK],
			profile: array_merge(self::YOUSSEF, ['birthDate' => '1998-03-04']),
			organisation: self::JANSEN,
			state: ['required' => ExamRegistrationLines::DEFAULT_REQUIRED, 'previous' => ['employerNotifiedAt' => '2026-10-05T07:30:00+02:00'], 'now' => '2026-10-06T10:00:00+02:00']
		);
		self::assertTrue($complete['complete']);
		self::assertSame([], $complete['missing']);
		self::assertNull($complete['employerNotifiedAt']);
		self::assertNull($complete['employerTask'], 'a stale task is not repeated');

		$alone = ExamRegistrationLines::line(
			enrolment: ['id' => 'e-2', 'learnerName' => 'Kim'],
			profile: ['givenName' => 'Kim'],
			organisation: [],
			state: ['required' => ['fullName', 'birthDate'], 'previous' => null, 'now' => '2026-10-06T10:00:00+02:00']
		);
		self::assertSame(['fullName', 'birthDate'], $alone['missing'], 'a name needs both parts');
		self::assertSame('Kim', $alone['learnerName']);
		self::assertNull($alone['employerNotifiedAt'], 'no task on the portal, so nobody was told');
		self::assertNull($alone['employerTask']);
		self::assertSame('Van Kim ontbreekt de naam en de geboortedatum.', ExamRegistrationLines::missingSentence(line: $alone));
	}//end testACompleteLineClearsTheTaskAndWithoutAPortalTaskNobodyIsTold()

	public function testTheSummaryCountsTheCompleteParticipants(): void {
		$lines = array_fill(0, 10, ['complete' => true]);
		$lines[] = ['complete' => false];

		self::assertSame('10 van de 11 deelnemers zijn compleet.', ExamRegistrationLines::summary(lines: $lines));
		self::assertSame(10, ExamRegistrationLines::completeCount(lines: $lines));
		self::assertSame('1 van de 2 deelnemers is compleet.', ExamRegistrationLines::summary(lines: [['complete' => true], ['complete' => false]]));
		self::assertSame('Alle 2 deelnemers zijn compleet.', ExamRegistrationLines::summary(lines: [['complete' => true], ['complete' => true]]));
		self::assertSame('De deelnemer is nog niet compleet.', ExamRegistrationLines::summary(lines: [['complete' => false]]));
	}//end testTheSummaryCountsTheCompleteParticipants()

	public function testTheNoticeSaysWhenTheEmployerWasTold(): void {
		self::assertSame('De werkgever kreeg vanochtend bericht.', ExamRegistrationLines::notice(notifiedAt: '2026-10-05T07:30:00+02:00', now: '2026-10-05T09:00:00+02:00'));
		self::assertSame('De werkgever kreeg vanmiddag bericht.', ExamRegistrationLines::notice(notifiedAt: '2026-10-05T12:30:00Z', now: '2026-10-05T16:00:00+02:00'));
		self::assertSame('De werkgever kreeg gisteren bericht.', ExamRegistrationLines::notice(notifiedAt: '2026-10-04T20:00:00+02:00', now: '2026-10-05T09:00:00+02:00'));
		self::assertSame('De werkgever kreeg op 1 oktober bericht.', ExamRegistrationLines::notice(notifiedAt: '2026-10-01T09:00:00+02:00', now: '2026-10-05T09:00:00+02:00'));
		self::assertNull(ExamRegistrationLines::notice(notifiedAt: null, now: '2026-10-05T09:00:00+02:00'));
	}//end testTheNoticeSaysWhenTheEmployerWasTold()

	public function testTheClosingLineNamesTheExamDayAndTheMoment(): void {
		self::assertSame(
			'De aanmelding voor het examen van donderdag sluit woensdag om 12.00 uur.',
			ExamRegistrationLines::closingSentence(examDate: '2026-10-08', closesAt: '2026-10-07T10:00:00Z')
		);
		self::assertSame('De aanmelding voor het examen sluit woensdag om 12.00 uur.', ExamRegistrationLines::closingSentence(examDate: null, closesAt: '2026-10-07T12:00:00+02:00'));
		self::assertNull(ExamRegistrationLines::closingSentence(examDate: '2026-10-08', closesAt: 'not a moment'));
	}//end testTheClosingLineNamesTheExamDayAndTheMoment()

	public function testAListIsDueFromItsMoment(): void {
		self::assertFalse(ExamRegistrationLines::isDue(closesAt: '2026-10-07T12:00:00+02:00', now: '2026-10-07T11:59:00+02:00'));
		self::assertTrue(ExamRegistrationLines::isDue(closesAt: '2026-10-07T12:00:00+02:00', now: '2026-10-07T10:00:00Z'));
		self::assertFalse(ExamRegistrationLines::isDue(closesAt: null, now: '2026-10-07T12:00:00+02:00'));
	}//end testAListIsDueFromItsMoment()

	public function testTheExportFollowsTheBodysColumnsAndGuardsItsCells(): void {
		$csv = ExamRegistrationLines::csv(
			required: ['birthDate', 'fullName', 'placeOfBirth'],
			lines: [['learnerRef' => 'p-1', 'learnerName' => 'Tom Verbeek'], ['learnerRef' => 'p-2', 'learnerName' => 'Eva']],
			profiles: [
				'p-1' => ['givenName' => 'Tom', 'familyName' => 'Verbeek', 'birthDate' => '1990-01-02', 'placeOfBirth' => "'s-Hertogenbosch; Noord-Brabant"],
				'p-2' => ['givenName' => '=HYPERLINK("x")', 'familyName' => 'Smit', 'birthDate' => '', 'placeOfBirth' => 'Ede'],
			]
		);

		self::assertSame(
			"Geboortedatum;Naam;Geboorteplaats\r\n"
			. "1990-01-02;Tom Verbeek;\"'s-Hertogenbosch; Noord-Brabant\"\r\n"
			. ";\"\t=HYPERLINK(\"\"x\"\") Smit\";Ede\r\n",
			$csv
		);
	}//end testTheExportFollowsTheBodysColumnsAndGuardsItsCells()
}//end class
