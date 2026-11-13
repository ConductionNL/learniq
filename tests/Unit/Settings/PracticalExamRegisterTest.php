<?php

/**
 * Learniq register: the exam assessor assignment and the practical exam assessment.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
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
 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use OCA\Learniq\Tests\Support\RegisterSchemaPayloads;
use PHPUnit\Framework\TestCase;

/**
 * The two records an assessor's exam day hangs off, read from the shipped register.
 */
class PracticalExamRegisterTest extends TestCase {
	use RegisterSchemaPayloads;

	private const TENANT = '00000000-0000-4000-8000-000000000000';
	private const SITTING = '22222222-2222-4222-8222-222222222222';
	private const ASSESSOR = '33333333-3333-4333-8333-333333333333';
	private const CANDIDATE = '44444444-4444-4444-8444-444444444444';

	/**
	 * The strings of a catalogue.
	 *
	 * @param string $locale The locale.
	 *
	 * @return array<string, string>
	 */
	private static function catalogue(string $locale): array {
		$file = json_decode((string)file_get_contents(__DIR__ . '/../../../l10n/' . $locale . '.json'), true);

		return $file['translations'];
	}//end catalogue()

	/**
	 * An assignment names a sitting, an assessor, a candidate and a role.
	 *
	 * @return void
	 */
	public function testAnAssignmentLinksAnAssessorToOneCandidateAtOneSitting(): void {
		$assignment = [
			'examSittingId' => self::SITTING,
			'externalAssessorId' => self::ASSESSOR,
			'learnerRef' => self::CANDIDATE,
			'role' => 'first',
			'lifecycle' => 'assigned',
			'tenant_id' => self::TENANT,
		];

		self::assertNull(self::schemaError(slug: 'exam-assessor-assignment', payload: $assignment));
		self::assertNull(self::schemaError(slug: 'exam-assessor-assignment', payload: array_merge($assignment, ['role' => 'second'])));
		self::assertNotNull(self::schemaError(slug: 'exam-assessor-assignment', payload: array_merge($assignment, ['role' => 'third'])), 'control: two roles only');

		$noAssessor = $assignment;
		unset($noAssessor['externalAssessorId']);
		self::assertNotNull(self::schemaError(slug: 'exam-assessor-assignment', payload: $noAssessor), 'control: an assignment names its assessor');

		$schema = self::shippedSchema(slug: 'exam-assessor-assignment');
		self::assertSame('ExternalAssessor', $schema['properties']['externalAssessorId']['$ref']);
		self::assertSame('ExamSitting', $schema['properties']['examSittingId']['$ref']);
	}//end testAnAssignmentLinksAnAssessorToOneCandidateAtOneSitting()

	/**
	 * A draft assessment with some criteria scored is a valid row; so is a submitted one.
	 *
	 * @return void
	 */
	public function testAnAssessmentKeepsADraftAndItsAssessor(): void {
		$draft = [
			'examSittingId' => self::SITTING,
			'learnerRef' => self::CANDIDATE,
			'assessmentModelId' => null,
			'criteria' => [
				['criterionId' => 'veiligheid', 'label' => 'Werkt veilig', 'score' => 3],
				['criterionId' => 'planning'],
			],
			'assessorId' => self::ASSESSOR,
			'assessorName' => 'Ruud de Wit',
			'assuranceLevel' => 'basic',
			'lifecycle' => 'draft',
			'tenant_id' => self::TENANT,
		];

		self::assertNull(self::schemaError(slug: 'practical-exam-assessment', payload: $draft));
		self::assertNull(self::schemaError(slug: 'practical-exam-assessment', payload: array_merge($draft, ['outcome' => 'pass', 'lifecycle' => 'submitted'])));
		self::assertNotNull(self::schemaError(slug: 'practical-exam-assessment', payload: array_merge($draft, ['outcome' => 'maybe'])), 'control: pass or fail');
		self::assertNotNull(self::schemaError(slug: 'practical-exam-assessment', payload: array_merge($draft, ['assuranceLevel' => 'very-sure'])), 'control: the eIDAS vocabulary');
		self::assertNotNull(self::schemaError(slug: 'practical-exam-assessment', payload: array_merge($draft, ['criteria' => [['score' => 2]]])), 'control: a score names its criterion');

		$schema = self::shippedSchema(slug: 'practical-exam-assessment');
		self::assertSame(
			self::shippedSchema(slug: 'werkproces-assessment')['properties']['assuranceLevel']['enum'],
			$schema['properties']['assuranceLevel']['enum'],
			'the same assurance vocabulary as a werkproces assessment'
		);
	}//end testAnAssessmentKeepsADraftAndItsAssessor()

	/**
	 * Staff read both; nobody writes an assessment through the object API.
	 *
	 * @return void
	 */
	public function testStaffReadAndTheAssessmentIsWrittenByTheServer(): void {
		$staff = ['instructors', 'hr', 'compliance-officers', 'team-leads'];

		self::assertSame(['read' => $staff, 'create' => $staff, 'update' => $staff], self::shippedSchema(slug: 'exam-assessor-assignment')['authorization']);
		self::assertSame(['read' => $staff], self::shippedSchema(slug: 'practical-exam-assessment')['authorization']);
	}//end testStaffReadAndTheAssessmentIsWrittenByTheServer()

	/**
	 * Every title, description and label the forms show has English and Dutch.
	 *
	 * @return void
	 */
	public function testTheFormStringsAreTranslated(): void {
		$en = self::catalogue(locale: 'en');
		$nl = self::catalogue(locale: 'nl');

		foreach (['exam-assessor-assignment', 'practical-exam-assessment'] as $slug) {
			$schema = self::shippedSchema(slug: $slug);
			$strings = [$schema['title'], $schema['description']];
			foreach ($schema['properties'] as $property) {
				$strings[] = $property['title'];
				$strings[] = $property['description'];
				foreach (($property['x-enum-labels'] ?? []) as $label) {
					$strings[] = $label;
				}
			}

			foreach ($strings as $string) {
				self::assertArrayHasKey($string, $en, $string);
				self::assertArrayHasKey($string, $nl, $string);
			}
		}
	}//end testTheFormStringsAreTranslated()
}//end class
