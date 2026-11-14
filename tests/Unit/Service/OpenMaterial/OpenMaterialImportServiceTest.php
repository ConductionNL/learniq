<?php

/**
 * OpenMaterialImportService unit tests.
 *
 * Adding a source record must decide before it writes: an ND record asked as
 * a copy is refused without a fetch, a restricted file never lands, NC
 * material does not go into a paid course, and the attribution is always
 * stored from the record the server looked up.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\OpenMaterial
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\OpenMaterial;

use OCA\Learniq\Exception\MaterialImportRefusedException;
use OCA\Learniq\Service\OpenMaterial\MaterialImportTarget;
use OCA\Learniq\Service\OpenMaterial\MaterialLicenceRules;
use OCA\Learniq\Service\OpenMaterial\OpenMaterialImportService;
use OCA\Learniq\Service\OpenMaterial\OpenMaterialSettings;
use OCA\Learniq\Service\OpenMaterial\OpenMaterialSourceClient;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OpenMaterialImportServiceTest extends TestCase {

	private const WIKIWIJS = [
		'source'       => 'edurep',
		'sourceId'     => 'urn:wikiwijsmaken:194413',
		'title'        => 'Het schrijven van een betoog',
		'url'          => 'https://maken.wikiwijs.nl/194413/Het_schrijven_van_een_betoog',
		'files'        => [['url' => 'https://maken.wikiwijs.nl/194413', 'format' => 'text/html']],
		'authors'      => ['Kirstin Beekman'],
		'license'      => 'CC-BY-4.0',
		'accessRights' => 'OpenAccess',
		'materialType' => 'application/x-Wikiwijs-Arrangement',
		'collection'   => 'wikiwijsmaken',
	];

	private OpenMaterialSourceClient&MockObject $client;

	private MaterialImportTarget&MockObject $targets;

	private ObjectService&MockObject $objectService;

	private IUser&MockObject $user;

	/**
	 * Wire the service with the real rules and default settings.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->client        = $this->createMock(OpenMaterialSourceClient::class);
		$this->targets       = $this->createMock(MaterialImportTarget::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->user          = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('docent');
		$this->targets->method('resolve')->willReturnCallback(
			static fn (IUser $user, array $request): array => ['fields' => ['lessonId' => 'les-7', 'courseId' => 'course-1', 'tenant_id' => 't-1'], 'commercial' => ($request['courseId'] ?? '') === 'paid']
		);
		$this->objectService->method('saveObject')->willReturnCallback(static fn (array $object): ObjectEntity => (new ObjectEntity())->hydrateObject($object));
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return OpenMaterialImportService The service.
	 */
	private function service(): OpenMaterialImportService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		return new OpenMaterialImportService(
			$this->client,
			new MaterialLicenceRules(),
			new OpenMaterialSettings($appConfig),
			$this->targets,
			$this->objectService
		);
	}//end service()

	/**
	 * The spec's scenario: a linked Wikiwijs arrangement stores its attribution.
	 *
	 * @return void
	 */
	public function testALinkedArrangementStoresItsAttribution(): void {
		$this->client->method('find')->willReturn(self::WIKIWIJS);

		$material = $this->service()->add(user: $this->user, request: ['sourceId' => 'urn:wikiwijsmaken:194413', 'lessonId' => 'les-7']);

		$this->assertSame(expected: 'link', actual: $material['kind']);
		$this->assertSame(expected: 'edurep', actual: $material['source']);
		$this->assertSame(expected: 'urn:wikiwijsmaken:194413', actual: $material['sourceId']);
		$this->assertSame(expected: self::WIKIWIJS['url'], actual: $material['sourceUrl']);
		$this->assertSame(expected: ['Kirstin Beekman'], actual: $material['authors']);
		$this->assertSame(expected: 'CC-BY-4.0', actual: $material['license']);
		$this->assertSame(expected: 'wikiwijsmaken', actual: $material['sourceCollection']);
		$this->assertArrayNotHasKey(key: 'fileRef', array: $material);
	}//end testALinkedArrangementStoresItsAttribution()

	/**
	 * The spec's scenario: an ND record asked as a copy is refused, naming ND, before anything is fetched or written.
	 *
	 * @return void
	 */
	public function testANoDerivativesCopyIsRefusedBeforeAnyWrite(): void {
		$this->client->method('find')->willReturn($this->openPdf(licence: 'CC-BY-NC-ND-3.0'));
		$this->objectService->expects($this->never())->method('saveObject');

		try {
			$this->service()->add(user: $this->user, request: ['sourceId' => 'urn:test:1', 'lessonId' => 'les-7', 'mode' => 'copy']);
			$this->fail('An ND copy must be refused.');
		} catch (MaterialImportRefusedException $exception) {
			$this->assertSame(expected: 'no-derivatives', actual: $exception->getReasonCode());
			$this->assertStringContainsString(needle: 'ND', haystack: $exception->getMessage());
		}
	}//end testANoDerivativesCopyIsRefusedBeforeAnyWrite()

	/**
	 * An ND record without a mode becomes a link with no file.
	 *
	 * @return void
	 */
	public function testANoDerivativesRecordIsLinked(): void {
		$this->client->method('find')->willReturn($this->openPdf(licence: 'CC-BY-NC-ND-3.0'));

		$material = $this->service()->add(user: $this->user, request: ['sourceId' => 'urn:test:1', 'lessonId' => 'les-7']);

		$this->assertSame(expected: 'link', actual: $material['kind']);
		$this->assertTrue($material['nonCommercial']);
		$this->assertArrayNotHasKey(key: 'fileRef', array: $material);
	}//end testANoDerivativesRecordIsLinked()

	/**
	 * The spec's scenario: a restricted file never lands.
	 *
	 * @return void
	 */
	public function testARestrictedFileNeverLands(): void {
		$this->client->method('find')->willReturn(array_merge($this->openPdf(licence: 'CC-BY-4.0'), ['accessRights' => 'RestrictedAccess']));

		$material = $this->service()->add(user: $this->user, request: ['sourceId' => 'urn:test:1', 'lessonId' => 'les-7']);

		$this->assertSame(expected: 'link', actual: $material['kind']);
		$this->assertSame(expected: 'RestrictedAccess', actual: $material['accessRights']);
	}//end testARestrictedFileNeverLands()

	/**
	 * A record the rules allow to copy is linked while integriq offers no download, with its attribution and NC flag.
	 *
	 * @return void
	 */
	public function testACopyableRecordIsLinkedWhileIntegriqOffersNoDownload(): void {
		$this->client->method('find')->willReturn($this->openPdf(licence: 'CC-BY-NC-4.0'));

		$material = $this->service()->add(user: $this->user, request: ['sourceId' => 'urn:test:1', 'lessonId' => 'les-7']);

		$this->assertSame(expected: ['link', true, 'https://example.org/record/1'], actual: [$material['kind'], $material['nonCommercial'], $material['sourceUrl']]);
		$this->assertArrayNotHasKey(key: 'fileRef', array: $material);
		$this->assertSame(expected: ['J. Roenhorst'], actual: $material['authors']);
	}//end testACopyableRecordIsLinkedWhileIntegriqOffersNoDownload()

	/**
	 * The panel offers a link for a copyable record, so it never asks for a copy it cannot make.
	 *
	 * @return void
	 */
	public function testThePanelOffersALinkForACopyableRecord(): void {
		$decision = $this->service()->withDecision(record: $this->openPdf(licence: 'CC-BY-4.0'))['decision'];

		$this->assertSame(expected: ['mode' => 'link', 'reason' => 'copy-not-offered', 'nonCommercial' => false], actual: $decision);
	}//end testThePanelOffersALinkForACopyableRecord()

	/**
	 * NC material does not go into a paid or corporate course.
	 *
	 * @return void
	 */
	public function testNonCommercialMaterialIsRefusedInAPaidCourse(): void {
		$this->client->method('find')->willReturn($this->openPdf(licence: 'CC-BY-NC-4.0'));
		$this->objectService->expects($this->never())->method('saveObject');
		try {
			$this->service()->add(user: $this->user, request: ['sourceId' => 'urn:test:1', 'courseId' => 'paid']);
			$this->fail('NC material must not go into a paid course.');
		} catch (MaterialImportRefusedException $exception) {
			$this->assertSame(expected: 'non-commercial-in-paid-course', actual: $exception->getReasonCode());
		}
	}//end testNonCommercialMaterialIsRefusedInAPaidCourse()

	/**
	 * A record without an open licence outside the allowed collections is refused.
	 *
	 * @return void
	 */
	public function testARecordWithoutAnOpenLicenceIsRefused(): void {
		$this->client->method('find')->willReturn(array_merge($this->openPdf(licence: 'rights-reserved'), ['collection' => 'artik']));
		$this->objectService->expects($this->never())->method('saveObject');
		$this->expectException(MaterialImportRefusedException::class);

		$this->service()->add(user: $this->user, request: ['sourceId' => 'urn:test:1', 'lessonId' => 'les-7']);
	}//end testARecordWithoutAnOpenLicenceIsRefused()

	/**
	 * A Schooltv record is a link to schooltv.nl, never a video in learniq.
	 *
	 * @return void
	 */
	public function testASchooltvRecordIsALinkOnly(): void {
		$schooltv = ['sourceId' => '/NTR/POMS/WO_NTR_20306689', 'title' => 'Andere Tijden', 'url' => 'https://schooltv.nl/item/WO_NTR_20306689', 'files' => [], 'authors' => ['Schooltv'], 'license' => 'rights-reserved', 'accessRights' => null, 'materialType' => 'video/H264', 'collection' => 'Schooltv', 'embedUrl' => null];
		$this->client->method('find')->willReturn($schooltv);

		$material = $this->service()->add(user: $this->user, request: ['sourceId' => '/NTR/POMS/WO_NTR_20306689', 'lessonId' => 'les-7']);

		$this->assertSame(expected: ['link', 'https://schooltv.nl/item/WO_NTR_20306689', 'other'], actual: [$material['kind'], $material['url'], $material['license']]);
	}//end testASchooltvRecordIsALinkOnly()

	/**
	 * An open-access pdf record.
	 *
	 * @param string $licence The licence code.
	 *
	 * @return array<string, mixed> The record.
	 */
	private function openPdf(string $licence): array {
		return [
			'source'       => 'edurep',
			'sourceId'     => 'urn:test:1',
			'title'        => 'Opdrachtomschrijving betoog',
			'url'          => 'https://example.org/record/1',
			'files'        => [['url' => 'https://example.org/opdracht.pdf', 'format' => 'application/pdf']],
			'authors'      => ['J. Roenhorst'],
			'license'      => $licence,
			'accessRights' => 'OpenAccess',
			'materialType' => 'application/pdf',
			'collection'   => 'surfsharekit',
		];
	}//end openPdf()
}//end class
