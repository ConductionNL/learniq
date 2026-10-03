<?php

/**
 * Learniq lifecycle-versus-appendOnly test.
 *
 * Open Register runs a lifecycle transition as an update of the object
 * (`TransitionEngine::transition()` saves it with its uuid), and
 * `ObjectService::saveObject()` throws `AppendOnlyException` for any update on
 * a schema whose top-level `appendOnly` is true. A schema that is appendOnly
 * and declares transitions therefore refuses every transition. Sixteen
 * schemas were in that state. Each of their specs needs the transitions
 * (revoke a credential, handle a flag, decide a BSA, record a deliberation);
 * the audit ADR-008 asks for is Open Register's audit trail, which keeps every
 * version, so `appendOnly` is what goes.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Register
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
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-a-schema-with-lifecycle-transitions-is-not-append-only
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Register;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Asserts that no declared transition is refused by appendOnly.
 */
class LifecycleSchemasAreNotAppendOnlyTest extends TestCase {

	/**
	 * The register as shipped.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $register = null;

	/**
	 * All schemas of the shipped register.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function schemas(): array {
		if (self::$register === null) {
			self::$register = json_decode(
				(string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'),
				true
			);
		}

		return self::$register['components']['schemas'];
	}//end schemas()

	/**
	 * Run a transition the way Open Register does, as far as appendOnly is
	 * concerned: resolve it from the lifecycle, check the object is in a
	 * state it leaves from, then save it as an update, which
	 * `ObjectService::saveObject()` refuses on an appendOnly schema.
	 *
	 * @param array<string, mixed> $schema The schema.
	 * @param string               $name   The transition name.
	 * @param string               $from   The state the object is in.
	 *
	 * @return string The state the object lands in, or `refused: <reason>`.
	 */
	private static function runTransition(array $schema, string $name, string $from): string {
		$transition = ($schema['x-openregister-lifecycle']['transitions'][$name] ?? null);
		if ($transition === null) {
			return 'refused: no such transition';
		}

		if (in_array($from, (array)$transition['from'], true) === false) {
			return 'refused: not allowed from ' . $from;
		}

		// A missing key is not "false": Open Register's Schema::hydrate() only
		// sets the keys an import carries, so an instance that once stored
		// appendOnly=true keeps it. Only an explicit false lets the update run.
		if (array_key_exists('appendOnly', $schema) === false) {
			return 'refused: appendOnly not declared, so an upgraded instance keeps its stored true';
		}

		if ($schema['appendOnly'] === true) {
			return 'refused: AppendOnlyException (update on an appendOnly schema)';
		}

		return (string)$transition['to'];
	}//end runTransition()

	/**
	 * One transition per schema that was appendOnly with a lifecycle.
	 *
	 * @return array<string, array{string, string, string, string}>
	 */
	public static function transitions(): array {
		return [
			'Credential'               => ['Credential', 'revoke', 'issued', 'revoked'],
			'Attestation'              => ['Attestation', 'sign', 'drafted', 'signed'],
			'ItemRevisionFlag'         => ['ItemRevisionFlag', 'acknowledge', 'open', 'acknowledged'],
			'ProctoringSession'        => ['ProctoringSession', 'activate', 'created', 'active'],
			'LearningPlanEvaluation'   => ['LearningPlanEvaluation', 'record', 'draft', 'recorded'],
			'DeliberationRecord'       => ['DeliberationRecord', 'record', 'scheduled', 'recorded'],
			'BehaviourIncident'        => ['BehaviourIncident', 'startHandling', 'open', 'in-handling'],
			'AttendanceFlag'           => ['AttendanceFlag', 'startHandling', 'open', 'in-handling'],
			'BsaProgressFlag'          => ['BsaProgressFlag', 'startHandling', 'open', 'in-handling'],
			'EngagementRiskFlag'       => ['EngagementRiskFlag', 'resolve', 'open', 'resolved'],
			'BsaWarning'               => ['BsaWarning', 'issue', 'drafted', 'issued'],
			'BsaDecision'              => ['BsaDecision', 'decide', 'drafted', 'decided'],
			'ConferenceReport'         => ['ConferenceReport', 'record', 'draft', 'recorded'],
			'PortfolioShare'           => ['PortfolioShare', 'grant', 'draft', 'active'],
			'CourseEvaluationResponse' => ['CourseEvaluationResponse', 'submit', 'draft', 'submitted'],
			'LvsResult'                => ['LvsResult', 'verify', 'imported', 'verified'],
			'FirstAidIncident'         => ['FirstAidIncident', 'startHandling', 'open', 'in-handling'],
		];
	}//end transitions()

	/**
	 * Each schema's transition lands in its target state instead of being
	 * refused.
	 *
	 * @param string $schema The schema name.
	 * @param string $name   The transition.
	 * @param string $from   The state it leaves from.
	 * @param string $to     The state it lands in.
	 *
	 * @return void
	 */
	#[DataProvider('transitions')]
	public function testTheTransitionRuns(string $schema, string $name, string $from, string $to): void {
		$this->assertSame($to, self::runTransition(schema: self::schemas()[$schema], name: $name, from: $from));
	}//end testTheTransitionRuns()

	/**
	 * Every schema that was ever appendOnly and no longer is declares
	 * `"appendOnly": false` explicitly. #977 removed the key instead, and
	 * upgraded instances kept refusing updates with SCHEMA_APPEND_ONLY
	 * (credential reissue, revoke and the Europass backfill all failed).
	 *
	 * @return void
	 */
	public function testFormerlyAppendOnlySchemasDeclareFalseExplicitly(): void {
		$names = [
			'AssessmentResult', 'AttendanceFlag', 'Attestation', 'BehaviourIncident', 'BsaDecision',
			'BsaProgressFlag', 'BsaWarning', 'ConferenceReport', 'CourseEvaluationResponse', 'Credential',
			'DeliberationRecord', 'EngagementRiskFlag', 'FirstAidIncident', 'ItemRevisionFlag',
			'LearningPlanEvaluation', 'LvsResult', 'PortfolioShare', 'ProctoringSession',
		];
		$schemas = self::schemas();
		$missing = [];
		foreach ($names as $name) {
			if (array_key_exists('appendOnly', $schemas[$name]) === false || $schemas[$name]['appendOnly'] !== false) {
				$missing[] = $name;
			}
		}

		$this->assertSame([], $missing, 'These schemas must declare "appendOnly": false explicitly.');
	}//end testFormerlyAppendOnlySchemasDeclareFalseExplicitly()

	/**
	 * No schema in the register declares transitions and appendOnly together,
	 * so a schema added later cannot ship the same dead lifecycle.
	 *
	 * @return void
	 */
	public function testNoSchemaIsAppendOnlyAndTransitions(): void {
		$both = [];
		foreach (self::schemas() as $name => $schema) {
			if (($schema['appendOnly'] ?? false) === true
				&& empty($schema['x-openregister-lifecycle']['transitions']) === false
			) {
				$both[] = $name;
			}
		}

		$this->assertSame([], $both);
	}//end testNoSchemaIsAppendOnlyAndTransitions()

	/**
	 * Schemas without a lifecycle keep appendOnly: a dossier note and a
	 * wellbeing check-in are corrected by a new record, never edited.
	 *
	 * @return void
	 */
	public function testRecordsWithoutALifecycleStayAppendOnly(): void {
		$schemas = self::schemas();

		$this->assertTrue($schemas['DossierNote']['appendOnly']);
		$this->assertTrue($schemas['WellbeingCheckIn']['appendOnly']);
	}//end testRecordsWithoutALifecycleStayAppendOnly()
}//end class
