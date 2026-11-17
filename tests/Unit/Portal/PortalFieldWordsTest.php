<?php

/**
 * PortalFieldWords test.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-fields-read-in-words/specs/portal-contribution/spec.md#requirement-every-field-a-portal-reader-sees-reads-in-words
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\PortalContributionProvider;
use OCA\Learniq\Portal\PortalFieldWords;
use PHPUnit\Framework\TestCase;

/**
 * Every field a portal reader sees has a label in words, and every fixed set
 * of values reads as words, in English and in Dutch.
 *
 * @spec openspec/changes/portal-fields-read-in-words/specs/portal-contribution/spec.md#requirement-every-field-a-portal-reader-sees-reads-in-words
 */
class PortalFieldWordsTest extends TestCase {

	/**
	 * Fields the portal leaves out of a cell: references and the tenant.
	 */
	private const NOT_READ = ['tenant_id', 'organisationRef', 'learnerRef', 'learnerId'];

	/**
	 * Every projected field of every audience ends with a label that has a
	 * Dutch entry, and every field with a fixed set of values has words for
	 * each value, with a Dutch entry too.
	 *
	 * @return void
	 */
	public function testEveryReadFieldHasALabelAndEveryValueWords(): void {
		$dutch = json_decode((string)file_get_contents(__DIR__ . '/../../../l10n/nl.json'), true)['translations'];
		$properties = PortalFieldWords::properties();
		$provider = new PortalContributionProvider();
		$checked = 0;
		foreach ($provider->getAudiences() as $audience) {
			foreach ($provider->getContribution(['audience' => $audience])['collections'] as $collection) {
				foreach ($collection['fields'] ?? [] as $field) {
					$property = ($properties[$collection['schema']][$field] ?? null);
					if ($property === null || self::isReference(field: $field, property: $property) === true) {
						continue;
					}

					$where = $audience . ' ' . $collection['id'] . '.' . $field;
					$config = ($collection['fieldConfigs'][$field] ?? []);
					self::assertArrayHasKey('label', $config, $where);
					self::assertArrayHasKey($config['label'], $dutch, $where . ': "' . $config['label'] . '"');
					foreach (array_filter((array)($property['enum'] ?? []), 'is_string') as $value) {
						self::assertArrayHasKey($value, ($config['valueLabels'] ?? []), $where . ' value ' . $value);
						self::assertArrayHasKey($config['valueLabels'][$value], $dutch, $where . ' value ' . $value);
					}

					$checked++;
				}
			}
		}

		self::assertGreaterThan(300, $checked);
	}//end testEveryReadFieldHasALabelAndEveryValueWords()

	/**
	 * A declared label and declared value labels are kept; a column's label
	 * is the field's label; the schema's own value words fill the rest.
	 *
	 * @return void
	 */
	public function testDeclaredWordsWinAndTheSchemaFillsTheRest(): void {
		$collection = (new PortalFieldWords())->collection(
			[
				'schema' => 'excuse-request',
				'fields' => ['learnerRef', 'reasonKind', 'lifecycle', 'dateFrom'],
				'columns' => [['field' => 'dateFrom', 'label' => 'First day']],
				'fieldConfigs' => ['reasonKind' => ['label' => 'Why', 'valueLabels' => ['illness' => 'Sick']]],
			]
		);

		self::assertSame(['label' => 'Why', 'valueLabels' => ['illness' => 'Sick']], $collection['fieldConfigs']['reasonKind']);
		self::assertSame('First day', $collection['fieldConfigs']['dateFrom']['label']);
		self::assertSame('Status', $collection['fieldConfigs']['lifecycle']['label']);
		self::assertSame('Approved', $collection['fieldConfigs']['lifecycle']['valueLabels']['approved']);
		self::assertArrayNotHasKey('learnerRef', $collection['fieldConfigs']);
	}//end testDeclaredWordsWinAndTheSchemaFillsTheRest()

	/**
	 * A late arrival reads its minutes late, not the minutes present that
	 * read 330 or 335 on a "Te laat" row, and the date without seconds.
	 *
	 * @return void
	 */
	public function testAttendanceReadsMinutesLate(): void {
		$provider = new PortalContributionProvider();
		foreach (['parent' => 'parentAttendance', 'student' => 'studentAttendance'] as $audience => $id) {
			$collection = array_column($provider->getContribution(['audience' => $audience])['collections'], null, 'id')[$id];
			self::assertContains('lateMinutes', $collection['fields'], $id);
			self::assertNotContains('minutesAttended', $collection['fields'], $id);
			self::assertSame('date', array_column($collection['columns'], null, 'field')['markedAt']['render'], $id);
		}
	}//end testAttendanceReadsMinutesLate()

	/**
	 * Whether a field is a reference the portal never shows as a value.
	 *
	 * @param string               $field    The field.
	 * @param array<string, mixed> $property The schema property.
	 *
	 * @return bool
	 */
	private static function isReference(string $field, array $property): bool {
		return in_array($field, self::NOT_READ, true) === true
			|| ($property['format'] ?? null) === 'uuid'
			|| isset($property['$ref']) === true
			|| (($property['items']['format'] ?? null) === 'uuid');
	}//end isReference()
}//end class
