<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "Alle geldige certificaten downloaden": one ZIP with the rendered PDF of
 * every issued, unexpired certificate of the company's people, kept on the
 * company's own row.
 *
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-an-employer-downloads-every-valid-certificate-at-once
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Portal;

use OCA\Learniq\Kernel\Contracts\CertificatePdfFileInterface;
use OCA\Learniq\Service\Portal\EmployerCertificatesArchive;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\FileUpload;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use ZipArchive;

/**
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-an-employer-downloads-every-valid-certificate-at-once
 */
#[CoversClass(EmployerCertificatesArchive::class)]
#[UsesClass(PortalOutcome::class)]
class EmployerCertificatesArchiveTest extends TestCase {

	/**
	 * 25 November 2026, noon UTC.
	 */
	private const NOW = 1795608000;

	private array $kept = [];

	private array $filters = [];

	public function testLindaDownloadsHerFourValidCertificates(): void {
		$rows = [
			self::certificate(id: 'c1', learner: 'Tom Bakker', course: 'F-gassen', expires: '2028-03-01', pdf: '11'),
			self::certificate(id: 'c2', learner: 'Sara de Vries', course: 'F-gassen', expires: '2026-11-25', pdf: '12'),
			self::certificate(id: 'c3', learner: 'Tom Bakker', course: 'Warmtepomp installeren', expires: null, pdf: '13'),
			self::certificate(id: 'c4', learner: 'Ali Yilmaz', course: 'F-gassen', expires: '2027-01-10', pdf: '14', status: 'expiring'),
			self::certificate(id: 'c5', learner: 'Piet Jansen', course: 'F-gassen', expires: '2026-11-24', pdf: '15'),
		];

		$outcome = $this->archive(rows: $rows)->make(organisationRef: ' co-a ');

		self::assertSame(expected: 201, actual: $outcome->status);
		self::assertSame(expected: ['status' => 'created', 'title' => 'Valid certificates, 2026-11-25', 'count' => 4, 'missing' => 0], actual: $outcome->body);
		self::assertCount(expectedCount: 1, haystack: $this->kept);
		self::assertSame(expected: 'co-a', actual: $this->kept[0]['object']);
		$upload = $this->kept[0]['upload'];
		self::assertSame(expected: 'Valid certificates, 2026-11-25.zip', actual: $upload->fileName);
		self::assertSame(expected: [EmployerCertificatesArchive::TAG], actual: $upload->tags);
		self::assertFalse(condition: $upload->share);
		self::assertSame(
			expected: [
				'Ali Yilmaz - F-gassen.pdf' => '%PDF-14',
				'Sara de Vries - F-gassen.pdf' => '%PDF-12',
				'Tom Bakker - F-gassen.pdf' => '%PDF-11',
				'Tom Bakker - Warmtepomp installeren.pdf' => '%PDF-13',
			],
			actual: self::entries(zip: (string)$upload->content)
		);
		self::assertSame(expected: 'co-a', actual: $this->filters['organisationRef']);
		self::assertSame(expected: ['issued', 'certificate'], actual: [$this->filters['lifecycle'], $this->filters['kind']]);
	}//end testLindaDownloadsHerFourValidCertificates()

	public function testOnlyHerCompanysIssuedCertificatesGoIn(): void {
		$rows = [
			self::certificate(id: 'c1', learner: 'Tom', course: 'F-gassen', expires: null, pdf: '11'),
			self::certificate(id: 'c2', learner: 'Eva', course: 'F-gassen', expires: null, pdf: '12', company: 'co-b'),
			self::certificate(id: 'c3', learner: 'Bram', course: 'F-gassen', expires: null, pdf: '13', lifecycle: 'revoked'),
			self::certificate(id: 'c4', learner: 'Lisa', course: 'Dag 1', expires: null, pdf: '14', kind: 'participation'),
			self::certificate(id: 'c5', learner: 'Joost', course: 'F-gassen', expires: '2030-01-01', pdf: '15', status: 'expired'),
		];

		$outcome = $this->archive(rows: $rows)->make(organisationRef: 'co-a');

		self::assertSame(expected: 1, actual: $outcome->body['count']);
		self::assertSame(expected: ['Tom - F-gassen.pdf'], actual: array_keys(self::entries(zip: (string)$this->kept[0]['upload']->content)));
	}//end testOnlyHerCompanysIssuedCertificatesGoIn()

	public function testACertificateWithoutARenderedPdfIsCountedAsMissing(): void {
		$rows = [
			self::certificate(id: 'c1', learner: 'Tom', course: 'F-gassen', expires: null, pdf: '11'),
			self::certificate(id: 'c2', learner: 'Sara', course: 'F-gassen', expires: null, pdf: '12', render: 'pending'),
			self::certificate(id: 'c3', learner: 'Ali', course: 'F-gassen', expires: null, pdf: '404'),
		];

		$outcome = $this->archive(rows: $rows)->make(organisationRef: 'co-a');

		self::assertSame(expected: [201, 1, 2], actual: [$outcome->status, $outcome->body['count'], $outcome->body['missing']]);
	}//end testACertificateWithoutARenderedPdfIsCountedAsMissing()

	public function testWithoutAValidCertificateNothingIsKept(): void {
		$rows = [self::certificate(id: 'c1', learner: 'Piet', course: 'F-gassen', expires: '2026-01-01', pdf: '11')];

		$outcome = $this->archive(rows: $rows)->make(organisationRef: 'co-a');

		self::assertSame(expected: [409, 'no-valid-certificates'], actual: [$outcome->status, $outcome->reason]);
		self::assertSame(expected: [], actual: $this->kept);

		$blank = $this->archive(rows: $rows)->make(organisationRef: ' ');
		self::assertSame(expected: 409, actual: $blank->status);
		self::assertSame(expected: [], actual: $this->filters, message: 'a blank company reads nothing');
	}//end testWithoutAValidCertificateNothingIsKept()

	public function testAnUnknownCompanyOrAFailedSaveKeepsNothing(): void {
		$rows = [self::certificate(id: 'c1', learner: 'Tom', course: 'F-gassen', expires: null, pdf: '11')];

		$unknown = $this->archive(rows: $rows, company: false)->make(organisationRef: 'co-a');
		self::assertSame(expected: [404, 'unknown-company'], actual: [$unknown->status, $unknown->reason]);

		$failed = $this->archive(rows: $rows, save: new RuntimeException('disk full'))->make(organisationRef: 'co-a');
		self::assertSame(expected: [502, 'archive-failed'], actual: [$failed->status, $failed->reason]);

		$unread = $this->archive(rows: new RuntimeException('store down'))->make(organisationRef: 'co-a');
		self::assertSame(expected: 409, actual: $unread->status);
	}//end testAnUnknownCompanyOrAFailedSaveKeepsNothing()

	public function testEntryNamesAreSafeAndUnique(): void {
		$taken = ['Tom - F-gassen.pdf'];

		self::assertSame(expected: 'Tom - F-gassen (2).pdf', actual: EmployerCertificatesArchive::entryName(certificate: ['learnerName' => 'Tom', 'courseName' => 'F-gassen'], taken: $taken));
		self::assertSame(expected: 'Tom - Koel-techniek- 2.pdf', actual: EmployerCertificatesArchive::entryName(certificate: ['learnerName' => 'Tom', 'courseName' => 'Koel/techniek: 2'], taken: []));
		self::assertSame(expected: 'Certificate c9.pdf', actual: EmployerCertificatesArchive::entryName(certificate: ['id' => 'c9'], taken: []));
	}//end testEntryNamesAreSafeAndUnique()

	/**
	 * The archive over the given store answer.
	 *
	 * @param array|RuntimeException $rows    The certificates, or a store failure.
	 * @param bool                   $company Whether the company row exists.
	 * @param RuntimeException|null  $save    A failure on keeping the file.
	 *
	 * @return EmployerCertificatesArchive
	 */
	private function archive(array|RuntimeException $rows, bool $company=true, ?RuntimeException $save=null): EmployerCertificatesArchive {
		$this->kept    = [];
		$this->filters = [];

		$store = $this->createMock(originalClassName: ObjectService::class);
		$store->method('findAll')->willReturnCallback(
			function (array $config) use ($rows): array {
				$this->filters = $config['filters'];
				if ($rows instanceof RuntimeException) {
					throw $rows;
				}

				return $rows;
			}
		);
		$store->method('find')->willReturnCallback(
			function (string|int $id) use ($company): ?ObjectEntity {
				if ($company === false) {
					return null;
				}

				$entity = $this->createMock(originalClassName: ObjectEntity::class);
				$entity->method('getUuid')->willReturn((string)$id);
				return $entity;
			}
		);

		$files = $this->createMock(originalClassName: FileService::class);
		$files->method('saveFile')->willReturnCallback(
			function (ObjectEntity $objectEntity, FileUpload $upload) use ($save): File {
				if ($save !== null) {
					throw $save;
				}

				$this->kept[] = ['object' => $objectEntity->getUuid(), 'upload' => $upload];
				return $this->createMock(originalClassName: File::class);
			}
		);

		$pdfs = $this->createMock(originalClassName: CertificatePdfFileInterface::class);
		$pdfs->method('read')->willReturnCallback(static fn (string $documentRef): ?string => $documentRef === '404' ? null : '%PDF-'.$documentRef);

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params=[]): string => vsprintf($text, $params));
		$factory = $this->createMock(originalClassName: IFactory::class);
		$factory->method('get')->willReturn($l10n);

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new EmployerCertificatesArchive(
			objectService: $store,
			fileService: $files,
			pdfs: $pdfs,
			l10nFactory: $factory,
			time: $time,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end archive()

	/**
	 * A certificate row as the store answers it.
	 *
	 * @return array<string, mixed>
	 */
	private static function certificate(
		string $id,
		string $learner,
		string $course,
		?string $expires,
		string $pdf,
		string $company='co-a',
		string $lifecycle='issued',
		string $kind='certificate',
		string $status='valid',
		string $render='rendered'
	): array {
		return [
			'id' => $id,
			'organisationRef' => $company,
			'learnerName' => $learner,
			'courseName' => $course,
			'kind' => $kind,
			'lifecycle' => $lifecycle,
			'expiresAt' => $expires,
			'expiryStatus' => $status,
			'pdfRenderStatus' => $render,
			'pdfDocumentRef' => $pdf,
		];
	}//end certificate()

	/**
	 * The files in a ZIP, by name, sorted.
	 *
	 * @param string $zip The ZIP bytes.
	 *
	 * @return array<string, string>
	 */
	private static function entries(string $zip): array {
		$path = tempnam(sys_get_temp_dir(), 'zip-test-');
		file_put_contents($path, $zip);
		$archive = new ZipArchive();
		$archive->open($path);
		$entries = [];
		for ($i = 0; $i < $archive->numFiles; $i++) {
			$name           = (string)$archive->getNameIndex($i);
			$entries[$name] = (string)$archive->getFromName($name);
		}

		$archive->close();
		unlink($path);
		ksort($entries);
		return $entries;
	}//end entries()
}//end class
