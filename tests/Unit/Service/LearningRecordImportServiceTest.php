<?php

/**
 * Unit tests for LearningRecordImportService.
 *
 * Verifies a recognised own-format bundle parses with verified/unverifiable
 * per key presence, a recognised bare ELM set parses with `sourceSchema:
 * null` entries, and an unparseable file sets `errorMessage` and blocks the
 * transition. `LearningRecordImportService` takes no `ObjectService`
 * dependency at all — it is structurally incapable of writing to any other
 * schema (a stronger guarantee than a mock-and-assert-zero-calls test).
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
 * @spec openspec/changes/archive/2026-07-16-portable-learning-record/tasks.md#task-4-4
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\LearningRecordExportSigningService;
use OCA\Learniq\Service\LearningRecordImportService;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for LearningRecordImportService::check() (the `parse` transition guard).
 */
class LearningRecordImportServiceTest extends TestCase {

	/**
	 * LearningRecordExportSigningService mock.
	 *
	 * @var LearningRecordExportSigningService&MockObject
	 */
	private LearningRecordExportSigningService&MockObject $signingService;

	/**
	 * Build a service under test whose IRootFolder mock serves the given raw bytes.
	 *
	 * @param string $rawContent Raw bytes `readSourceBytes()` should return.
	 *
	 * @return LearningRecordImportService
	 */
	private function makeService(string $rawContent): LearningRecordImportService {
		$this->signingService = $this->createMock(LearningRecordExportSigningService::class);

		/** @var File&MockObject $file */
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn($rawContent);

		/** @var Folder&MockObject $folder */
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willReturn($file);

		/** @var IRootFolder&MockObject $rootFolder */
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($folder);

		return new LearningRecordImportService(
			signingService: $this->signingService,
			rootFolder: $rootFolder,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end makeService()

	/**
	 * A base valid LearningRecordImport as OpenRegister saves it on `parse`
	 * (lifecycle already at its target), overridden per test.
	 *
	 * @param array<string,mixed> $overrides Object field overrides.
	 *
	 * @return array<string,mixed>
	 */
	private function baseObject(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'import-1',
				'lifecycle' => 'parsed',
				'sourceRef' => '/Scholiq/tenant-1/learning-record-imports/abc.json',
				'uploadedBy' => 'coordinator-1',
				'sourceFormat' => 'scholiq-learning-record',
				'tenant_id' => 'tenant-1',
			],
			$overrides
		);
	}//end baseObject()

	/**
	 * A recognised own-format bundle whose issuerDid matches the importing
	 * tenant's own key AND whose signature verifies parses `verified`.
	 *
	 * @return void
	 */
	public function testRecognisedOwnFormatBundleParsesVerifiedWhenKeyMatches(): void {
		$bundle = [
			'bundleType' => 'scholiq-learning-record',
			'issuerDid' => 'did:web:learniq:tenant-1:fingerprint',
			'elm' => [['kind' => 'diploma']],
			'scholiqNative' => [
				'credentials' => [['id' => 'cred-1', 'kind' => 'diploma']],
				'finalGrades' => [['id' => 'fg-1']],
			],
			'proof' => ['jws' => 'header..signature'],
		];

		$service = $this->makeService(rawContent: (string)json_encode($bundle));
		$this->signingService->method('resolveIssuerDid')->willReturn('did:web:learniq:tenant-1:fingerprint');
		$this->signingService->method('verify')->willReturn(true);

		self::assertTrue($service->check($this->baseObject(), 'parse', 'coordinator-1')->isAllowed());
		$saved = $service->parse(import: $this->baseObject());

		self::assertSame('verified', $saved['verificationStatus']);
		self::assertNull($saved['errorMessage']);

		$entries = $saved['entries'];
		self::assertNotEmpty($entries);

		$credEntry = current(array_filter($entries, static fn (array $e) => $e['sourceSchema'] === 'credential'));
		self::assertNotFalse($credEntry);
		self::assertSame('recognized', $credEntry['outcome']);
	}//end testRecognisedOwnFormatBundleParsesVerifiedWhenKeyMatches()

	/**
	 * A recognised own-format bundle from a DIFFERENT (or unresolvable)
	 * issuer parses `unverifiable` — the expected, non-error case for a
	 * genuinely foreign system.
	 *
	 * @return void
	 */
	public function testRecognisedOwnFormatBundleParsesUnverifiableForForeignIssuer(): void {
		$bundle = [
			'bundleType' => 'scholiq-learning-record',
			'issuerDid' => 'did:web:learniq:some-other-tenant:xyz',
			'elm' => [],
			'scholiqNative' => ['credentials' => []],
			'proof' => ['jws' => 'header..signature'],
		];

		$service = $this->makeService(rawContent: (string)json_encode($bundle));
		$this->signingService->method('resolveIssuerDid')->willReturn('did:web:learniq:tenant-1:fingerprint');
		$this->signingService->expects($this->never())->method('verify');

		$saved = $service->parse(import: $this->baseObject());

		self::assertSame('unverifiable', $saved['verificationStatus']);
	}//end testRecognisedOwnFormatBundleParsesUnverifiableForForeignIssuer()

	/**
	 * A bundle claiming to be from THIS tenant but whose signature fails
	 * verification parses `invalid` (tamper flag).
	 *
	 * @return void
	 */
	public function testTamperedOwnFormatBundleParsesInvalid(): void {
		$bundle = [
			'bundleType' => 'scholiq-learning-record',
			'issuerDid' => 'did:web:learniq:tenant-1:fingerprint',
			'elm' => [],
			'scholiqNative' => ['credentials' => []],
			'proof' => ['jws' => 'header..tampered-signature'],
		];

		$service = $this->makeService(rawContent: (string)json_encode($bundle));
		$this->signingService->method('resolveIssuerDid')->willReturn('did:web:learniq:tenant-1:fingerprint');
		$this->signingService->method('verify')->willReturn(false);

		$saved = $service->parse(import: $this->baseObject());

		self::assertSame('invalid', $saved['verificationStatus']);
	}//end testTamperedOwnFormatBundleParsesInvalid()

	/**
	 * A recognised bare ELM/Europass credential set parses with
	 * `sourceSchema: null` entries and `unverifiable` (no generic
	 * third-party ELM verifier is built).
	 *
	 * @return void
	 */
	public function testRecognisedBareElmSetParsesWithNullSourceSchema(): void {
		$elmSet = [
			'credentials' => [
				['credentialSubject' => ['achievement' => ['name' => 'Foreign Diploma']]],
				['name' => 'Another Credential'],
			],
		];

		$service = $this->makeService(rawContent: (string)json_encode($elmSet));

		$saved = $service->parse(import: $this->baseObject(['sourceFormat' => 'elm-europass']));

		self::assertSame('unverifiable', $saved['verificationStatus']);

		$entries = $saved['entries'];
		self::assertCount(2, $entries);
		foreach ($entries as $entry) {
			self::assertNull($entry['sourceSchema']);
			self::assertSame('recognized', $entry['outcome']);
		}

		self::assertSame('Foreign Diploma', $entries[0]['sourceTitle']);
	}//end testRecognisedBareElmSetParsesWithNullSourceSchema()

	/**
	 * Unparseable JSON is refused by the guard with the reason, and parse()
	 * throws rather than saving partial entries.
	 *
	 * @return void
	 */
	public function testUnparseableFileSetsErrorMessageAndBlocks(): void {
		$service = $this->makeService(rawContent: '{not valid json');

		$verdict = $service->check($this->baseObject(), 'parse', 'coordinator-1');

		self::assertInstanceOf(LifecycleGuardInterface::class, $service);
		self::assertFalse($verdict->isAllowed());
		self::assertStringContainsString('not valid JSON', (string)$verdict->getMessage());

		$this->expectException(RuntimeException::class);
		$service->parse(import: $this->baseObject());
	}//end testUnparseableFileSetsErrorMessageAndBlocks()

	/**
	 * An unrecognised sourceFormat is refused by the guard.
	 *
	 * @return void
	 */
	public function testUnrecognisedSourceFormatBlocks(): void {
		$service = $this->makeService(rawContent: (string)json_encode(['a' => 1]));

		$verdict = $service->check($this->baseObject(['sourceFormat' => 'some-other-format']), 'parse', 'coordinator-1');

		self::assertFalse($verdict->isAllowed());
		self::assertStringContainsString('some-other-format', (string)$verdict->getMessage());
	}//end testUnrecognisedSourceFormatBlocks()
	/**
	 * The full coverage report of an own-format bundle: native rows per
	 * collection in the collection order, then each ELM credential.
	 *
	 * @return void
	 */
	public function testOwnFormatReportListsEveryRowInOrder(): void {
		$bundle = [
			'issuerDid' => 'did:web:learniq:tenant-1:fingerprint',
			'elm' => [['kind' => 'diploma'], 'not-an-object', ['other' => 1]],
			'scholiqNative' => [
				'credentials' => [['kind' => 'certificate'], 'not-a-row'],
				'enrolments' => [['id' => 'e-1']],
				'portfolios' => [['title' => 'Mijn portfolio'], []],
				'finalGrades' => 'not-a-list',
				'bpvPlacements' => [['trainingCompanyName' => 'Van Dam']],
				'lessonCompletions' => [['courseId' => 'c-9']],
			],
			'proof' => ['jws' => 'header..signature'],
		];

		$service = $this->makeService(rawContent: (string)json_encode($bundle));
		$this->signingService->method('resolveIssuerDid')->with('tenant-1')->willReturn('did:web:learniq:tenant-1:fingerprint');
		$this->signingService->expects($this->once())->method('verify')->with('header..signature', $bundle, 'tenant-1')->willReturn(true);

		$saved = $service->parse(import: $this->baseObject(['entries' => 'old', 'errorMessage' => 'old']));

		$entry = static fn (?string $schema, string $title): array => ['sourceSchema' => $schema, 'sourceTitle' => $title, 'outcome' => 'recognized', 'reason' => null];
		self::assertSame(
			array_merge(
				$this->baseObject(),
				[
					'entries' => [
						$entry('enrolment', 'enrolment'),
						$entry('credential', 'Credential (certificate)'),
						$entry('credential', 'Credential (unknown)'),
						$entry('portfolio', 'Mijn portfolio'),
						$entry('portfolio', 'portfolio'),
						$entry('bpv-placement', 'Van Dam'),
						$entry('lesson-completion', 'Lesson completions — course c-9'),
						$entry('credential', 'Credential (diploma)'),
						$entry('credential', 'Credential (credential)'),
						$entry('credential', 'Credential (credential)'),
					],
					'errorMessage' => null,
					'issuerDid' => 'did:web:learniq:tenant-1:fingerprint',
					'verificationStatus' => 'verified',
				]
			),
			$saved
		);
	}//end testOwnFormatReportListsEveryRowInOrder()

	/**
	 * Without a usable proof, or without an own issuer to compare against,
	 * an own-format bundle is unverifiable and nothing is verified.
	 *
	 * @return void
	 */
	public function testAMissingProofOrOwnIssuerIsUnverifiable(): void {
		$cases = [
			'no proof' => [[], 'did:own'],
			'empty proof' => [['proof' => ['jws' => '']], 'did:own'],
			'proof not a string' => [['proof' => ['jws' => ['x']]], 'did:own'],
			'no own issuer' => [['proof' => ['jws' => 'h..s']], null],
			'no bundle issuer' => [['proof' => ['jws' => 'h..s'], 'issuerDid' => null], 'did:own'],
			'neither issuer' => [['proof' => ['jws' => 'h..s'], 'issuerDid' => null], null],
		];

		foreach ($cases as $label => [$extra, $own]) {
			$bundle = array_merge(['issuerDid' => 'did:own'], $extra);
			$service = $this->makeService(rawContent: (string)json_encode($bundle));
			$this->signingService->method('resolveIssuerDid')->willReturn($own);
			$this->signingService->expects($this->never())->method('verify');

			$saved = $service->parse(import: $this->baseObject());

			self::assertSame('unverifiable', $saved['verificationStatus'], $label);
			self::assertSame([], $saved['entries'], $label);
			self::assertSame(($bundle['issuerDid'] ?? null), $saved['issuerDid'], $label);
		}
	}//end testAMissingProofOrOwnIssuerIsUnverifiable()

	/**
	 * A bare ELM list without a `credentials` key: every entry titled by its
	 * achievement, its name, or its position; the issuer read from `issuer.id`.
	 *
	 * @return void
	 */
	public function testABareElmListIsTitledByNameOrPosition(): void {
		$elmList = [
			['credentialSubject' => ['achievement' => ['name' => 'Diploma A']], 'name' => 'ignored'],
			['name' => 'Certificaat B'],
			['name' => ''],
			'not-an-object',
		];
		$service = $this->makeService(rawContent: (string)json_encode($elmList));
		$this->signingService->expects($this->never())->method('resolveIssuerDid');

		$saved = $service->parse(import: $this->baseObject(['sourceFormat' => 'elm-europass']));

		self::assertSame(['Diploma A', 'Certificaat B', 'ELM credential 3', 'ELM credential 4'], array_column($saved['entries'], 'sourceTitle'));
		self::assertNull($saved['issuerDid']);
		self::assertSame('unverifiable', $saved['verificationStatus']);
		self::assertNull($saved['errorMessage']);

		$withIssuer = $this->makeService(rawContent: (string)json_encode(['issuer' => ['id' => 'did:a'], 'issuerDid' => 'did:b', 'credentials' => [['name' => 'X']]]));
		$saved = $withIssuer->parse(import: $this->baseObject(['sourceFormat' => 'elm-europass']));
		self::assertSame('did:a', $saved['issuerDid']);
		self::assertSame(['X'], array_column($saved['entries'], 'sourceTitle'));

		$credentialsNotAList = $this->makeService(rawContent: (string)json_encode(['issuerDid' => 'did:b', 'credentials' => 'none']));
		$saved = $credentialsNotAList->parse(import: $this->baseObject(['sourceFormat' => 'elm-europass']));
		self::assertSame('did:b', $saved['issuerDid']);
		self::assertSame(['ELM credential 1', 'ELM credential 2'], array_column($saved['entries'], 'sourceTitle'));
	}//end testABareElmListIsTitledByNameOrPosition()

	/**
	 * The upload is read from the uploader's own files, relative to their
	 * home; a missing reference, a missing uploader, a folder or a failing
	 * read all answer "could not read", the failure with a warning.
	 *
	 * @return void
	 */
	public function testTheUploadIsReadFromTheUploadersFiles(): void {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn('{"a":1}');
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willReturnCallback(
			function (string $path) use ($file): object {
				if ($path === 'boom.json') {
					throw new RuntimeException('disk gone');
				}

				return ($path === 'a/b.json' ? $file : $this->createMock(Folder::class));
			}
		);
		$users = [];
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturnCallback(
			function (string $uid) use (&$users, $folder): Folder {
				$users[] = $uid;
				return $folder;
			}
		);
		$warnings = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);
		$service = new LearningRecordImportService(
			signingService: $this->createMock(LearningRecordExportSigningService::class),
			rootFolder: $rootFolder,
			logger: $logger,
		);

		$read = $service->check($this->baseObject(['sourceRef' => '//a/b.json', 'sourceFormat' => 'unknown-format']), 'parse', 'x');
		self::assertSame('Unrecognised sourceFormat: unknown-format', $read->getMessage());

		foreach ([['sourceRef' => ''], ['uploadedBy' => ''], ['sourceRef' => '/a'], ['sourceRef' => 'boom.json']] as $override) {
			$verdict = $service->check($this->baseObject($override), 'parse', 'x');
			self::assertSame('Could not read the uploaded bundle.', $verdict->getMessage(), (string)json_encode($override));
		}

		self::assertSame(['coordinator-1', 'coordinator-1', 'coordinator-1'], $users);
		self::assertSame(
			[['[LearningRecordImportService] Could not read uploaded bundle at "{ref}": {msg}', ['ref' => 'boom.json', 'msg' => 'disk gone']]],
			$warnings
		);
	}//end testTheUploadIsReadFromTheUploadersFiles()

}//end class
