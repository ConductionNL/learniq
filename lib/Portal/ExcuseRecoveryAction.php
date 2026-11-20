<?php

/**
 * Learniq portal action: report a pupil better again
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/absence-reports-seen-and-ended/tasks.md#T4
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * `reportRecovered`: the update action that ends an open illness by setting
 * its last day (absence-reports-seen-and-ended). The guardian gets it on a
 * report of her own child, a pupil who reports for herself on her own
 * report. `rowWhen` (portaliq update-row-action-condition) shows it on a
 * report that is submitted or approved; it only hides the button.
 * ExcuseRecoveryGuard refuses a portal recovery of a report that already
 * has a last day, and any last day before the first day.
 *
 * @spec openspec/changes/absence-reports-seen-and-ended/tasks.md#T4
 */
final class ExcuseRecoveryAction {

	/**
	 * The action id the collections name in `rowActions`.
	 *
	 * @var string
	 */
	public const ID = 'reportRecovered';

	private const REGISTER = 'learniq';

	private const SCHEMA = 'excuse-request';

	/**
	 * On a report still being decided or approved, the states in which the
	 * pupil can still be absent. A row condition names lifecycle states;
	 * that the report has no last day yet is checked by ExcuseRecoveryGuard,
	 * which refuses a portal recovery of a report that already ends.
	 *
	 * @var array<string, mixed>
	 */
	public const ROW_WHEN = [
		'field' => 'lifecycle',
		'in' => ['submitted', 'approved'],
	];

	/**
	 * The guardian ends her child's open illness, scoped through the same
	 * reverse `via` join her absence collection reads.
	 *
	 * @param array<string, mixed> $childJoin The guardian's children join.
	 *
	 * @return array<string, mixed> The update action.
	 *
	 * @spec openspec/changes/absence-reports-seen-and-ended/specs/portal-contribution/spec.md#requirement-the-guardian-reads-that-the-school-saw-her-report
	 */
	public function forGuardian(array $childJoin): array {
		return array_merge(
			$this->action(lastDayLabel: 'Last day your child was ill', success: 'Your child is reported better. The report ends on the last day you chose.'),
			[
				'scopeField' => 'learnerRef',
				'scopeClaim' => 'guardianRef',
				'via' => $childJoin,
				'minTrust' => 'substantial',
			]
		);
	}//end forGuardian()

	/**
	 * A pupil who reports for herself ends her own open illness.
	 *
	 * @return array<string, mixed> The update action.
	 *
	 * @spec openspec/changes/absence-reports-seen-and-ended/specs/portal-contribution/spec.md#requirement-a-pupil-of-eighteen-reports-for-herself
	 */
	public function forPupil(): array {
		return array_merge(
			$this->action(lastDayLabel: 'Last day you were ill', success: 'You are reported better. The report ends on the last day you chose.'),
			[
				'scopeField' => 'learnerRef',
				'scopeClaim' => 'learnerRef',
				'minTrust' => 'low',
			]
		);
	}//end forPupil()

	/**
	 * The parts both audiences share.
	 *
	 * @param string $lastDayLabel The label of the last-day field.
	 * @param string $success      What the portal says after the write.
	 *
	 * @return array<string, mixed>
	 */
	private function action(string $lastDayLabel, string $success): array {
		return [
			'id' => self::ID,
			'type' => 'update',
			'label' => 'Report better',
			'register' => self::REGISTER,
			'schema' => self::SCHEMA,
			'fields' => ['dateTo'],
			'requiredFields' => ['dateTo'],
			'fieldConfigs' => [
				'dateTo' => ['label' => $lastDayLabel, 'required' => true, 'widget' => 'dateChoices', 'dateChoices' => 2],
			],
			'rowWhen' => self::ROW_WHEN,
			'submitLabel' => 'Report better',
			'successMessage' => $success,
		];
	}//end action()
}//end class
