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

use OCA\Learniq\Service\ExamRegistration\ExamRegistrationDates;
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

	/**
	 * A body requires the default fields unless it names known ones.
	 *
	 * @return void
	 */
	public function testABodyRequiresTheDefaultFieldsUnlessItNamesKnownOnes(): void {
		self::assertSame(expected: ['fullName', 'birthDate', 'placeOfBirth'], actual: (new ExamRegistrationLines())->required(required: []));
		self::assertSame(
			expected: ['fullName', 'birthDate', 'placeOfBirth'],
			actual: (new ExamRegistrationLines())->required(required: ['shoeSize'])
		);
		self::assertSame(
			expected: ['birthDate', 'fullName'],
			actual: (new ExamRegistrationLines())->required(required: ['birthDate', 'fullName', 'birthDate', 'shoeSize'])
		);
	}//end testABodyRequiresTheDefaultFieldsUnlessItNamesKnownOnes()

	/**
	 * A missing birth date names the field and tells the employer once.
	 *
	 * @return void
	 */
	public function testAMissingBirthDateNamesTheFieldAndTellsTheEmployerOnce(): void {
		$line = (new ExamRegistrationLines())->line(
			enrolment: ['id' => 'e-youssef', 'openTask' => self::TASK],
			profile: self::YOUSSEF,
			organisation: self::JANSEN,
			state: ['required' => ExamRegistrationLines::DEFAULT_REQUIRED, 'previous' => null, 'now' => '2026-10-05T07:30:00+02:00']
		);

		self::assertSame(expected: ['birthDate'], actual: $line['missing']);
		self::assertFalse(condition: $line['complete']);
		self::assertSame(expected: 'Youssef El Amrani', actual: $line['learnerName']);
		self::assertSame(expected: 'Linda Jansen', actual: $line['contactName']);
		self::assertSame(expected: '2026-10-05T07:30:00+02:00', actual: $line['employerNotifiedAt']);
		self::assertSame(expected: self::TASK, actual: $line['employerTask']);
		self::assertArrayNotHasKey(key: 'birthDate', array: $line, message: 'the list never carries the birth date');
		self::assertSame(
			expected: 'Van Youssef El Amrani (Jansen Installatietechniek) ontbreekt de geboortedatum.',
			actual: (new ExamRegistrationLines())->missingSentence(line: $line)
		);

		// A later refresh keeps the moment the employer was first told.
		$again = (new ExamRegistrationLines())->line(
			enrolment: ['id' => 'e-youssef', 'openTask' => self::TASK],
			profile: self::YOUSSEF,
			organisation: self::JANSEN,
			state: [
				'required' => ExamRegistrationLines::DEFAULT_REQUIRED,
				'previous' => array_merge($line, ['result' => 'pass']),
				'now' => '2026-10-06T10:00:00+02:00'
			]
		);
		self::assertSame(expected: '2026-10-05T07:30:00+02:00', actual: $again['employerNotifiedAt']);
		self::assertSame(expected: 'pass', actual: $again['result']);
	}//end testAMissingBirthDateNamesTheFieldAndTellsTheEmployerOnce()

	/**
	 * A complete line clears the task and without a portal task nobody is told.
	 *
	 * @return void
	 */
	public function testACompleteLineClearsTheTaskAndWithoutAPortalTaskNobodyIsTold(): void {
		$complete = (new ExamRegistrationLines())->line(
			enrolment: ['id' => 'e-1', 'openTask' => self::TASK],
			profile: array_merge(self::YOUSSEF, ['birthDate' => '1998-03-04']),
			organisation: self::JANSEN,
			state: [
				'required' => ExamRegistrationLines::DEFAULT_REQUIRED,
				'previous' => ['employerNotifiedAt' => '2026-10-05T07:30:00+02:00'],
				'now' => '2026-10-06T10:00:00+02:00'
			]
		);
		self::assertTrue(condition: $complete['complete']);
		self::assertSame(expected: [], actual: $complete['missing']);
		self::assertNull(actual: $complete['employerNotifiedAt']);
		self::assertNull(actual: $complete['employerTask'], message: 'a stale task is not repeated');

		$alone = (new ExamRegistrationLines())->line(
			enrolment: ['id' => 'e-2', 'learnerName' => 'Kim'],
			profile: ['givenName' => 'Kim'],
			organisation: [],
			state: ['required' => ['fullName', 'birthDate'], 'previous' => null, 'now' => '2026-10-06T10:00:00+02:00']
		);
		self::assertSame(expected: ['fullName', 'birthDate'], actual: $alone['missing'], message: 'a name needs both parts');
		self::assertSame(expected: 'Kim', actual: $alone['learnerName']);
		self::assertNull(actual: $alone['employerNotifiedAt'], message: 'no task on the portal, so nobody was told');
		self::assertNull(actual: $alone['employerTask']);
		self::assertSame(
			expected: 'Van Kim ontbreekt de naam en de geboortedatum.',
			actual: (new ExamRegistrationLines())->missingSentence(line: $alone)
		);
	}//end testACompleteLineClearsTheTaskAndWithoutAPortalTaskNobodyIsTold()

	/**
	 * The summary counts the complete participants.
	 *
	 * @return void
	 */
	public function testTheSummaryCountsTheCompleteParticipants(): void {
		$lines = array_fill(0, 10, ['complete' => true]);
		$lines[] = ['complete' => false];

		self::assertSame(expected: '10 van de 11 deelnemers zijn compleet.', actual: (new ExamRegistrationLines())->summary(lines: $lines));
		self::assertSame(expected: 10, actual: (new ExamRegistrationLines())->completeCount(lines: $lines));
		self::assertSame(
			expected: '1 van de 2 deelnemers is compleet.',
			actual: (new ExamRegistrationLines())->summary(lines: [['complete' => true], ['complete' => false]])
		);
		self::assertSame(
			expected: 'Alle 2 deelnemers zijn compleet.',
			actual: (new ExamRegistrationLines())->summary(lines: [['complete' => true], ['complete' => true]])
		);
		self::assertSame(
			expected: 'De deelnemer is nog niet compleet.',
			actual: (new ExamRegistrationLines())->summary(lines: [['complete' => false]])
		);
	}//end testTheSummaryCountsTheCompleteParticipants()

	/**
	 * The notice says when the employer was told.
	 *
	 * @return void
	 */
	public function testTheNoticeSaysWhenTheEmployerWasTold(): void {
		self::assertSame(
			expected: 'De werkgever kreeg vanochtend bericht.',
			actual: (new ExamRegistrationDates())->notice(notifiedAt: '2026-10-05T07:30:00+02:00', now: '2026-10-05T09:00:00+02:00')
		);
		self::assertSame(
			expected: 'De werkgever kreeg vanmiddag bericht.',
			actual: (new ExamRegistrationDates())->notice(notifiedAt: '2026-10-05T12:30:00Z', now: '2026-10-05T16:00:00+02:00')
		);
		self::assertSame(
			expected: 'De werkgever kreeg gisteren bericht.',
			actual: (new ExamRegistrationDates())->notice(notifiedAt: '2026-10-04T20:00:00+02:00', now: '2026-10-05T09:00:00+02:00')
		);
		self::assertSame(
			expected: 'De werkgever kreeg op 1 oktober bericht.',
			actual: (new ExamRegistrationDates())->notice(notifiedAt: '2026-10-01T09:00:00+02:00', now: '2026-10-05T09:00:00+02:00')
		);
		self::assertNull(actual: (new ExamRegistrationDates())->notice(notifiedAt: null, now: '2026-10-05T09:00:00+02:00'));
	}//end testTheNoticeSaysWhenTheEmployerWasTold()

	/**
	 * The closing line names the exam day and the moment.
	 *
	 * @return void
	 */
	public function testTheClosingLineNamesTheExamDayAndTheMoment(): void {
		self::assertSame(
			expected: 'De aanmelding voor het examen van donderdag sluit woensdag om 12.00 uur.',
			actual: (new ExamRegistrationDates())->closingSentence(examDate: '2026-10-08', closesAt: '2026-10-07T10:00:00Z')
		);
		self::assertSame(
			expected: 'De aanmelding voor het examen sluit woensdag om 12.00 uur.',
			actual: (new ExamRegistrationDates())->closingSentence(examDate: null, closesAt: '2026-10-07T12:00:00+02:00')
		);
		self::assertNull(actual: (new ExamRegistrationDates())->closingSentence(examDate: '2026-10-08', closesAt: 'not a moment'));
	}//end testTheClosingLineNamesTheExamDayAndTheMoment()

	/**
	 * A list is due from its moment.
	 *
	 * @return void
	 */
	public function testAListIsDueFromItsMoment(): void {
		self::assertFalse(condition: (new ExamRegistrationDates())->isDue(closesAt: '2026-10-07T12:00:00+02:00', now: '2026-10-07T11:59:00+02:00'));
		self::assertTrue(condition: (new ExamRegistrationDates())->isDue(closesAt: '2026-10-07T12:00:00+02:00', now: '2026-10-07T10:00:00Z'));
		self::assertFalse(condition: (new ExamRegistrationDates())->isDue(closesAt: null, now: '2026-10-07T12:00:00+02:00'));
	}//end testAListIsDueFromItsMoment()

	/**
	 * The export follows the bodys columns and guards its cells.
	 *
	 * @return void
	 */
	public function testTheExportFollowsTheBodysColumnsAndGuardsItsCells(): void {
		$csv = (new ExamRegistrationLines())->csv(
			required: ['birthDate', 'fullName', 'placeOfBirth'],
			lines: [['learnerRef' => 'p-1', 'learnerName' => 'Tom Verbeek'], ['learnerRef' => 'p-2', 'learnerName' => 'Eva']],
			profiles: [
				'p-1' => [
					'givenName' => 'Tom',
					'familyName' => 'Verbeek',
					'birthDate' => '1990-01-02',
					'placeOfBirth' => "'s-Hertogenbosch; Noord-Brabant"
				],
				'p-2' => ['givenName' => '=HYPERLINK("x")', 'familyName' => 'Smit', 'birthDate' => '', 'placeOfBirth' => 'Ede'],
			]
		);

		self::assertSame(
			expected: "Geboortedatum;Naam;Geboorteplaats\r\n"
			. "1990-01-02;Tom Verbeek;\"'s-Hertogenbosch; Noord-Brabant\"\r\n"
			. ";\"\t=HYPERLINK(\"\"x\"\") Smit\";Ede\r\n",
			actual: $csv
		);
	}//end testTheExportFollowsTheBodysColumnsAndGuardsItsCells()
}//end class
