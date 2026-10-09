<?php

/**
 * Learniq student self-assessment
 *
 * The student's own estimate per work process of her placement (board
 * esdoornveen, Detail "Werkprocessen"): the "Nu invullen" update action and
 * the self-assessment page the "Volgende stap" card opens. Like the other
 * portal declaration classes it is plain: no portaliq imports and no
 * constructor dependencies.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
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
 * @spec openspec/changes/guardian-tasks-per-child-and-self-assessment/specs/portal-contribution/spec.md#requirement-the-student-fills-in-her-own-estimate-per-work-process
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * The student's "Nu invullen" and her self-assessment page.
 *
 * @spec openspec/changes/guardian-tasks-per-child-and-self-assessment/specs/portal-contribution/spec.md#requirement-the-student-fills-in-her-own-estimate-per-work-process
 */
class StudentSelfAssessment {

	private const REGISTER = 'learniq';

	/**
	 * Her "Nu invullen" on a work process row.
	 */
	public const ACTION = 'fillInSelfAssessment';

	/**
	 * The page the "Volgende stap" card opens.
	 */
	public const PAGE = 'studentSelfAssessment';

	/**
	 * "Nu invullen": she fills in her own estimate on one of her own work
	 * process rows (board Detail, "Werkprocessen").
	 *
	 * An update of one field. Portaliq re-reads the row under her own claim
	 * before it writes (the row's `learnerRef` must be hers) and writes only
	 * the fields the action names, so the hours and every other field stay as
	 * they are. The trainer's judgement lives in another schema
	 * (`werkproces-assessment`) that this action never reaches.
	 *
	 * @return array<string, mixed> The update action.
	 *
	 * @spec openspec/changes/guardian-tasks-per-child-and-self-assessment/specs/portal-contribution/spec.md#requirement-the-student-fills-in-her-own-estimate-per-work-process
	 */
	public function action(): array {
		return [
			'id' => self::ACTION,
			'type' => 'update',
			'label' => 'Fill in now',
			'register' => self::REGISTER,
			'schema' => 'werkproces-progress',
			'scopeField' => 'learnerRef',
			'scopeClaim' => 'learnerRef',
			'minTrust' => 'low',
			'fields' => ['selfAssessment'],
			'requiredFields' => ['selfAssessment'],
			'optionsProviders' => [
				'selfAssessment' => [
					'type' => 'static',
					'options' => array_map(
						static fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
						array_keys(PortalValueLabels::SELF_ASSESSMENT),
						array_values(PortalValueLabels::SELF_ASSESSMENT)
					),
				],
			],
			'fieldConfigs' => [
				'selfAssessment' => [
					'label' => 'Your estimate',
					'required' => true,
					'widget' => 'choices',
					'valueLabels' => PortalValueLabels::SELF_ASSESSMENT,
				],
			],
			'submitLabel' => 'Save my estimate',
			'successMessage' => 'Your estimate is saved. Your workplace trainer and your coach see it at the interim assessment.',
		];
	}//end action()

	/**
	 * The self-assessment of her open placement, the page the "Volgende
	 * stap" card opens. No board draws it, so it is the minimum: her work
	 * processes with the hours and her estimate, each with "Nu invullen".
	 * A record page on her placement with no list of placements on it, so a
	 * link to a placement still opens the placement page.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/guardian-tasks-per-child-and-self-assessment/specs/portal-contribution/spec.md#requirement-the-next-step-card-opens-her-self-assessment
	 */
	public function page(): array {
		return [
			'id' => self::PAGE,
			'label' => 'Self-assessment',
			'menu' => false,
			'record' => ['collection' => 'studentBpvPlacements', 'titleFields' => ['trainingCompanyName']],
			'blocks' => [
				[
					'type' => 'collection',
					'label' => 'For each work process, how do you rate yourself?',
					'collection' => 'studentWorkProcesses',
					'recordField' => 'bpvPlacementId',
				],
			],
		];
	}//end page()
}//end class
