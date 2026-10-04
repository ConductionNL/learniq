<?php

/**
 * Learniq portal value labels
 *
 * How a stored value reads on the parent portal. Portaliq shows a status
 * cell's raw value ("approved") and writes an enum select's options as
 * English words ("Illness") unless the app declares a `valueLabels` map on
 * the column or the field config (portaliq contribution-value-labels). These
 * maps hold the English source; PortalLabelTranslator puts every label in the
 * reader's language through learniq's catalogue, so a guardian on a Dutch
 * portal reads "Goedgekeurd" and "Ziekte".
 *
 * Each key is a value of the schema property it labels; the unit test checks
 * that against lib/Settings/learniq_register.json.
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
 * @spec openspec/changes/parent-portal-value-labels/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * The English labels of the enum values a guardian sees on the portal.
 *
 * @spec openspec/changes/parent-portal-value-labels/specs/portal-contribution/spec.md
 */
class PortalValueLabels {

	/**
	 * `excuse-request.lifecycle`: where an absence report stands.
	 *
	 * @var array<string, string>
	 */
	public const EXCUSE_STATUS = [
		'submitted' => 'Submitted',
		'approved'  => 'Approved',
		'rejected'  => 'Rejected',
	];

	/**
	 * `excuse-request.reasonKind`: the kind of absence a guardian reports.
	 *
	 * @var array<string, string>
	 */
	public const ABSENCE_KIND = [
		'illness'              => 'Illness',
		'medical-appointment'  => 'Medical appointment',
		'family-circumstance'  => 'Family circumstance',
		'religious-observance' => 'Religious observance',
		'bereavement'          => 'Bereavement',
		'other'                => 'Other',
	];

	/**
	 * `attendance-record.status`: whether the child was there.
	 *
	 * @var array<string, string>
	 */
	public const ATTENDANCE_STATUS = [
		'present'          => 'Present',
		'absent-unexcused' => 'Absent (unexcused)',
		'absent-excused'   => 'Absent (excused)',
		'late'             => 'Late',
		'left-early'       => 'Left early',
	];

	/**
	 * `conference-signup.lifecycle`: where a conference booking stands.
	 *
	 * @var array<string, string>
	 */
	public const SIGNUP_STATUS = [
		'draft'      => 'Draft',
		'submitted'  => 'Submitted',
		'scheduled'  => 'Scheduled',
		'waitlisted' => 'On the waiting list',
		'booked'       => 'Booked',
		'acknowledged' => 'Acknowledged by the teacher',
		'declined'     => 'Declined by the teacher',
		'cancelled'  => 'Booking cancelled',
	];

	/**
	 * `conference-slot.lifecycle`: where a conference time stands.
	 *
	 * @var array<string, string>
	 */
	public const SLOT_STATUS = [
		'proposed'  => 'Proposed time',
		'confirmed' => 'Confirmed',
		'free'         => 'Free',
		'booked'       => 'Booked',
		'acknowledged' => 'Acknowledged by the teacher',
		'declined'     => 'Declined by the teacher',
		'completed' => 'Completed',
		'no-show'   => 'Did not attend',
		'cancelled' => 'Conversation cancelled',
	];

	/**
	 * `werkproces-assessment.assessment`: how a trainer judged a work process.
	 *
	 * @var array<string, string>
	 */
	public const WERKPROCES_ASSESSMENT = [
		'nog-niet-competent' => 'Not yet competent',
		'competent'          => 'Competent',
	];
}//end class
