<?php

/**
 * Tests for DataExchangePayloadBuilder::composeRecords(): what may leave for
 * one exchange job, per integriq mapping.
 *
 * @category Test
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
 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-what-may-leave-is-decided-by-learniq-per-mapping
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\DataExchangePayloadBuilder;
use OCA\Learniq\Service\DataExchangeTransformer;
use OCA\Learniq\Service\ExchangeDisclosure;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * The record composer over an in-memory store.
 */
class DataExchangePayloadBuilderTest extends TestCase {

	/**
	 * Rows by schema.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * The builder over the rows.
	 *
	 * @return DataExchangePayloadBuilder The builder.
	 */
	private function builder(): DataExchangePayloadBuilder {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], ...$rest): array {
				$filters = $config['filters'] ?? [];
				$out = [];
				foreach (($this->rows[(string)($filters['schema'] ?? '')] ?? []) as $row) {
					$keep = true;
					foreach ($filters as $field => $value) {
						if (in_array($field, ['register', 'schema', 'tenant_id'], true) === false && ($row[$field] ?? null) !== $value) {
							$keep = false;
						}
					}

					if ($keep === true) {
						$out[] = OrEntityFactory::make($row, (string)$filters['schema']);
					}
				}

				return $out;
			}
		);

		return new DataExchangePayloadBuilder($objects, new DataExchangeTransformer($objects), new ExchangeDisclosure());
	}//end builder()

	/**
	 * An OSO export resolves the BRIN from the cohort; recordIds narrow the scope.
	 *
	 * @return void
	 */
	public function testAnOsoExportResolvesTheBrin(): void {
		$this->rows['cohort'] = [['id' => 'c-1', 'brinNumber' => '12AB']];
		$this->rows['learner-profile'] = [
			['id' => 'lp-1', 'eckId' => 'eck-1', 'givenName' => 'Sanne', 'familyName' => 'Bakker', 'birthDate' => '2014-03-02', 'cohortId' => 'c-1'],
			['id' => 'lp-2', 'eckId' => 'eck-2', 'givenName' => 'Daan', 'familyName' => 'de Vries', 'birthDate' => '2014-05-20', 'cohortId' => 'c-1'],
		];

		$records = $this->builder()->composeRecords('oso', 'learniq-oso-export-dossier', ['schema' => 'learner-profile', 'recordIds' => ['lp-2']], '');

		$this->assertCount(1, $records);
		$this->assertSame('lp-2', $records[0]['recordId']);
		$this->assertSame(['eckId', 'givenName', 'familyName', 'birthDate', 'schoolBrin'], array_keys($records[0]['data']));
		$this->assertSame('12AB', $records[0]['data']['schoolBrin']);
	}//end testAnOsoExportResolvesTheBrin()

	/**
	 * The SWV file always carries its learner and plan sections, null without a plan.
	 *
	 * @return void
	 */
	public function testTheSwvFileAlwaysCarriesItsSections(): void {
		$this->rows['support-request'] = [['id' => 'sr-1', 'supportDomain' => 'gedrag', 'description' => 'Hulp gevraagd', 'urgency' => 'hoog', 'learnerId' => 'pupil-1']];
		$this->rows['learner-profile'] = [['id' => 'lp-1', 'ncUserId' => 'pupil-1', 'eckId' => 'eck-1', 'givenName' => 'Sanne', 'bsnEncrypted' => 'SECRET']];

		$records = $this->builder()->composeRecords('swv', 'learniq-swv-export-zorgvraag', ['schema' => 'support-request'], '');

		$data = $records[0]['data'];
		$this->assertSame('gedrag', $data['supportDomain']);
		$this->assertSame('eck-1', $data['learner']['eckId']);
		$this->assertArrayHasKey('learningPlanContext', $data, 'integriq copies the key; an absent key would be rendered as its own name.');
		$this->assertNull($data['learningPlanContext']);
		$this->assertStringNotContainsString('SECRET', (string)json_encode($records));
	}//end testTheSwvFileAlwaysCarriesItsSections()

	/**
	 * A non-statutory target without a mapping gets the object minus BSN, email and metadata.
	 *
	 * @return void
	 */
	public function testAPassThroughStripsWhatNeverLeaves(): void {
		$this->rows['learner-profile'] = [['id' => 'lp-1', 'givenName' => 'Sanne', 'bsnEncrypted' => 'SECRET', 'bsnHash' => 'H', 'email' => 'x@y']];

		$records = $this->builder()->composeRecords('hr', null, ['schema' => 'learner-profile'], '');

		$this->assertSame('Sanne', $records[0]['data']['givenName']);
		foreach (['bsnEncrypted', 'bsnHash', 'email', '@self'] as $never) {
			$this->assertArrayNotHasKey($never, $records[0]['data']);
		}
	}//end testAPassThroughStripsWhatNeverLeaves()
}//end class
