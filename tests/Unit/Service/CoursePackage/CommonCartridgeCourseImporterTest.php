<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\CoursePackage;

use OCA\Learniq\Service\CommonCartridgeParser;
use OCA\Learniq\Service\CoursePackage\CommonCartridgeCourseImporter;
use OCA\Learniq\Service\CoursePackage\CommonCartridgeResourceRouter;
use OCA\Learniq\Service\CoursePackage\CoursePackageFileWriter;
use OCA\Learniq\Service\CoursePackage\CoursePackageImportReporter;
use OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter;
use OCA\Learniq\Service\CoursePackage\CoursePackageOutput;
use OCA\Learniq\Service\CoursePackage\PackageContext;
use OCA\Learniq\Service\CoursePackage\ResourceSlot;
use OCA\Learniq\Service\QtiImportService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests CommonCartridgeCourseImporter: extraction + manifest parsing, the
 * organization walk into Courses and Lesson slots, the routing of
 * unreferenced resources, and the bulk QTI resolution of `pending-qti`
 * entries.
 *
 * The object writer, reporter and manifest parser are real, built over a
 * mocked ObjectService; the QTI import service and the resource router are
 * mocked so every call they receive can be asserted.
 *
 * @covers \OCA\Learniq\Service\CoursePackage\CommonCartridgeCourseImporter
 * @uses \OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter
 * @uses \OCA\Learniq\Service\CoursePackage\CoursePackageImportReporter
 * @uses \OCA\Learniq\Service\CoursePackage\CoursePackageOutput
 * @uses \OCA\Learniq\Service\CoursePackage\CourseMetadataFilter
 * @uses \OCA\Learniq\Service\CoursePackage\OrganizationWalk
 * @uses \OCA\Learniq\Service\CoursePackage\PackageContext
 * @uses \OCA\Learniq\Service\CoursePackage\ResourceSlot
 * @uses \OCA\Learniq\Service\CoursePackage\CommonCartridgeItemTree
 * @uses \OCA\Learniq\Service\CommonCartridgeParser
 */
class CommonCartridgeCourseImporterTest extends TestCase {

	private const TENANT = 'tenant-1';

	/**
	 * Every saveObject() call: schema and payload, in order.
	 *
	 * @var list<array{schema: string, object: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * Schemas whose saves come back without a uuid.
	 *
	 * @var list<string>
	 */
	private array $uuidlessSchemas = [];

	/**
	 * Every routeResource() call, as plain data.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $routed = [];

	/**
	 * Temp directory of the current test.
	 *
	 * @var string
	 */
	private string $dir = '';

	/**
	 * @var QtiImportService&MockObject
	 */
	private QtiImportService $qti;

	/**
	 * Fresh doubles and temp directory per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->saved = [];
		$this->uuidlessSchemas = [];
		$this->routed = [];
		$this->dir = sys_get_temp_dir() . '/learniq-ccci-' . uniqid('', true);
		mkdir($this->dir, 0777, true);
		$this->qti = $this->createMock(QtiImportService::class);
	}//end setUp()

	/**
	 * Remove the temp directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ((array)glob($this->dir . '/*') as $file) {
			@unlink((string)$file);
		}

		@rmdir($this->dir);
	}//end tearDown()

	/**
	 * The archive is extracted through the QTI service's hardened path into
	 * the target directory, then the manifest found there is parsed.
	 *
	 * @return void
	 */
	public function testExtractAndParseExtractsThenParsesTheManifest(): void {
		$manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest xmlns="http://www.imsglobal.org/xsd/imscp_v1p1" identifier="m1">
  <organizations>
    <organization identifier="org">
      <item identifier="root">
        <item identifier="mod1">
          <title>Module 1</title>
          <item identifier="it1" identifierref="r1"><title>Intro</title></item>
        </item>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="r1" type="webcontent" href="web/intro.html"/>
    <resource identifier="r2" type="imsqti_xmlv1p2/imscc_xmlv1p3/assessment"><file href="q/item.xml"/></resource>
  </resources>
</manifest>
XML;
		$this->qti->expects($this->once())
			->method('extractZip')
			->with('/uploads/course.imscc', $this->dir)
			->willReturnCallback(
				function (string $zipPath, string $targetDir) use ($manifest): void {
					file_put_contents($targetDir . '/imsmanifest.xml', $manifest);
				}
			);

		$parsed = $this->importer()->extractAndParse(packagePath: '/uploads/course.imscc', targetDir: $this->dir);

		$this->assertSame(
			[
				[
					'identifier' => 'r1',
					'type' => 'webcontent',
					'classification' => 'webcontent',
					'href' => 'web/intro.html',
					'title' => 'intro.html',
				],
				[
					'identifier' => 'r2',
					'type' => 'imsqti_xmlv1p2/imscc_xmlv1p3/assessment',
					'classification' => 'imsqti_item',
					'href' => 'q/item.xml',
					'title' => 'item.xml',
				],
			],
			$parsed['resources']
		);
		$identifiers = array_column($parsed['organizationNodes'], 'identifier');
		$this->assertContains('mod1', $identifiers);
		$this->assertContains('it1', $identifiers);
		$leaf = $parsed['organizationNodes'][array_search('it1', $identifiers, true)];
		$this->assertSame('r1', $leaf['resourceIdentifier']);
		$this->assertSame('mod1', $leaf['parentIdentifier']);
		$this->assertFalse($leaf['isFolder']);
	}//end testExtractAndParseExtractsThenParsesTheManifest()

	/**
	 * An archive without a manifest fails in the parser after extraction.
	 *
	 * @return void
	 */
	public function testExtractAndParseFailsWithoutAManifest(): void {
		$this->qti->expects($this->once())->method('extractZip')->with('/uploads/empty.imscc', $this->dir);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("No imsmanifest.xml found in '{$this->dir}'");

		$this->importer()->extractAndParse(packagePath: '/uploads/empty.imscc', targetDir: $this->dir);
	}//end testExtractAndParseFailsWithoutAManifest()

	/**
	 * Folders become nested Courses, leaf items route their resource into a
	 * Lesson slot of the enclosing Course, unknown parents and dangling
	 * resource refs fall back, and unreferenced resources route once at the
	 * top-level Course.
	 *
	 * @return void
	 */
	public function testImportManifestWalksFoldersLeavesAndUnreferencedResources(): void {
		$manifest = [
			'organizationNodes' => [
				$this->node('mod1', 'Module 1', 1, null, true, null),
				$this->node('it1', 'Intro', 2, 'mod1', false, 'r1'),
				$this->node('mod2', 'Module 2', 3, 'mod1', true, null),
				$this->node('it2', 'Deep', 4, 'mod2', false, 'r2'),
				$this->node('it3', 'Orphan parent', 5, 'nowhere', false, 'r3'),
				$this->node('dangling', 'Dangling ref', 6, 'nowhere', false, 'missing'),
			],
			'resources' => [
				$this->resource('r1', 'webcontent'),
				$this->resource('r2', 'weblink'),
				$this->resource('r3', 'discussion'),
				$this->resource('r4', 'webcontent'),
			],
		];
		$entries = [];

		$this->qti->expects($this->never())->method('importFromDirectory');

		$top = $this->importer()->importManifest(manifest: $manifest, package: $this->package(), entries: $entries);

		$this->assertSame('course-1', $top);
		$this->assertSame(['course', 'course', 'course'], array_column($this->saved, 'schema'));
		$this->assertSame(['Module 1', 'Module 2', 'Dangling ref'], array_column(array_column($this->saved, 'object'), 'name'));
		// mod2 nests under mod1; a folder with an unknown parent becomes a root Course.
		$this->assertSame([null, 'course-1', null], array_column(array_column($this->saved, 'object'), 'parentCourseId'));
		foreach ($this->saved as $save) {
			$this->assertSame(self::TENANT, $save['object']['tenant_id']);
			$this->assertSame('draft', $save['object']['lifecycle']);
			$this->assertMatchesRegularExpression('/^IMPORT-[0-9a-f]{8}$/', $save['object']['code']);
		}

		$this->assertSame(
			[
				['resource' => 'r1', 'courseId' => 'course-1', 'order' => 2, 'title' => 'Intro', 'dir' => $this->dir],
				['resource' => 'r2', 'courseId' => 'course-2', 'order' => 4, 'title' => 'Deep', 'dir' => $this->dir],
				['resource' => 'r3', 'courseId' => 'course-1', 'order' => 5, 'title' => 'Orphan parent', 'dir' => $this->dir],
				['resource' => 'r4', 'courseId' => 'course-1', 'order' => null, 'title' => null, 'dir' => $this->dir],
			],
			$this->routed
		);

		$this->assertSame(
			[
				$this->folderEntry('mod1', 'Module 1', 'course-1'),
				$this->routedEntry('r1', 'imported'),
				$this->folderEntry('mod2', 'Module 2', 'course-2'),
				$this->routedEntry('r2', 'imported'),
				$this->routedEntry('r3', 'imported'),
				$this->folderEntry('dangling', 'Dangling ref', 'course-3'),
				$this->routedEntry('r4', 'imported'),
			],
			$entries
		);
	}//end testImportManifestWalksFoldersLeavesAndUnreferencedResources()

	/**
	 * A leaf with no enclosing folder materialises one implicit top-level
	 * Course, which the following leaves and unreferenced resources reuse.
	 *
	 * @return void
	 */
	public function testLeafWithoutFolderCreatesOneImplicitTopCourse(): void {
		$manifest = [
			'organizationNodes' => [
				$this->node('it1', 'First', 1, null, false, 'r1'),
				$this->node('it2', 'Second', 2, null, false, 'r2'),
			],
			'resources' => [
				$this->resource('r1', 'webcontent'),
				$this->resource('r2', 'webcontent'),
				$this->resource('r3', 'webcontent'),
			],
		];
		$entries = [];

		$top = $this->importer()->importManifest(manifest: $manifest, package: $this->package(), entries: $entries);

		$this->assertSame('course-1', $top);
		$this->assertCount(1, $this->saved);
		$this->assertSame('Imported course', $this->saved[0]['object']['name']);
		$this->assertNull($this->saved[0]['object']['parentCourseId']);
		$this->assertSame(['course-1', 'course-1', 'course-1'], array_column($this->routed, 'courseId'));
		$this->assertSame(['r1', 'r2', 'r3'], array_column($entries, 'resourceIdentifier'));
	}//end testLeafWithoutFolderCreatesOneImplicitTopCourse()

	/**
	 * Entries the caller already holds are kept, and a resource they already
	 * report is not routed a second time.
	 *
	 * @return void
	 */
	public function testEntriesAlreadyPresentAreKeptAndNotRoutedAgain(): void {
		$manifest = [
			'organizationNodes' => [],
			'resources' => [$this->resource('r1', 'webcontent'), $this->resource('r2', 'webcontent')],
		];
		$existing = ['resourceIdentifier' => 'r1', 'outcome' => 'dropped'];
		$entries = [$existing];

		$top = $this->importer()->importManifest(manifest: $manifest, package: $this->package(), entries: $entries);

		$this->assertNull($top);
		$this->assertSame([], $this->saved);
		$this->assertSame(['r2'], array_column($this->routed, 'resource'));
		$this->assertNull($this->routed[0]['courseId']);
		$this->assertSame([$existing, $this->routedEntry('r2', 'imported')], $entries);
	}//end testEntriesAlreadyPresentAreKeptAndNotRoutedAgain()

	/**
	 * An empty manifest creates nothing and returns no Course.
	 *
	 * @return void
	 */
	public function testEmptyManifestCreatesNothing(): void {
		$entries = [];
		$this->qti->expects($this->never())->method('importFromDirectory');

		$top = $this->importer()->importManifest(
			manifest: ['organizationNodes' => [], 'resources' => []],
			package: $this->package(),
			entries: $entries
		);

		$this->assertNull($top);
		$this->assertSame([], $entries);
		$this->assertSame([], $this->saved);
		$this->assertSame([], $this->routed);
	}//end testEmptyManifestCreatesNothing()

	/**
	 * `pending-qti` entries pair positionally with the created Items: the
	 * first becomes imported, the unmatched tail degrades.
	 *
	 * @return void
	 */
	public function testPendingQtiEntriesResolveAgainstCreatedItems(): void {
		$manifest = [
			'organizationNodes' => [
				$this->node('mod1', 'Module', 1, null, true, null),
				$this->node('it1', 'Quiz one', 2, 'mod1', false, 'q1'),
			],
			'resources' => [
				$this->resource('q1', 'imsqti_item'),
				$this->resource('w1', 'webcontent'),
				$this->resource('q2', 'imsqti_item'),
			],
		];
		$entries = [];

		$this->qti->expects($this->once())
			->method('importFromDirectory')
			->with($this->dir, 'item-bank-1', self::TENANT)
			->willReturn(['item-uuid-1']);

		$top = $this->importer()->importManifest(manifest: $manifest, package: $this->package(), entries: $entries);

		$this->assertSame('course-1', $top);
		$this->assertSame(['course', 'item-bank'], array_column($this->saved, 'schema'));
		$this->assertSame(['name' => 'Imported items', 'tenant_id' => self::TENANT, 'lifecycle' => 'draft'], $this->saved[1]['object']);

		$this->assertSame(
			[
				$this->folderEntry('mod1', 'Module', 'course-1'),
				[
					'resourceIdentifier' => 'q1',
					'outcome' => 'imported',
					'targetType' => 'item',
					'targetId' => 'item-uuid-1',
					'reason' => null,
				],
				$this->routedEntry('w1', 'imported'),
				[
					'resourceIdentifier' => 'q2',
					'outcome' => 'degraded',
					'targetType' => null,
					'targetId' => null,
					'reason' => 'Item could not be parsed from the package (see application log).',
				],
			],
			$entries
		);
	}//end testPendingQtiEntriesResolveAgainstCreatedItems()

	/**
	 * Without an item bank no Item is imported, and every `pending-qti`
	 * entry degrades.
	 *
	 * @return void
	 */
	public function testNoItemBankDegradesEveryPendingQtiEntry(): void {
		$this->uuidlessSchemas = ['item-bank'];
		$manifest = [
			'organizationNodes' => [],
			'resources' => [$this->resource('q1', 'imsqti_item'), $this->resource('q2', 'imsqti_item')],
		];
		$entries = [];

		$this->qti->expects($this->never())->method('importFromDirectory');

		$this->importer()->importManifest(manifest: $manifest, package: $this->package(), entries: $entries);

		$this->assertSame(['item-bank'], array_column($this->saved, 'schema'));
		$this->assertSame(['degraded', 'degraded'], array_column($entries, 'outcome'));
		$this->assertSame([null, null], array_column($entries, 'targetId'));
	}//end testNoItemBankDegradesEveryPendingQtiEntry()

	/**
	 * When the QTI import throws, the entries gathered so far still reach
	 * the caller before the exception propagates.
	 *
	 * @return void
	 */
	public function testEntriesReachTheCallerWhenTheQtiImportThrows(): void {
		$manifest = [
			'organizationNodes' => [],
			'resources' => [$this->resource('w1', 'webcontent'), $this->resource('q1', 'imsqti_item')],
		];
		$entries = [];
		$this->qti->method('importFromDirectory')->willThrowException(new RuntimeException('bad item xml'));

		try {
			$this->importer()->importManifest(manifest: $manifest, package: $this->package(), entries: $entries);
			$this->fail('The QTI import exception should propagate.');
		} catch (RuntimeException $e) {
			$this->assertSame('bad item xml', $e->getMessage());
		}

		$this->assertSame([$this->routedEntry('w1', 'imported'), $this->routedEntry('q1', 'pending-qti')], $entries);
	}//end testEntriesReachTheCallerWhenTheQtiImportThrows()

	/**
	 * The importer under test, over a recording ObjectService and router.
	 *
	 * @return CommonCartridgeCourseImporter
	 */
	private function importer(): CommonCartridgeCourseImporter {
		$counters = [];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null) use (&$counters) {
				$schema = (string)$schema;
				$this->assertSame('learniq', $register);
				$this->saved[] = ['schema' => $schema, 'object' => $object];
				$counters[$schema] = ($counters[$schema] ?? 0) + 1;
				$uuid = null;
				if (in_array($schema, $this->uuidlessSchemas, true) === false) {
					$uuid = $schema . '-' . $counters[$schema];
				}

				return OrEntityFactory::make($object, $schema, 'learniq', $uuid);
			}
		);

		$router = $this->createMock(CommonCartridgeResourceRouter::class);
		$router->method('routeResource')->willReturnCallback(
			function (ResourceSlot $slot, PackageContext $package): array {
				$this->routed[] = [
					'resource' => $slot->resource['identifier'],
					'courseId' => $slot->courseId,
					'order' => $slot->lessonOrder,
					'title' => $slot->lessonTitle,
					'dir' => $package->dir,
				];
				$outcome = 'imported';
				if ($slot->resource['classification'] === 'imsqti_item') {
					$outcome = 'pending-qti';
				}

				return $this->routedEntry($slot->resource['identifier'], $outcome);
			}
		);

		return new CommonCartridgeCourseImporter(
			$this->qti,
			new CommonCartridgeParser(),
			$router,
			new CoursePackageOutput(
				new CoursePackageObjectWriter($objectService),
				$this->createStub(CoursePackageFileWriter::class),
				new CoursePackageImportReporter($objectService)
			),
		);
	}//end importer()

	/**
	 * The package of the current test.
	 *
	 * @return PackageContext
	 */
	private function package(): PackageContext {
		return new PackageContext($this->dir, 'anna', self::TENANT);
	}//end package()

	/**
	 * One organization node row.
	 *
	 * @param string      $identifier Identifier.
	 * @param string      $title      Title.
	 * @param int         $order      Order.
	 * @param string|null $parent     Parent identifier.
	 * @param bool        $isFolder   Whether it is a folder.
	 * @param string|null $resource   Referenced resource identifier.
	 *
	 * @return array<string, mixed>
	 */
	private function node(string $identifier, string $title, int $order, ?string $parent, bool $isFolder, ?string $resource): array {
		return [
			'identifier' => $identifier,
			'title' => $title,
			'order' => $order,
			'parentIdentifier' => $parent,
			'isFolder' => $isFolder,
			'resourceIdentifier' => $resource,
		];
	}//end node()

	/**
	 * One resource row.
	 *
	 * @param string $identifier     Identifier.
	 * @param string $classification Classification.
	 *
	 * @return array<string, mixed>
	 */
	private function resource(string $identifier, string $classification): array {
		return [
			'identifier' => $identifier,
			'type' => $classification,
			'classification' => $classification,
			'href' => $identifier . '.html',
			'title' => $identifier . '.html',
		];
	}//end resource()

	/**
	 * The entry the router double returns.
	 *
	 * @param string $identifier Resource identifier.
	 * @param string $outcome    Outcome.
	 *
	 * @return array<string, mixed>
	 */
	private function routedEntry(string $identifier, string $outcome): array {
		return [
			'resourceIdentifier' => $identifier,
			'outcome' => $outcome,
			'targetType' => null,
			'targetId' => null,
			'reason' => null,
		];
	}//end routedEntry()

	/**
	 * The entry a folder node reports.
	 *
	 * @param string $identifier Node identifier.
	 * @param string $title      Node title.
	 * @param string $courseId   Created Course.
	 *
	 * @return array<string, mixed>
	 */
	private function folderEntry(string $identifier, string $title, string $courseId): array {
		return [
			'resourceIdentifier' => $identifier,
			'resourceType' => 'organization',
			'title' => $title,
			'outcome' => 'imported',
			'targetType' => 'course',
			'targetId' => $courseId,
			'reason' => null,
		];
	}//end folderEntry()
}//end class
