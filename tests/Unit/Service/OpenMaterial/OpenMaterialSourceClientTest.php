<?php

/**
 * OpenMaterialSourceClient unit tests.
 *
 * The client is the one place the integriq contract lives: it fails closed
 * without integriq, turns every refusal into the source-down state in its
 * own words (never integriq's Dutch reason), and reads the answer slot.
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

use OCA\Integriq\Event\LearningMaterialRequestedEvent;
use OCA\Learniq\Exception\IntegriqUnavailableException;
use OCA\Learniq\Service\OpenMaterial\OpenMaterialSourceClient;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

class OpenMaterialSourceClientTest extends TestCase {

	/**
	 * A client whose dispatcher lets integriq answer with the given handler.
	 *
	 * @param callable(LearningMaterialRequestedEvent): void $handler   What integriq does.
	 * @param bool                                           $installed Whether integriq is enabled.
	 *
	 * @return OpenMaterialSourceClient The client.
	 */
	private function client(callable $handler, bool $installed = true): OpenMaterialSourceClient {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback($handler);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn($installed);

		return new OpenMaterialSourceClient($dispatcher, $apps);
	}//end client()

	public function testASearchSendsTheContractRequestAndReadsTheAnswer(): void {
		$seen   = null;
		$client = $this->client(
			static function (LearningMaterialRequestedEvent $event) use (&$seen): void {
				$seen = [$event->getSourceApp(), $event->getRequest()];
				$event->answer(answer: ['total' => 242, 'records' => []]);
			}
		);

		$result = $client->search(request: ['query' => 'betoog', 'filters' => ['levels' => ['VO'], 'collections' => ['wikiwijsmaken']], 'page' => 2, 'facets' => false]);

		$this->assertSame(
			expected: ['learniq', ['source' => 'edurep', 'operation' => 'search', 'query' => 'betoog', 'filters' => ['levels' => ['VO'], 'collection' => ['wikiwijsmaken'], 'pageSize' => 10, 'facets' => false], 'page' => 2]],
			actual: $seen
		);
		$this->assertSame(expected: 242, actual: $result['total']);
	}//end testASearchSendsTheContractRequestAndReadsTheAnswer()

	public function testWithoutIntegriqTheClientFailsClosed(): void {
		$this->expectException(IntegriqUnavailableException::class);

		$this->client(static function (): void {
		}, false)->search(request: ['query' => 'betoog', 'filters' => [], 'page' => 1]);
	}//end testWithoutIntegriqTheClientFailsClosed()

	public function testARefusalIsUnavailableInLearniqsWordsNotIntegriqs(): void {
		$this->expectException(IntegriqUnavailableException::class);
		$this->expectExceptionMessage('The source did not answer. (source-unavailable)');

		$this->client(
			static fn (LearningMaterialRequestedEvent $event) => $event->refuse(refusal: ['code' => 'source-unavailable', 'reason' => 'Edurep antwoordt niet.', 'source' => 'edurep'])
		)->find(sourceId: 'urn:test:1');
	}//end testARefusalIsUnavailableInLearniqsWordsNotIntegriqs()

	public function testAnUnknownCodeStillReadsAsFailed(): void {
		$this->expectExceptionMessage('Integriq could not reach the source. (something-new)');

		$this->client(static fn (LearningMaterialRequestedEvent $event) => $event->refuse(refusal: ['code' => 'something-new', 'reason' => 'Iets nieuws.']))
			->find(sourceId: 'urn:test:1');
	}//end testAnUnknownCodeStillReadsAsFailed()

	public function testNobodyHandlingTheEventIsUnavailable(): void {
		$this->expectException(IntegriqUnavailableException::class);

		$this->client(static function (): void {
		})->find(sourceId: 'urn:test:1');
	}//end testNobodyHandlingTheEventIsUnavailable()

	public function testFindSendsTheIdAndReturnsNullWhenTheSourceHasNoRecord(): void {
		$seen   = null;
		$client = $this->client(
			static function (LearningMaterialRequestedEvent $event) use (&$seen): void {
				$seen = $event->getRequest();
				$event->answer(answer: null);
			}
		);

		$this->assertNull($client->find(sourceId: 'urn:test:gone'));
		$this->assertSame(expected: ['source' => 'edurep', 'operation' => 'find', 'id' => 'urn:test:gone'], actual: $seen);
	}//end testFindSendsTheIdAndReturnsNullWhenTheSourceHasNoRecord()

	public function testFindReturnsTheRecord(): void {
		$client = $this->client(static fn (LearningMaterialRequestedEvent $event) => $event->answer(answer: ['sourceId' => 'urn:wikiwijsmaken:194413']));

		$this->assertSame(expected: ['sourceId' => 'urn:wikiwijsmaken:194413'], actual: $client->find(sourceId: 'urn:wikiwijsmaken:194413'));
	}//end testFindReturnsTheRecord()
}//end class
