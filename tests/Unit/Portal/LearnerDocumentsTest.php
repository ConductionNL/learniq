<?php

/**
 * What leaves the server on the learner's "Documenten": only files on her
 * own published report cards, her approved subject choice, the agreements of
 * her own placements (with their signing state) and the issued certificates
 * of the enrolment, whatever the store answers.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-each-school-audience-offers-its-own-documents-grouped-per-record
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\DocumentRow;
use OCA\Learniq\Portal\LearnerDocuments;
use OCA\Learniq\Portal\PortalFileEntries;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\File;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for LearnerDocuments.
 */
#[CoversClass(LearnerDocuments::class)]
#[UsesClass(PortalFileEntries::class)]
#[UsesClass(DocumentRow::class)]
class LearnerDocumentsTest extends TestCase {

	/**
	 * The schemas read, in order.
	 *
	 * @var array<int, string>
	 */
	private array $schemas = [];

	/**
	 * A reader over these rows per schema, whose files are listed per row id.
	 *
	 * @param array<string, array<int, array<string, mixed>>|RuntimeException> $rows  What the store answers per schema, or what it throws.
	 * @param array<string, array<int, File>>                                  $files The attached files per row id.
	 *
	 * @return LearnerDocuments
	 */
	private function reader(array $rows, array $files): LearnerDocuments {
		$store = $this->createMock(ObjectService::class);
		$store->method('findAll')->willReturnCallback(
			function (array $config) use ($rows): array {
				$schema          = $config['filters']['schema'];
				$this->schemas[] = $schema;
				$answer          = ($rows[$schema] ?? []);
				if ($answer instanceof RuntimeException) {
					throw $answer;
				}

				return $answer;
			}
		);
		$fileService = $this->createMock(FileService::class);
		$fileService->method('getFiles')->willReturnCallback(static fn (string $object): array => ($files[$object] ?? []));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'nl:'.$text);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->with('learniq')->willReturn($l10n);
		$logger = $this->createMock(LoggerInterface::class);

		return new LearnerDocuments($store, new PortalFileEntries($fileService, $logger), $factory, $logger);
	}//end reader()

	/**
	 * An attached PDF.
	 *
	 * @param int    $id   The file id.
	 * @param string $name The file name.
	 *
	 * @return File
	 */
	private function file(int $id, string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		$file->method('getMimetype')->willReturn('application/pdf');
		$file->method('getSize')->willReturn(2048);
		$file->method('getMTime')->willReturn(time());
		return $file;
	}//end file()

	/**
	 * Noor's published report, approved choice and issued certificate reach
	 * her, each under its group; a report in review, a draft choice, a
	 * revoked certificate and another pupil's papers do not.
	 *
	 * @return void
	 */
	public function testOnlyHerOwnSettledDocumentsAreOfferedPerGroup(): void {
		$reader = $this->reader(
			[
				'enrolment' => [['id' => 'enr-1', 'learnerRef' => 'noor']],
				'report-card' => [
					['id' => 'rc-1', 'learnerRef' => 'noor', 'lifecycle' => 'published-to-parents', 'periodName' => 'Rapport 2, 3 havo'],
					['id' => 'rc-2', 'learnerRef' => 'noor', 'lifecycle' => 'finalised', 'periodName' => 'Rapport 3'],
					['id' => 'rc-3', 'learnerRef' => 'milan', 'lifecycle' => 'published-to-parents', 'periodName' => 'Rapport 1'],
				],
				'subject-choice' => [
					['id' => 'sc-1', 'learnerRef' => 'noor', 'lifecycle' => 'approved'],
					['id' => 'sc-2', 'learnerRef' => 'noor', 'lifecycle' => 'draft'],
				],
				'credential' => [
					['id' => 'cr-1', 'enrolmentId' => 'enr-1', 'lifecycle' => 'issued'],
					['id' => 'cr-2', 'enrolmentId' => 'enr-1', 'lifecycle' => 'revoked'],
					['id' => 'cr-3', 'enrolmentId' => 'enr-9', 'lifecycle' => 'issued'],
				],
			],
			[
				'rc-1' => [$this->file(id: 1, name: 'rapport.pdf')],
				'rc-2' => [$this->file(id: 2, name: 'x.pdf')],
				'rc-3' => [$this->file(id: 3, name: 'y.pdf')],
				'sc-1' => [$this->file(id: 4, name: 'Profielkeuze.pdf')],
				'sc-2' => [$this->file(id: 5, name: 'z.pdf')],
				'cr-1' => [$this->file(id: 6, name: 'Certificaat.pdf')],
				'cr-2' => [$this->file(id: 7, name: 'r.pdf')],
				'cr-3' => [$this->file(id: 8, name: 'o.pdf')],
			]
		);

		$entries = $reader->forEnrolment('enr-1');

		self::assertSame(['1', '4', '6'], array_column($entries, 'id'));
		self::assertSame(['nl:Reports', 'nl:Choices and statements', 'nl:Certificates'], array_column($entries, 'group'));
		self::assertSame(['Rapport 2, 3 havo', 'Profielkeuze', 'Certificaat'], array_column($entries, 'title'));
		self::assertSame('PDF, 2 KB', $entries[0]['meta']);
		self::assertSame(['register' => 'learniq', 'schema' => 'credential', 'id' => 'cr-1', 'fileId' => '6'], $entries[2]['file']);
	}//end testOnlyHerOwnSettledDocumentsAreOfferedPerGroup()

	/**
	 * Her proof of enrolment, kept on the enrolment, is listed with her
	 * statements under its own title (school-documents-on-the-portal T4).
	 *
	 * @return void
	 */
	public function testTheProofKeptOnTheEnrolmentIsAStatement(): void {
		$reader = $this->reader(
			['enrolment' => [['id' => 'enr-1', 'learnerRef' => 'noor']]],
			['enr-1' => [$this->file(id: 9, name: 'Bewijs van inschrijving, schooljaar 2026-2027.pdf')]]
		);

		$entries = $reader->forEnrolment('enr-1');

		self::assertCount(1, $entries);
		self::assertSame('Bewijs van inschrijving, schooljaar 2026-2027', $entries[0]['title']);
		self::assertSame('nl:Choices and statements', $entries[0]['group']);
		self::assertSame(['register' => 'learniq', 'schema' => 'enrolment', 'id' => 'enr-1', 'fileId' => '9'], $entries[0]['file']);
	}//end testTheProofKeptOnTheEnrolmentIsAStatement()

	/**
	 * Milan's agreement, signed by him and the school, reads "Wacht op
	 * leerbedrijf" with the company in its meta; a draft agreement and an
	 * agreement of another placement stay inside.
	 *
	 * @return void
	 */
	public function testTheAgreementCarriesItsSigningState(): void {
		$reader = $this->reader(
			[
				'enrolment' => [['id' => 'enr-2', 'learnerRef' => 'milan']],
				'bpv-placement' => [['id' => 'pl-1', 'learnerRef' => 'milan', 'trainingCompanyName' => 'Bakker Techniek BV']],
				'praktijkovereenkomst' => [
					['id' => 'pok-1', 'bpvPlacementId' => 'pl-1', 'lifecycle' => 'pending-signatures'],
					['id' => 'pok-2', 'bpvPlacementId' => 'pl-1', 'lifecycle' => 'draft'],
					['id' => 'pok-3', 'bpvPlacementId' => 'pl-9', 'lifecycle' => 'active'],
				],
				'pok-signature' => [
					['subjectId' => 'pok-1', 'signerRole' => 'student'],
					['subjectId' => 'pok-1', 'signerRole' => 'school'],
				],
			],
			[
				'pok-1' => [$this->file(id: 11, name: 'Praktijkovereenkomst.pdf')],
				'pok-2' => [$this->file(id: 12, name: 'a.pdf')],
				'pok-3' => [$this->file(id: 13, name: 'b.pdf')],
			]
		);

		$entries = $reader->forEnrolment('enr-2');

		self::assertCount(1, $entries);
		self::assertSame('11', $entries[0]['id']);
		self::assertSame('nl:Contracts', $entries[0]['group']);
		self::assertSame('nl:Waiting for the training company', $entries[0]['status']);
		self::assertSame('Bakker Techniek BV, PDF, 2 KB', $entries[0]['meta']);
	}//end testTheAgreementCarriesItsSigningState()

	/**
	 * The signing state names whom it waits on first, and reads signed by
	 * all three (or everyone, when a parent signs too) once all have.
	 *
	 * @return void
	 */
	public function testTheSigningStateInWords(): void {
		$reader = $this->reader([], []);
		$l10n   = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$signed = static fn (string ...$roles): array => array_map(static fn (string $role): array => ['signerRole' => $role], $roles);

		self::assertSame('Signed by all three', $reader->signingState([], $signed('student', 'school', 'praktijkopleider'), $l10n));
		self::assertSame('Waiting for your signature', $reader->signingState([], $signed('school'), $l10n));
		self::assertSame('Waiting for the school', $reader->signingState([], $signed('student', 'praktijkopleider'), $l10n));
		self::assertSame('Waiting for your parent', $reader->signingState(['parentSignatureRequired' => true], $signed('student', 'school', 'praktijkopleider'), $l10n));
		self::assertSame('Signed by everyone', $reader->signingState(['parentSignatureRequired' => true], $signed('student', 'school', 'praktijkopleider', 'parent'), $l10n));
		self::assertSame('Signed by all three', $reader->signingState(['isFullySigned' => true], [], $l10n));
	}//end testTheSigningStateInWords()

	/**
	 * An unknown enrolment, one without a learner, and a store that fails
	 * all offer nothing.
	 *
	 * @return void
	 */
	public function testNothingWithoutAReadableEnrolment(): void {
		self::assertSame([], $this->reader(['enrolment' => [['id' => 'enr-x', 'learnerRef' => 'noor']]], [])->forEnrolment('enr-1'));
		self::assertSame([], $this->reader(['enrolment' => [['id' => 'enr-1', 'learnerRef' => '']]], [])->forEnrolment('enr-1'));
		self::assertSame([], $this->reader(['enrolment' => new RuntimeException('down')], [])->forEnrolment('enr-1'));
		self::assertSame([], $this->reader([], [])->forEnrolment(''));
	}//end testNothingWithoutAReadableEnrolment()
}//end class
