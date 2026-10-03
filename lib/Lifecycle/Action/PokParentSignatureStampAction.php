<?php

/**
 * Learniq PokParentSignatureStampAction
 *
 * Transition action that writes `parentSignatureRequired` onto a
 * praktijkovereenkomst when signatures are requested and when it activates.
 * The flag tells the signing page to ask for a parent or guardian and feeds
 * the declarative `isFullySigned`. PokActivationGuard applies the same rule
 * itself and never reads the flag, so a hand-written value changes what the
 * page shows, not what activation accepts.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle\Action
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-every-required-signature
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle\Action;

use OCA\Learniq\Service\PokParentSignatureRule;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Stamps whether a parent or guardian signs a praktijkovereenkomst.
 *
 * Declared on Praktijkovereenkomst `requestSignatures` and `activate`.
 * OpenRegister's LifecycleActionListener merges the returned array into the
 * object it saves.
 *
 * @spec openspec/specs/bpv/spec.md#scenario-the-signing-flow-asks-for-the-parent
 */
class PokParentSignatureStampAction implements LifecycleActionInterface {

	private const LEARNIQ_REGISTER = 'learniq';
	private const POK_SIGNATURE_SCHEMA = 'pok-signature';

	/**
	 * Constructor.
	 *
	 * @param PokParentSignatureRule $parentRule Whether a parent signs.
	 * @param ObjectService $objectService Reads the version's signatures for the student's signing date.
	 */
	public function __construct(
		private readonly PokParentSignatureRule $parentRule,
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Stamp `parentSignatureRequired` from the rule.
	 *
	 * @param array<string,mixed> $objectData   The POK after the lifecycle field moved to its target.
	 * @param array<string,mixed> $previousData The POK before the transition (unused).
	 * @param array<string,mixed> $parameters   The declared `actionParameters` (unused).
	 * @param string              $actionName   The declared action name (unused).
	 *
	 * @return array<string,mixed> The POK with `parentSignatureRequired` set; unchanged when it has no id.
	 *
	 * @spec openspec/specs/bpv/spec.md#scenario-the-signing-flow-asks-for-the-parent
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$pokId = (string)($objectData['id'] ?? ($objectData['uuid'] ?? ''));
		if ($pokId === '') {
			return $objectData;
		}

		$signatures = $this->signatures(pokId: $pokId, version: (int)($objectData['version'] ?? 1), tenantId: (string)($objectData['tenant_id'] ?? ''));
		$verdict = $this->parentRule->evaluate(pok: $objectData, studentSignedAt: $this->parentRule->studentSignedAt(signatures: $signatures));

		$objectData['parentSignatureRequired'] = $verdict['required'];

		return $objectData;
	}//end execute()

	/**
	 * The PokSignature rows on this POK version.
	 *
	 * @param string $pokId Praktijkovereenkomst uuid.
	 * @param int $version Praktijkovereenkomst version.
	 * @param string $tenantId Tenant scope, or '' for none.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function signatures(string $pokId, int $version, string $tenantId): array {
		$filters = ['subjectId' => $pokId, 'subjectVersion' => $version];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$rows = [];
		$raw = $this->objectService->findAll(
			[
				'filters' => array_merge($filters, ['register' => self::LEARNIQ_REGISTER, 'schema' => self::POK_SIGNATURE_SCHEMA]),
				'limit' => 200,
			]
		);
		foreach ($raw as $item) {
			$row = $item;
			if (is_array($item) === false) {
				$row = $item->jsonSerialize();
			}

			if (is_array($row) === true) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end signatures()
}//end class
