<?php

/**
 * Learniq parent portal inboxes
 *
 * The guardian's two inbox collections: a notice per published report card
 * and a notice per new grade, each read through the reverse join on her own
 * children and each hidden until its `visibleFrom` (portaliq #1198).
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
 * @spec openspec/changes/school-portals-use-the-new-blocks/specs/portal-contribution/spec.md#requirement-a-new-grade-reaches-the-guardians-inbox-when-it-becomes-visible
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * The guardian's inbox collections.
 *
 * @spec openspec/changes/school-portals-use-the-new-blocks/specs/portal-contribution/spec.md#requirement-a-new-grade-reaches-the-guardians-inbox-when-it-becomes-visible
 */
class ParentInboxCollections {

	private const REGISTER = 'learniq';

	/**
	 * Both inbox collections, report cards first.
	 *
	 * @param array<string, mixed> $childJoin The shared reverse `via` join descriptor.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/school-portals-use-the-new-blocks/specs/portal-contribution/spec.md#requirement-a-new-grade-reaches-the-guardians-inbox-when-it-becomes-visible
	 */
	public function collections(array $childJoin): array {
		return [$this->reportCardInbox(childJoin: $childJoin), $this->gradeInbox(childJoin: $childJoin)];
	}//end collections()

	/**
	 * The guardian's inbox: a notice for each published report card of her
	 * own children (site-guardian-portal-design T3).
	 *
	 * Rows are read through the same reverse join as every parent read, so a
	 * notice about another family's child never shows. The publish handler
	 * writes a notice the moment the card is published (`visibleFrom` is that
	 * moment), and only for a card in `published-to-parents`, so every notice
	 * is already visible. The line she reads is the stamped `subject`
	 * ("Het rapport van Vera staat klaar"); a grade is never part of it.
	 * Grade notices stay out: their `visibleFrom` may lie in the future and
	 * portaliq cannot yet hide a row until a date (requested from lane L2).
	 *
	 * @param array<string, mixed> $childJoin The shared reverse `via` join descriptor.
	 *
	 * @return array<string, mixed> The collection.
	 *
	 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-new-school-notices-about-a-child-reach-the-guardians-inbox
	 */
	private function reportCardInbox(array $childJoin): array {
		return [
			'id' => 'parentInbox',
			'kind' => 'inbox',
			'register' => self::REGISTER,
			'schema' => 'report-card-parent-notification',
			'scopeField' => 'learnerRef',
			'scopeClaim' => 'guardianRef',
			'via' => $childJoin,
			'groupByField' => 'learnerRef',
			'label' => 'Messages from school',
			'listable' => true,
			'minTrust' => 'substantial',
			'fields' => [
				'learnerRef',
				'event',
				'subject',
				'visibleFrom',
			],
			'messageFields' => [
				'subject' => 'subject',
				'receivedAt' => 'visibleFrom',
			],
			// A notice waits until its moment has come (lane L2, portaliq #1198).
			'visibleFromField' => 'visibleFrom',
		];

	}//end reportCardInbox()

	/**
	 * The guardian's inbox of new grades: a notice per published grade of her
	 * own children, shown from the grade's `visibleFrom` and never before
	 * (a teacher may hold a grade back). The line is the subject's name; the
	 * grade itself is never part of a notice.
	 *
	 * @param array<string, mixed> $childJoin The shared reverse `via` join descriptor.
	 *
	 * @return array<string, mixed> The collection.
	 *
	 * @spec openspec/changes/school-portals-use-the-new-blocks/specs/portal-contribution/spec.md#requirement-a-new-grade-reaches-the-guardians-inbox-when-it-becomes-visible
	 */
	private function gradeInbox(array $childJoin): array {
		return [
			'id' => 'parentGradeInbox',
			'kind' => 'inbox',
			'register' => self::REGISTER,
			'schema' => 'grade-notification',
			'scopeField' => 'learnerRef',
			'scopeClaim' => 'guardianRef',
			'via' => $childJoin,
			'groupByField' => 'learnerRef',
			'label' => 'New grades',
			'listable' => true,
			'minTrust' => 'substantial',
			'fields' => ['learnerRef', 'event', 'courseName', 'visibleFrom'],
			'messageFields' => [
				'subject' => 'courseName',
				'receivedAt' => 'visibleFrom',
			],
			'visibleFromField' => 'visibleFrom',
		];

	}//end gradeInbox()
}//end class
