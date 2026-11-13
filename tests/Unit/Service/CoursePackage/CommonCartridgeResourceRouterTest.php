<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\CoursePackage;

use OCA\Learniq\Service\CoursePackage\CommonCartridgeResourceRouter;
use OCA\Learniq\Service\CoursePackage\CoursePackageFileWriter;
use OCA\Learniq\Service\CoursePackage\CoursePackageImportReporter;
use OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter;
use OCA\Learniq\Service\CoursePackage\CoursePackageOutput;
use OCA\Learniq\Service\CoursePackage\PackageContext;
use OCA\Learniq\Service\CoursePackage\PackageXmlValueReader;
use OCA\Learniq\Service\CoursePackage\ResourceSlot;
use OCA\Learniq\Tests\Support\CapturingLogger;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * CommonCartridgeResourceRouter routes every Common Cartridge resource to its
 * Learniq target and answers exactly one report entry for it. The object and
 * file writers are real; only OpenRegister and the Nextcloud files tree are doubles.
 *
 * @covers \OCA\Learniq\Service\CoursePackage\CommonCartridgeResourceRouter
 * @uses   \OCA\Learniq\Service\CoursePackage\CoursePackageFileWriter
 * @uses   \OCA\Learniq\Service\CoursePackage\CoursePackageImportReporter
 * @uses   \OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter
 * @uses   \OCA\Learniq\Service\CoursePackage\CoursePackageOutput
 * @uses   \OCA\Learniq\Service\CoursePackage\PackageContext
 * @uses   \OCA\Learniq\Service\CoursePackage\PackageXmlValueReader
 * @uses   \OCA\Learniq\Service\CoursePackage\ResourceSlot
 * @uses   \OCA\Learniq\Service\FolderFileWriter
 */
class CommonCartridgeResourceRouterTest extends TestCase {

	/**
	 * Extracted package fixture directory.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * OpenRegister double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * Nextcloud root folder double.
	 *
	 * @var IRootFolder&MockObject
	 */
	private IRootFolder&MockObject $rootFolder;

	/**
	 * Logger that keeps every call.
	 *
	 * @var CapturingLogger
	 */
	private CapturingLogger $logger;

	/**
	 * Every saveObject call as [schema, object].
	 *
	 * @var array<int, array{0: mixed, 1: mixed}>
	 */
	private array $saves = [];

	/**
	 * Every deleteObject call as [uuid, register, schema].
	 *
	 * @var array<int, array{0: mixed, 1: mixed, 2: mixed}>
	 */
	private array $deletes = [];

	/**
	 * Schema slug to the UUID its save answers, or a Throwable to throw.
	 *
	 * @var array<string, string|\Throwable|null>
	 */
	private array $saveAnswers = [];

	/**
	 * What deleteObject throws, when set.
	 *
	 * @var \Throwable|null
	 */
	private ?\Throwable $deleteFailure = null;

	/**
	 * Create the fixture directory and the boundary doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/learniq-ccrr-' . uniqid('', true);
		mkdir($this->dir . '/web', 0777, true);

		$this->saveAnswers = ['material' => 'mat-1', 'lesson' => 'les-1', 'lti-tool-placement' => 'lti-1'];
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null): ObjectEntity {
				$this->saves[] = [$schema, $object];
				$answer = ($this->saveAnswers[$schema] ?? null);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				$entity = new ObjectEntity();
				$entity->setUuid($answer);
				return $entity;
			}
		);
		$this->objectService->method('deleteObject')->willReturnCallback(
			function (string $uuid, mixed $register = null, mixed $schema = null): bool {
				$this->deletes[] = [$uuid, $register, $schema];
				if ($this->deleteFailure !== null) {
					throw $this->deleteFailure;
				}

				return true;
			}
		);

		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->logger = new CapturingLogger();
	}//end setUp()

	/**
	 * Remove the fixture directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ((array)glob($this->dir . '/web/*') as $file) {
			unlink((string)$file);
		}

		rmdir($this->dir . '/web');
		rmdir($this->dir);
	}//end tearDown()

	/**
	 * The router under test, wired with real writers over the boundary doubles.
	 *
	 * @return CommonCartridgeResourceRouter
	 */
	private function router(): CommonCartridgeResourceRouter {
		return new CommonCartridgeResourceRouter(
			output: new CoursePackageOutput(
				objectWriter: new CoursePackageObjectWriter(objectService: $this->objectService),
				fileWriter: new CoursePackageFileWriter(rootFolder: $this->rootFolder, logger: $this->logger),
				reporter: new CoursePackageImportReporter(objectService: $this->objectService)
			),
			xmlReader: new PackageXmlValueReader(),
			logger: $this->logger
		);
	}//end router()

	/**
	 * Route one resource in the fixture package.
	 *
	 * @param array<string, mixed> $resource    The resource row.
	 * @param string|null          $lessonTitle The slot's lesson title.
	 * @param int|null             $lessonOrder The slot's lesson order.
	 *
	 * @return array<string, mixed> The report entry.
	 */
	private function route(array $resource, ?string $lessonTitle = 'Lesson title', ?int $lessonOrder = 3): array {
		return $this->router()->routeResource(
			slot: new ResourceSlot(resource: $resource, courseId: 'course-1', lessonOrder: $lessonOrder, lessonTitle: $lessonTitle),
			package: new PackageContext(dir: $this->dir, importedBy: 'teacher1', tenantId: 'tenant-1')
		);
	}//end route()

	/**
	 * A resource row.
	 *
	 * @param string      $classification Manifest classification.
	 * @param string      $type           Manifest type string.
	 * @param string|null $href           Package-relative href.
	 *
	 * @return array<string, mixed>
	 */
	private static function resource(string $classification, string $type, ?string $href = null): array {
		return [
			'identifier' => 'RES1',
			'type' => $type,
			'title' => 'Resource title',
			'href' => $href,
			'classification' => $classification,
		];
	}//end resource()

	/**
	 * The expected report entry.
	 *
	 * @param string      $type       Resource type.
	 * @param string      $outcome    Outcome.
	 * @param string|null $targetType Target schema.
	 * @param string|null $targetId   Target UUID.
	 * @param string|null $reason     Reason.
	 *
	 * @return array<string, mixed>
	 */
	private static function entry(string $type, string $outcome, ?string $targetType, ?string $targetId, ?string $reason): array {
		return [
			'resourceIdentifier' => 'RES1',
			'resourceType' => $type,
			'title' => 'Resource title',
			'outcome' => $outcome,
			'targetType' => $targetType,
			'targetId' => $targetId,
			'reason' => $reason,
		];
	}//end entry()

	/**
	 * A SCORM resource is dropped by its type, whatever its classification, and nothing is written.
	 *
	 * @return void
	 */
	public function testScormIsDroppedWithoutWriting(): void {
		$this->rootFolder->expects($this->never())->method('getUserFolder');

		$entry = $this->route(resource: self::resource(classification: 'webcontent', type: 'imsadlcp_SCORM_v1p3', href: 'web/page.html'));

		$this->assertSame(
			self::entry('imsadlcp_SCORM_v1p3', 'dropped', null, null, "requires ADR-002's lesson-content importer, not yet implemented"),
			$entry
		);
		$this->assertSame([], $this->saves);
	}//end testScormIsDroppedWithoutWriting()

	/**
	 * Webcontent writes its file into nc:files, then a document Material, then the Lesson that shows it.
	 *
	 * @return void
	 */
	public function testWebContentCreatesMaterialWithFileAndLesson(): void {
		file_put_contents($this->dir . '/web/page.html', '<p>Hello</p>');
		$userFolder = $this->createMock(Folder::class);
		$userFolder->expects($this->exactly(3))->method('get')->willReturn($this->createMock(Node::class));
		$userFolder->method('nodeExists')->willReturn(false);
		$userFolder->expects($this->once())
			->method('newFile')
			->with('Learniq/tenant-1/course-imports/page.html', '<p>Hello</p>');
		$this->rootFolder->expects($this->once())->method('getUserFolder')->with('teacher1')->willReturn($userFolder);

		$entry = $this->route(resource: self::resource(classification: 'webcontent', type: 'webcontent', href: 'web/page.html'));

		$this->assertSame(self::entry('webcontent', 'imported', 'material', 'mat-1', null), $entry);
		$this->assertSame(
			[
				[
					'material',
					[
						'title' => 'Lesson title',
						'kind' => 'document',
						'fileRef' => '/Learniq/tenant-1/course-imports/page.html',
						'url' => null,
						'courseId' => 'course-1',
						'tenant_id' => 'tenant-1',
					],
				],
				[
					'lesson',
					[
						'courseId' => 'course-1',
						'name' => 'Lesson title',
						'order' => 3,
						'contentType' => 'text',
						'contentRef' => 'mat-1',
						'lifecycle' => 'draft',
						'tenant_id' => 'tenant-1',
					],
				],
			],
			$this->saves
		);
		$this->assertSame([], $this->logger->records);
	}//end testWebContentCreatesMaterialWithFileAndLesson()

	/**
	 * Without a slot title and order, the resource title and order 0 are used; no href means no file.
	 *
	 * @return void
	 */
	public function testWebContentFallsBackToResourceTitleAndOrderZero(): void {
		$this->rootFolder->expects($this->never())->method('getUserFolder');

		$entry = $this->route(resource: self::resource(classification: 'webcontent', type: 'webcontent'), lessonTitle: null, lessonOrder: null);

		$this->assertSame('imported', $entry['outcome']);
		$this->assertSame('Resource title', $this->saves[0][1]['title']);
		$this->assertSame('', $this->saves[0][1]['fileRef']);
		$this->assertSame('Resource title', $this->saves[1][1]['name']);
		$this->assertSame(0, $this->saves[1][1]['order']);
	}//end testWebContentFallsBackToResourceTitleAndOrderZero()

	/**
	 * A failed Lesson removes the Material it would have shown and reports the resource dropped.
	 *
	 * @return void
	 */
	public function testFailedLessonRemovesItsMaterial(): void {
		$this->saveAnswers['lesson'] = new RuntimeException('lesson store down');

		$entry = $this->route(resource: self::resource(classification: 'webcontent', type: 'webcontent'));

		$this->assertSame(self::entry('webcontent', 'dropped', null, null, 'Import failed: lesson store down'), $entry);
		$this->assertSame([['mat-1', 'learniq', 'material']], $this->deletes);
		$this->assertCount(1, $this->logger->records);
		$this->assertSame('warning', $this->logger->records[0]['level']);
		$this->assertSame(
			['id' => 'RES1', 'msg' => 'lesson store down'],
			$this->logger->records[0]['context']
		);
	}//end testFailedLessonRemovesItsMaterial()

	/**
	 * A Material save that answered no UUID leaves nothing to remove when its Lesson fails.
	 *
	 * @return void
	 */
	public function testFailedLessonWithoutMaterialIdRemovesNothing(): void {
		$this->saveAnswers['material'] = null;
		$this->saveAnswers['lesson'] = new RuntimeException('lesson store down');

		$entry = $this->route(resource: self::resource(classification: 'webcontent', type: 'webcontent'));

		$this->assertSame('Import failed: lesson store down', $entry['reason']);
		$this->assertSame('', $this->saves[1][1]['contentRef']);
		$this->assertSame([], $this->deletes);
		$this->assertCount(1, $this->logger->records);
	}//end testFailedLessonWithoutMaterialIdRemovesNothing()

	/**
	 * A failed Material removal is logged, and the report still names the Lesson's failure.
	 *
	 * @return void
	 */
	public function testFailedMaterialRemovalIsLogged(): void {
		$this->saveAnswers['lesson'] = new RuntimeException('lesson store down');
		$this->deleteFailure = new RuntimeException('delete refused');

		$entry = $this->route(resource: self::resource(classification: 'webcontent', type: 'webcontent'));

		$this->assertSame('Import failed: lesson store down', $entry['reason']);
		$this->assertSame([['mat-1', 'learniq', 'material']], $this->deletes);
		$this->assertSame(
			[
				['[CommonCartridgeResourceRouter] Material {id} of a dropped resource could not be removed: {msg}', ['id' => 'mat-1', 'msg' => 'delete refused']],
				['[CommonCartridgeResourceRouter] Resource {id} failed to import: {msg}', ['id' => 'RES1', 'msg' => 'lesson store down']],
			],
			array_map(static fn (array $record): array => [$record['message'], $record['context']], $this->logger->records)
		);
	}//end testFailedMaterialRemovalIsLogged()

	/**
	 * A failing Material save drops the resource; no Lesson is attempted.
	 *
	 * @return void
	 */
	public function testFailedMaterialSaveDropsTheResource(): void {
		$this->saveAnswers['material'] = new RuntimeException('register offline');

		$entry = $this->route(resource: self::resource(classification: 'weblink', type: 'imswl_xmlv1p1', href: 'web/link.xml'));

		$this->assertSame(self::entry('imswl_xmlv1p1', 'dropped', null, null, 'Import failed: register offline'), $entry);
		$this->assertSame(['material'], array_column($this->saves, 0));
		$this->assertSame('[CommonCartridgeResourceRouter] Resource {id} failed to import: {msg}', $this->logger->records[0]['message']);
	}//end testFailedMaterialSaveDropsTheResource()

	/**
	 * A weblink becomes a link Material pointing at the URL inside its descriptor.
	 *
	 * @return void
	 */
	public function testWeblinkResolvesUrlFromDescriptor(): void {
		file_put_contents(
			$this->dir . '/web/link.xml',
			'<webLink xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imswl_v1p1"><title>L</title><url href="https://example.org/target"/></webLink>'
		);

		$entry = $this->route(resource: self::resource(classification: 'weblink', type: 'imswl_xmlv1p1', href: 'web/link.xml'));

		$this->assertSame(self::entry('imswl_xmlv1p1', 'imported', 'material', 'mat-1', null), $entry);
		$this->assertSame(
			[
				[
					'material',
					[
						'title' => 'Lesson title',
						'kind' => 'link',
						'fileRef' => '',
						'url' => 'https://example.org/target',
						'courseId' => 'course-1',
						'tenant_id' => 'tenant-1',
					],
				],
			],
			$this->saves
		);
	}//end testWeblinkResolvesUrlFromDescriptor()

	/**
	 * A weblink whose descriptor is missing keeps its manifest href as the URL.
	 *
	 * @return void
	 */
	public function testWeblinkFallsBackToHrefWhenDescriptorMissing(): void {
		$this->route(resource: self::resource(classification: 'weblink', type: 'imswl_xmlv1p1', href: 'web/absent.xml'), lessonTitle: null);

		$this->assertSame('web/absent.xml', $this->saves[0][1]['url']);
		$this->assertSame('Resource title', $this->saves[0][1]['title']);
	}//end testWeblinkFallsBackToHrefWhenDescriptorMissing()

	/**
	 * A weblink without an href gets no URL.
	 *
	 * @return void
	 */
	public function testWeblinkWithoutHrefHasNoUrl(): void {
		$entry = $this->route(resource: self::resource(classification: 'weblink', type: 'imswl_xmlv1p1'));

		$this->assertSame('imported', $entry['outcome']);
		$this->assertNull($this->saves[0][1]['url']);
	}//end testWeblinkWithoutHrefHasNoUrl()

	/**
	 * QTI items and tests get a `pending-qti` placeholder and write nothing yet.
	 *
	 * @return void
	 */
	public function testQtiResourcesArePendingWithoutWriting(): void {
		$this->assertSame(
			self::entry('imsqti_xmlv1p2/imscc_xmlv1p1/assessment', 'pending-qti', null, null, null),
			$this->route(resource: self::resource(classification: 'imsqti_test', type: 'imsqti_xmlv1p2/imscc_xmlv1p1/assessment'))
		);
		$this->assertSame(
			self::entry('imsqti_xmlv1p2/imscc_xmlv1p1/question-bank', 'pending-qti', null, null, null),
			$this->route(resource: self::resource(classification: 'imsqti_item', type: 'imsqti_xmlv1p2/imscc_xmlv1p1/question-bank'))
		);
		$this->assertSame([], $this->saves);
	}//end testQtiResourcesArePendingWithoutWriting()

	/**
	 * Basic LTI becomes a draft placement reported `degraded`, since no deployment is bound.
	 *
	 * @return void
	 */
	public function testBasicLtiCreatesDegradedPlacement(): void {
		$entry = $this->route(resource: self::resource(classification: 'basiclti', type: 'imsbasiclti_xmlv1p0'));

		$this->assertSame(
			self::entry(
				'imsbasiclti_xmlv1p0',
				'degraded',
				'lti-tool-placement',
				'lti-1',
				'LTI placement created without a configured OpenConnector deployment; '
				. 'an admin must bind a deployment before this tool can be launched.'
			),
			$entry
		);
		$this->assertSame(
			[
				[
					'lti-tool-placement',
					[
						'openconnectorDeploymentId' => '',
						'launchMode' => 'resource-link',
						'courseId' => 'course-1',
						'lifecycle' => 'draft',
						'tenant_id' => 'tenant-1',
					],
				],
			],
			$this->saves
		);
	}//end testBasicLtiCreatesDegradedPlacement()

	/**
	 * A discussion has no Learniq schema and is dropped with a migration hint.
	 *
	 * @return void
	 */
	public function testDiscussionIsDropped(): void {
		$this->assertSame(
			self::entry('imsdt_xmlv1p1', 'dropped', null, null, 'No Learniq schema represents discussion/forum content — migrate manually.'),
			$this->route(resource: self::resource(classification: 'discussion', type: 'imsdt_xmlv1p1'))
		);
		$this->assertSame([], $this->saves);
	}//end testDiscussionIsDropped()

	/**
	 * An unknown classification is dropped naming its type.
	 *
	 * @return void
	 */
	public function testUnsupportedTypeIsDropped(): void {
		$this->assertSame(
			self::entry('associatedcontent/imscc_xmlv1p1/learning-application-resource', 'dropped', null, null, 'Resource type not supported: associatedcontent/imscc_xmlv1p1/learning-application-resource.'),
			$this->route(resource: self::resource(classification: 'unknown', type: 'associatedcontent/imscc_xmlv1p1/learning-application-resource'))
		);
		$this->assertSame([], $this->saves);
		$this->assertSame([], $this->logger->records);
	}//end testUnsupportedTypeIsDropped()
}//end class
