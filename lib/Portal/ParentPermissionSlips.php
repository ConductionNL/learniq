<?php

/**
 * Learniq ParentPermissionSlips
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The parent portal's permission slips: the school's open requests that
 * cover one of the guardian's children, and the action to answer one for
 * her own child. The request is read through the same reverse `via` join
 * every parent read uses, on the request's `learnerRefs`; the answer goes
 * through learniq's own receiver, which checks the child is hers, the
 * request is still open and her session meets the school's floor.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/permission-slips
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * The guardian's permission requests and the action that answers one.
 *
 * @spec openspec/changes/permission-slips
 */
class ParentPermissionSlips {

	private const REGISTER = 'learniq';

	/**
	 * The collection of the guardian's open permission requests.
	 */
	public const COLLECTION = 'parentPermissionRequests';

	/**
	 * The action that answers one.
	 */
	public const ACTION = 'answerPermissionRequest';

	private const FIELDS = ['title', 'explanation', 'happensOn', 'respondBy', 'treatSilenceAsRefusal', 'learnerRefs', 'lifecycle'];

	private const COLUMNS = [
		['field' => 'title', 'label' => 'What it is about'],
		['field' => 'happensOn', 'label' => 'Date'],
		['field' => 'respondBy', 'label' => 'Answer by'],
	];

	private const ANSWER_FIELDS = ['permissionRequestId', 'learnerRef', 'answer'];

	private const ANSWER_FIELD_CONFIGS = [
		'permissionRequestId' => ['label' => 'Request', 'required' => true],
		'learnerRef' => ['label' => 'Child', 'required' => true],
		'answer' => ['label' => 'Your choice', 'required' => true],
	];

	/**
	 * The open requests that name one of the guardian's children.
	 *
	 * @param array<string, mixed> $childJoin The shared reverse `via` join descriptor.
	 *
	 * @return array<int, array<string, mixed>> The collection.
	 *
	 * @spec openspec/changes/permission-slips
	 */
	public function collections(array $childJoin): array {
		return [
			[
				'id' => self::COLLECTION,
				'register' => self::REGISTER,
				'schema' => 'permission-request',
				'scopeField' => 'learnerRefs',
				'scopeClaim' => 'guardianRef',
				'via' => $childJoin,
				'filter' => ['lifecycle' => 'open'],
				'label' => 'Permission asked by the school',
				'listable' => true,
				'minTrust' => 'low',
				'fields' => self::FIELDS,
				'columns' => self::COLUMNS,
				'rowActions' => [self::ACTION],
			],
		];
	}//end collections()

	/**
	 * The guardian answers a request yes or no for one of her children.
	 *
	 * Through learniq's own receiver: a portal create carries no assertion,
	 * and the assertion is where the sign-in level is, which is recorded on
	 * the answer and held against the school's floor.
	 *
	 * @return array<int, array<string, mixed>> The action.
	 *
	 * @spec openspec/changes/permission-slips
	 */
	public function actions(): array {
		return [
			[
				'id' => self::ACTION,
				'type' => 'endpoint-forward',
				'label' => 'Give your answer',
				'endpoint' => '/apps/learniq/api/portal/permission-answers',
				'method' => 'POST',
				// A school may demand more through `permission_answer_min_assurance`.
				'minTrust' => 'low',
				'subjectField' => 'guardianRef',
				'scopeClaim' => 'guardianRef',
				'fields' => self::ANSWER_FIELDS,
				'requiredFields' => self::ANSWER_FIELDS,
				// A row action on the request: portaliq stamps its id (portaliq#805).
				'rowField' => 'permissionRequestId',
				// Only an open request takes an answer; the receiver refuses a closed one too.
				'rowWhen' => ['field' => 'lifecycle', 'in' => ['open']],
				'crossRefs' => ['learnerRef' => (new ParentPortalCollections())->childCrossRef()],
				'optionsProviders' => [
					'learnerRef' => (new ParentPortalCollections())->childOptions(),
					'answer' => [
						'type' => 'static',
						'options' => [
							['value' => 'yes', 'label' => 'Yes, I give permission'],
							['value' => 'no', 'label' => 'No, I do not give permission'],
						],
					],
				],
				'fieldConfigs' => self::ANSWER_FIELD_CONFIGS,
				'submitLabel' => 'Send my answer',
				'successMessage' => 'Your answer is sent to the school.',
			],
		];
	}//end actions()

	/**
	 * "Wat u nog moet doen": an open request, with its deadline.
	 *
	 * @return array<string, mixed> The tasks block.
	 *
	 * @spec openspec/changes/permission-slips
	 */
	public static function tasksBlock(): array {
		return [
			'type' => 'tasks',
			'label' => 'Still to do',
			'display' => 'highlight',
			'collection' => self::COLLECTION,
			'dueField' => 'respondBy',
			'titleFields' => ['title'],
			'titleTemplate' => 'Give permission: {title}',
			'buttonLabel' => 'Give your answer',
			'dueInLine' => true,
		];
	}//end tasksBlock()
}//end class
