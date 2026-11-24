<?php

/**
 * Learniq parent leave requests
 *
 * The guardian's side of a leave request (leave-requests-decided-by-the-school):
 * the `requestLeave` action on one of her own children, the collection
 * `parentLeaveRequests` of her requests with the state in words and the
 * decision note, the page that holds both, and the overview's line about a
 * request the school has not decided yet.
 *
 * The action is scoped like the absence report: `scopeField` is
 * `requestedByRef`, so portaliq stamps the guardian's own uuid from her
 * session and never from the form, and `learnerRef` is a cross-reference
 * that must resolve to one of her children before portaliq writes.
 * LeaveRequestPortalStamp checks the child again on the server.
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
 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-guardian-asks-leave-for-her-own-child-and-sees-the-decision
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * Builds the guardian's leave action, collection, page and overview block.
 *
 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-guardian-asks-leave-for-her-own-child-and-sees-the-decision
 */
class ParentLeaveRequests {

	/**
	 * The register every leave request lives in.
	 */
	private const REGISTER = 'learniq';

	/**
	 * The schema slug.
	 */
	private const SCHEMA = 'leave-request';

	/**
	 * The action id.
	 */
	public const ACTION = 'requestLeave';

	/**
	 * The guardian's own requests.
	 */
	public const COLLECTION = 'parentLeaveRequests';

	/**
	 * The guardian's requests the school has not decided yet.
	 */
	public const OPEN_COLLECTION = 'parentOpenLeaveRequests';

	/**
	 * The page id.
	 */
	public const PAGE = 'parentLeave';

	/**
	 * What the guardian fills in. `requestedByRef` is never a form field.
	 *
	 * @var array<int, string>
	 */
	private const ACTION_FIELDS = [
		'learnerRef',
		'dateFrom',
		'dateTo',
		'kind',
		'reason',
		'attachmentRef',
	];

	/**
	 * What the guardian reads of her requests. Who decided is a member of
	 * staff's user id, so it stays out; the decision note says it in words.
	 *
	 * @var array<int, string>
	 */
	private const READ_FIELDS = [
		'learnerRef',
		'dateFrom',
		'dateTo',
		'kind',
		'reason',
		'schoolDays',
		'decideBy',
		'lifecycle',
		'decidedAt',
		'decisionNote',
	];

	/**
	 * The columns of her list.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const COLUMNS = [
		['field' => 'dateFrom', 'label' => 'From'],
		['field' => 'dateTo', 'label' => 'Until'],
		['field' => 'kind', 'label' => 'Kind of leave', 'valueLabels' => PortalValueLabels::LEAVE_KIND],
		['field' => 'lifecycle', 'label' => 'Status', 'valueLabels' => PortalValueLabels::LEAVE_STATUS],
		['field' => 'decideBy', 'label' => 'The school answers by'],
		['field' => 'decisionNote', 'label' => "The school's explanation"],
	];

	/**
	 * How each state reads as a tone on the rows.
	 *
	 * @var array<string, string>
	 */
	private const STATUS_TONES = [
		'submitted' => 'neutral',
		'allowed' => 'success',
		'refused' => 'error',
		'forwarded' => 'warning',
		'withdrawn' => 'neutral',
	];

	/**
	 * The guardian asks leave for one of her own children.
	 *
	 * @param array<string, mixed> $childJoin The shared reverse `via` join to her children.
	 *
	 * @return array<string, mixed> The create action.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-guardian-asks-leave-for-her-own-child-and-sees-the-decision
	 */
	public function action(array $childJoin): array {
		$children = new ParentPortalCollections();

		return [
			'id' => self::ACTION,
			'type' => 'create',
			'label' => 'Ask for leave',
			'register' => self::REGISTER,
			'schema' => self::SCHEMA,
			// Stamped from her session: who asked is never a form value.
			'scopeField' => 'requestedByRef',
			'scopeClaim' => 'guardianRef',
			'via' => $childJoin,
			'minTrust' => 'substantial',
			'fields' => self::ACTION_FIELDS,
			// The child must be one of hers before portaliq writes;
			// LeaveRequestPortalStamp checks it again.
			'crossRefs' => ['learnerRef' => $children->childCrossRef()],
			'optionsProviders' => ['learnerRef' => $children->childOptions()],
			'fieldConfigs' => [
				'learnerRef' => ['label' => 'Child', 'required' => true, 'widget' => 'choices'],
				'dateFrom' => ['label' => 'First day of leave', 'required' => true],
				'dateTo' => ['label' => 'Last day of leave', 'required' => true, 'requiredMessage' => 'Choose the last day of the leave.'],
				'kind' => [
					'label' => 'What is the leave for?',
					'required' => true,
					'valueLabels' => PortalValueLabels::LEAVE_KIND,
					'widget' => 'choices',
				],
				'reason' => ['label' => 'Explain your request', 'required' => true],
				'attachmentRef' => ['label' => 'Attachment (for example an invitation)', 'required' => false],
			],
			'submitLabel' => 'Send the request',
			'successMessage' => 'The school has your request. You read the decision in the list of leave requests.',
			'confirmation' => [
				'title' => 'Your request has been sent',
				'body' => 'The head of the school decides within five school days. Above ten school days the attendance officer decides.',
			],
		];
	}//end action()

	/**
	 * Her requests, the newest leave first, and those still waiting for the school.
	 *
	 * @param array<string, mixed> $childJoin The shared reverse `via` join to her children.
	 *
	 * @return array<int, array<string, mixed>> The two collections.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-guardian-asks-leave-for-her-own-child-and-sees-the-decision
	 */
	public function collections(array $childJoin): array {
		$own = [
			'register' => self::REGISTER,
			'schema' => self::SCHEMA,
			'scopeField' => 'learnerRef',
			'scopeClaim' => 'guardianRef',
			'via' => $childJoin,
			'groupByField' => 'learnerRef',
			'minTrust' => 'substantial',
			'fields' => self::READ_FIELDS,
			'columns' => self::COLUMNS,
		];

		return [
			array_merge(
				$own,
				[
					'id' => self::COLLECTION,
					'label' => 'Leave requests',
					'listable' => true,
					'defaultSort' => ['field' => 'dateFrom', 'direction' => 'desc'],
				]
			),
			// Read by the overview only; the server filter can only narrow her own rows.
			array_merge(
				$own,
				[
					'id' => self::OPEN_COLLECTION,
					'label' => 'Waiting for the school',
					'listable' => false,
					'filter' => ['lifecycle' => 'submitted'],
					'defaultSort' => ['field' => 'decideBy', 'direction' => 'asc'],
				]
			),
		];
	}//end collections()

	/**
	 * "Verlof": the form, then her requests as rows with the state and the school's explanation.
	 *
	 * @return array<string, mixed> The page.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-guardian-asks-leave-for-her-own-child-and-sees-the-decision
	 */
	public function page(): array {
		return [
			'id' => self::PAGE,
			'label' => 'Leave of absence',
			'icon' => 'CalendarQuestion',
			'blocks' => [
				['type' => 'action', 'action' => self::ACTION],
				[
					'type' => 'collection',
					'label' => 'Your leave requests',
					'collection' => self::COLLECTION,
					'recordField' => 'learnerRef',
					'display' => 'rows',
					'dateField' => 'dateFrom',
					'lookups' => [ParentSitePages::childNameLookup()],
					'titleFields' => ['childName', 'kind'],
					'quoteField' => 'reason',
					'statusField' => 'lifecycle',
					'statusTones' => self::STATUS_TONES,
					'statusNoteField' => 'decisionNote',
					'statusNoteAtField' => 'decidedAt',
					'sort' => ['field' => 'dateFrom', 'direction' => 'desc'],
				],
			],
		];
	}//end page()

	/**
	 * The overview's line about a request the school still decides, with the
	 * date it answers by. Nothing shows when every request is decided.
	 *
	 * @return array<string, mixed> The block.
	 *
	 * @spec openspec/changes/archive/2026-11-24-leave-requests-decided-by-the-school/tasks.md
	 */
	public function overviewBlock(): array {
		return [
			'type' => 'collection',
			'label' => 'Waiting for the school',
			'collection' => self::OPEN_COLLECTION,
			'display' => 'rows',
			'dateField' => 'decideBy',
			'lookups' => [ParentSitePages::childNameLookup()],
			'titleFields' => ['childName', 'kind'],
			'statusField' => 'lifecycle',
			'statusTones' => self::STATUS_TONES,
			'limit' => 3,
			'hideWhenEmpty' => true,
			'sort' => ['field' => 'decideBy', 'direction' => 'asc'],
		];
	}//end overviewBlock()
}//end class
