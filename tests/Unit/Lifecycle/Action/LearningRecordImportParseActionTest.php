<?php

/**
 * Tests for the LearningRecordImport parse action that writes the coverage report.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle\Action
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

namespace OCA\Learniq\Tests\Unit\Lifecycle\Action;

use OCA\Learniq\Lifecycle\Action\LearningRecordImportParseAction;
use OCA\Learniq\Service\LearningRecordExportSigningService;
use OCA\Learniq\Service\LearningRecordImportService;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The entries and verification status the parse guard used to write into its
 * context now come back from this action (learniq#983). OpenRegister's
 * executor is not loadable here; the test drives the action directly against
 * the copied interface.
 */
class LearningRecordImportParseActionTest extends TestCase {

	/**
	 * Build the action over an uploaded file with the given bytes.
	 *
	 * @param string $rawContent The uploaded bundle's bytes.
	 *
	 * @return LearningRecordImportParseAction
	 */
	private function makeAction(string $rawContent): LearningRecordImportParseAction {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn($rawContent);
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willReturn($file);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($folder);

		return new LearningRecordImportParseAction(
			new LearningRecordImportService(
				signingService: $this->createMock(LearningRecordExportSigningService::class),
				rootFolder: $rootFolder,
				logger: new NullLogger(),
			)
		);
	}//end makeAction()

	/**
	 * The import as OpenRegister saves it on `parse`.
	 *
	 * @return array<string,mixed>
	 */
	private static function parsedImport(): array {
		return [
			'id' => 'import-1',
			'lifecycle' => 'parsed',
			'sourceRef' => '/Scholiq/tenant-1/learning-record-imports/abc.json',
			'uploadedBy' => 'coordinator-1',
			'sourceFormat' => 'elm-europass',
			'tenant_id' => 'tenant-1',
		];
	}//end parsedImport()

	/**
	 * The action is one OpenRegister's executor can run.
	 *
	 * @return void
	 */
	public function testImplementsTheActionInterface(): void {
		self::assertInstanceOf(LifecycleActionInterface::class, $this->makeAction('[]'));
	}//end testImplementsTheActionInterface()

	/**
	 * The parsed entries and verification status land on the saved object.
	 *
	 * @return void
	 */
	public function testParsedEntriesLandOnTheSavedObject(): void {
		$bundle = (string)json_encode(['credentials' => [['name' => 'Foreign Diploma']]]);

		$saved = $this->makeAction($bundle)->execute(self::parsedImport(), [], [], LearningRecordImportParseAction::class);

		self::assertSame('parsed', $saved['lifecycle']);
		self::assertSame('unverifiable', $saved['verificationStatus']);
		self::assertCount(1, $saved['entries']);
		self::assertSame('Foreign Diploma', $saved['entries'][0]['sourceTitle']);
		self::assertNull($saved['errorMessage']);
	}//end testParsedEntriesLandOnTheSavedObject()

	/**
	 * An unreadable bundle throws, so no import is saved as parsed without entries.
	 *
	 * @return void
	 */
	public function testUnparseableBundleThrows(): void {
		$this->expectException(RuntimeException::class);

		$this->makeAction('{not json')->execute(self::parsedImport(), [], [], LearningRecordImportParseAction::class);
	}//end testUnparseableBundleThrows()
}//end class
