<?php

/**
 * Tests for the attacher that puts the rendered report-card PDF on the card.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\ReportCardPdfAttacher;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The PDF filinq rendered becomes the card's one `Rapport … .pdf` file
 * (report-card-pdf-to-guardians D2, D3), fail-soft.
 */
#[CoversClass(ReportCardPdfAttacher::class)]
class ReportCardPdfAttacherTest extends TestCase {

	/**
	 * @var FileService&MockObject
	 */
	private FileService&MockObject $fileService;

	/**
	 * @var Folder&MockObject
	 */
	private Folder&MockObject $storage;

	/**
	 * @var IRootFolder&MockObject
	 */
	private IRootFolder&MockObject $rootFolder;

	/**
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $userManager;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->fileService = $this->createMock(FileService::class);
		$this->storage     = $this->createMock(Folder::class);
		$this->rootFolder  = $this->createMock(IRootFolder::class);
		$this->appConfig   = $this->createMock(IAppConfig::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->logger      = $this->createMock(LoggerInterface::class);

		$this->appConfig->method('getValueString')->willReturnMap([
			['filinq', 'report_render_storage_user', '', false, 'filinq-reports'],
		]);
		$this->rootFolder->method('getUserFolder')->willReturnMap([['filinq-reports', $this->storage]]);
		$learner = $this->createMock(IUser::class);
		$learner->method('getDisplayName')->willReturn('Sam de Vries');
		$this->userManager->method('get')->willReturnMap([['sam', $learner]]);
	}//end setUp()

	/**
	 * The attacher under test.
	 *
	 * @return ReportCardPdfAttacher
	 */
	private function attacher(): ReportCardPdfAttacher {
		return new ReportCardPdfAttacher(
			fileService: $this->fileService,
			rootFolder: $this->rootFolder,
			appConfig: $this->appConfig,
			userManager: $this->userManager,
			logger: $this->logger
		);
	}//end attacher()

	/**
	 * A rendered card.
	 *
	 * @param array<string, mixed> $extra Fields to add or replace.
	 *
	 * @return ObjectEntity
	 */
	private function card(array $extra = []): ObjectEntity {
		return OrEntityFactory::make(
			array_merge(
				[
					'id'                   => 'card-1',
					'learnerId'            => 'sam',
					'periodName'           => 'Periode 1',
					'docudeskRenderStatus' => 'rendered',
					'docudeskDocumentRef'  => '417',
				],
				$extra
			),
			'report-card'
		);
	}//end card()

	/**
	 * A Files node with a name.
	 *
	 * @param string $name    The file name.
	 * @param string $content The file content.
	 *
	 * @return File&MockObject
	 */
	private function file(string $name, string $content = ''): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getContent')->willReturn($content);

		return $file;
	}//end file()

	/**
	 * The rendered PDF is written on the card under the period and the learner's name.
	 *
	 * @return void
	 */
	public function testFirstRenderWritesThePdfOnTheCard(): void {
		$card = $this->card();
		$this->storage->expects($this->once())->method('getById')->with(417)->willReturn([$this->file('rapport.pdf', '%PDF-1.7 body')]);
		$this->fileService->method('getFiles')->willReturn([]);
		$this->fileService->expects($this->once())->method('saveFile')->with(
			$card,
			null,
			'%PDF-1.7 body',
			false,
			['report-card-pdf'],
			'Rapport Periode 1 Sam de Vries.pdf'
		)->willReturn($this->file('Rapport Periode 1 Sam de Vries.pdf'));
		$this->fileService->expects($this->never())->method('deleteFile');

		self::assertSame('Rapport Periode 1 Sam de Vries.pdf', $this->attacher()->attach(reportCard: $card));
	}//end testFirstRenderWritesThePdfOnTheCard()

	/**
	 * A period already named "Rapport …" is not prefixed twice; an unknown learner keeps the user id.
	 *
	 * @return void
	 */
	public function testAPeriodNamedRapportIsNotPrefixedTwice(): void {
		$this->storage->method('getById')->willReturn([$this->file('x.pdf')]);
		$this->fileService->method('getFiles')->willReturn([]);
		$this->fileService->expects($this->once())->method('saveFile');

		$name = $this->attacher()->attach(reportCard: $this->card(['periodName' => 'Rapport 2', 'learnerId' => 'kim/2']));

		self::assertSame('Rapport 2 kim-2.pdf', $name);
	}//end testAPeriodNamedRapportIsNotPrefixedTwice()

	/**
	 * A re-render under another name removes the earlier PDF and keeps other files.
	 *
	 * @return void
	 */
	public function testAReRenderRemovesTheEarlierPdfOnly(): void {
		$card    = $this->card();
		$current = $this->file('Rapport Periode 1 Sam de Vries.pdf');
		$earlier = $this->file('Rapport Periode 1 sam.pdf');
		$other   = $this->file('Verslag mentorgesprek.pdf');
		$image   = $this->file('Rapport foto.png');
		$this->storage->method('getById')->willReturn([$this->file('r.pdf', '%PDF')]);
		$this->fileService->expects($this->once())->method('saveFile')->willReturn($current);
		$this->fileService->method('getFiles')->willReturn([$current, $earlier, $other, $image]);
		$this->fileService->expects($this->once())->method('deleteFile')->with($earlier, $card)->willReturn(true);

		self::assertSame('Rapport Periode 1 Sam de Vries.pdf', $this->attacher()->attach(reportCard: $card));
	}//end testAReRenderRemovesTheEarlierPdfOnly()

	/**
	 * A card that is not rendered, or has no Files id, attaches nothing and reads nothing.
	 *
	 * @return void
	 */
	public function testANotRenderedCardAttachesNothing(): void {
		$this->rootFolder->expects($this->never())->method('getUserFolder');
		$this->fileService->expects($this->never())->method('saveFile');

		self::assertNull($this->attacher()->attach(reportCard: $this->card(['docudeskRenderStatus' => 'failed'])));
		self::assertNull($this->attacher()->attach(reportCard: $this->card(['docudeskDocumentRef' => 'doc-1'])));
		self::assertNull($this->attacher()->attach(reportCard: $this->card(['docudeskDocumentRef' => null])));
	}//end testANotRenderedCardAttachesNothing()

	/**
	 * The rendered PDF missing from filinq's storage is logged and attaches nothing.
	 *
	 * @return void
	 */
	public function testAMissingRenderedPdfIsLogged(): void {
		$this->storage->method('getById')->willReturn([$this->createMock(Folder::class)]);
		$this->fileService->expects($this->never())->method('saveFile');
		$this->logger->expects($this->once())->method('warning');

		self::assertNull($this->attacher()->attach(reportCard: $this->card()));
	}//end testAMissingRenderedPdfIsLogged()

	/**
	 * No filinq storage user configured: nothing is read or attached.
	 *
	 * @return void
	 */
	public function testNoStorageUserAttachesNothing(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$this->rootFolder->expects($this->never())->method('getUserFolder');
		$this->fileService->expects($this->never())->method('saveFile');

		$attacher = new ReportCardPdfAttacher(
			fileService: $this->fileService,
			rootFolder: $this->rootFolder,
			appConfig: $appConfig,
			userManager: $this->userManager,
			logger: $this->logger
		);

		self::assertNull($attacher->attach(reportCard: $this->card()));
	}//end testNoStorageUserAttachesNothing()

	/**
	 * A failing write is logged and never thrown (fail-soft).
	 *
	 * @return void
	 */
	public function testAFailingWriteIsLoggedNotThrown(): void {
		$this->storage->method('getById')->willReturn([$this->file('r.pdf', '%PDF')]);
		$this->fileService->method('saveFile')->willThrowException(new RuntimeException('not permitted'));
		$this->fileService->expects($this->never())->method('deleteFile');
		$this->logger->expects($this->once())->method('error')->with(
			$this->stringContains('failed'),
			$this->callback(static fn (array $context): bool => $context['message'] === 'not permitted' && $context['id'] === 'card-1')
		);

		self::assertNull($this->attacher()->attach(reportCard: $this->card()));
	}//end testAFailingWriteIsLoggedNotThrown()
}//end class
