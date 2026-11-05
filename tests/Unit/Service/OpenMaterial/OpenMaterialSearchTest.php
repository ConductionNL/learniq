<?php

/**
 * OpenMaterialSearch unit tests.
 *
 * The panel reads the school profile's level and the administrator's
 * switches; a search leaves switched-off collections out unless the panel
 * manages them itself, puts the licence decision on every record, and reports
 * how the source answered to the connections page.
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
 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\OpenMaterial;

use OCA\Learniq\Exception\IntegriqUnavailableException;
use OCA\Learniq\Service\ConnectionReportService;
use OCA\Learniq\Service\OpenMaterial\MaterialImportTarget;
use OCA\Learniq\Service\OpenMaterial\MaterialLicenceRules;
use OCA\Learniq\Service\OpenMaterial\OpenMaterialImportService;
use OCA\Learniq\Service\OpenMaterial\OpenMaterialSearch;
use OCA\Learniq\Service\OpenMaterial\OpenMaterialSettings;
use OCA\Learniq\Service\OpenMaterial\OpenMaterialSourceClient;
use OCA\Learniq\Service\SegmentService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OpenMaterialSearchTest extends TestCase {

	private OpenMaterialSourceClient&MockObject $client;

	private ConnectionReportService&MockObject $reports;

	private SegmentService&MockObject $segments;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->client   = $this->createMock(OpenMaterialSourceClient::class);
		$this->reports  = $this->createMock(ConnectionReportService::class);
		$this->segments = $this->createMock(SegmentService::class);
	}//end setUp()

	/**
	 * The search with default settings: KlasCement off, Schooltv link-allowed.
	 *
	 * @return OpenMaterialSearch The service.
	 */
	private function search(): OpenMaterialSearch {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);
		$settings = new OpenMaterialSettings($appConfig);
		$imports  = new OpenMaterialImportService(
			$this->client,
			new MaterialLicenceRules(),
			$settings,
			$this->createMock(MaterialImportTarget::class),
			$this->createMock(ObjectService::class)
		);

		return new OpenMaterialSearch($this->client, $imports, $settings, $this->segments, $this->reports, $this->createMock(MaterialImportTarget::class));
	}//end search()

	public function testThePanelTakesTheLevelFromTheSchoolProfile(): void {
		$this->segments->method('currentSegment')->willReturn('vo');
		$this->client->method('isAvailable')->willReturn(true);

		$panel = $this->search()->panel();

		$this->assertSame(expected: ['VO'], actual: $panel['defaultLevels']);
		$this->assertSame(expected: ['KlasCement'], actual: $panel['collectionsOff']);
		$this->assertTrue($panel['enabled']);
	}//end testThePanelTakesTheLevelFromTheSchoolProfile()

	public function testASearchLeavesKlasCementOutAndDecidesOnEveryRecord(): void {
		$sent = null;
		$this->client->method('search')->willReturnCallback(
			static function (array $request) use (&$sent): array {
				$sent = $request;
				return ['total' => 1, 'records' => [['sourceId' => 'a', 'license' => 'CC-BY-ND-4.0', 'accessRights' => 'OpenAccess', 'files' => []]]];
			}
		);
		$this->reports->expects($this->once())->method('observe')->with('open-material-edurep', 'configured');

		$result = $this->search()->search(request: ['query' => ' betoog ', 'levels' => ['VO']]);

		$this->assertSame(expected: ['query' => 'betoog', 'filters' => ['levels' => ['VO'], 'excludeCollections' => ['KlasCement']], 'page' => 1], actual: $sent);
		$this->assertSame(expected: ['mode' => 'link', 'reason' => 'no-derivatives', 'nonCommercial' => false], actual: $result['records'][0]['decision']);
	}//end testASearchLeavesKlasCementOutAndDecidesOnEveryRecord()

	public function testThePanelManagesTheExcludedCollectionsItself(): void {
		$sent = null;
		$this->client->method('search')->willReturnCallback(
			static function (array $request) use (&$sent): array {
				$sent = $request;
				return ['total' => 0, 'records' => []];
			}
		);

		$this->search()->search(request: ['query' => 'betoog', 'excludeCollections' => [], 'includeCollectionsOff' => true]);

		$this->assertArrayNotHasKey(key: 'excludeCollections', array: $sent['filters']);
	}//end testThePanelManagesTheExcludedCollectionsItself()

	public function testASourceThatDoesNotAnswerIsReportedAsAnError(): void {
		$this->client->method('search')->willThrowException(new IntegriqUnavailableException('Edurep did not answer.'));
		$this->reports->expects($this->once())->method('observe')->with('open-material-edurep', 'error', 'Edurep did not answer.');
		$this->expectException(IntegriqUnavailableException::class);

		$this->search()->search(request: ['query' => 'betoog']);
	}//end testASourceThatDoesNotAnswerIsReportedAsAnError()

	public function testARecordThatIsGoneIsNull(): void {
		$this->client->method('find')->willReturn(null);

		$this->assertNull($this->search()->record(sourceId: 'urn:test:gone'));
	}//end testARecordThatIsGoneIsNull()
}//end class
