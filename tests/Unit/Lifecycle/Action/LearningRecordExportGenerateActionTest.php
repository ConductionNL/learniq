<?php

/**
 * Tests for the LearningRecordExport generate action that writes the signed bundle.
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

use OCA\Learniq\Lifecycle\Action\LearningRecordExportGenerateAction;
use OCA\Learniq\Service\LearningRecordAggregationService;
use OCA\Learniq\Service\LearningRecordBundleWriter;
use OCA\Learniq\Service\LearningRecordExportService;
use OCA\Learniq\Service\LearningRecordExportSigningService;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The bundle fields the generate guard used to write into its context now come
 * back from this action (learniq#983). OpenRegister's executor is not loadable
 * here; the test drives the action directly against the copied interface.
 */
class LearningRecordExportGenerateActionTest extends TestCase {

	/**
	 * Build the action over a signing service answering with the given signature.
	 *
	 * @param string|null $signature The JWS the signing service returns, or null when signing fails.
	 *
	 * @return LearningRecordExportGenerateAction
	 */
	private function makeAction(?string $signature): LearningRecordExportGenerateAction {
		$aggregation = $this->createMock(LearningRecordAggregationService::class);
		$aggregation->method('compose')->willReturn(['finalGrades' => [['id' => 'fg-1']]]);
		$signing = $this->createMock(LearningRecordExportSigningService::class);
		$signing->method('resolveIssuerDid')->willReturn('did:web:learniq:tenant-1:abc');
		$signing->method('sign')->willReturn($signature);
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn([]);

		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willThrowException(new NotFoundException());
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFolder')->willReturn($this->createMock(Folder::class));
		$folder->method('newFile')->willReturn($this->createMock(File::class));
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($folder);

		return new LearningRecordExportGenerateAction(
			new LearningRecordExportService(
				aggregationService: $aggregation,
				signingService: $signing,
				objectService: $objectService,
				bundleWriter: new LearningRecordBundleWriter(rootFolder: $rootFolder, logger: new NullLogger()),
			)
		);
	}//end makeAction()

	/**
	 * The export as OpenRegister saves it on `generate`.
	 *
	 * @return array<string,mixed>
	 */
	private static function generatedExport(): array {
		return [
			'id' => 'export-1',
			'lifecycle' => 'generated',
			'learnerId' => 'anna',
			'learnerRef' => 'learner-ref-1',
			'requestedBy' => 'anna',
			'tenant_id' => 'tenant-1',
			'periodFrom' => null,
			'periodTo' => null,
		];
	}//end generatedExport()

	/**
	 * The action is one OpenRegister's executor can run.
	 *
	 * @return void
	 */
	public function testImplementsTheActionInterface(): void {
		self::assertInstanceOf(LifecycleActionInterface::class, $this->makeAction('sig'));
	}//end testImplementsTheActionInterface()

	/**
	 * The signed bundle's fields land on the saved object.
	 *
	 * @return void
	 */
	public function testBundleFieldsLandOnTheSavedObject(): void {
		$saved = $this->makeAction('header..signature')->execute(self::generatedExport(), [], [], LearningRecordExportGenerateAction::class);

		self::assertSame('generated', $saved['lifecycle']);
		self::assertSame('header..signature', $saved['bundleSignature']);
		self::assertSame('did:web:learniq:tenant-1:abc', $saved['issuerDid']);
		self::assertNotEmpty($saved['bundleRef']);
		self::assertNotEmpty($saved['generatedAt']);
		self::assertNotEmpty($saved['coverageReport']);
		self::assertNull($saved['errorMessage']);
	}//end testBundleFieldsLandOnTheSavedObject()

	/**
	 * A signing failure throws, so no unsigned export is saved as generated.
	 *
	 * @return void
	 */
	public function testSigningFailureThrows(): void {
		$this->expectException(RuntimeException::class);

		$this->makeAction(null)->execute(self::generatedExport(), [], [], LearningRecordExportGenerateAction::class);
	}//end testSigningFailureThrows()
}//end class
