<?php

/**
 * Learniq PermissionAnswerStore
 *
 * The OpenRegister side of a guardian's answer to a permission request: the
 * request it answers, and the one PermissionResponse per child per request,
 * which a second answer replaces. A stored answer is then kept as a document
 * of the child (PermissionAnswerDocument).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-permission-slips
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-each-school-audience-offers-its-own-documents-grouped-per-record
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use OCA\Learniq\Support\SerialisedRow;
use OCA\Learniq\Support\TextValue;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Reads the request and stores the answer, as the system: the portal
 * receiver has no session.
 *
 * @spec openspec/changes/archive/2026-11-24-permission-slips
 */
class PermissionAnswerStore {

	private const REGISTER = 'learniq';

	private const REQUEST_SCHEMA = 'permission-request';

	private const RESPONSE_SCHEMA = 'permission-response';

	/**
	 * Constructor.
	 *
	 * @param ObjectService                 $objectService Reads the request and earlier answers; writes the answer.
	 * @param PermissionAnswerDocument|null $documents     Keeps the answer as a document of the child; none in a bare test.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ?PermissionAnswerDocument $documents=null,
	) {
	}//end __construct()

	/**
	 * The permission request, or null.
	 *
	 * @param string $id The request's uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/archive/2026-11-24-permission-slips
	 */
	public function request(string $id): ?array {
		return $this->row(schema: self::REQUEST_SCHEMA, id: $id);
	}//end request()

	/**
	 * Save the answer in the request's tenant, replacing the answer already
	 * given for this child, then keep it as a document of the child.
	 *
	 * @param array<string, string> $response The answer.
	 * @param array<string, mixed>  $request  The request answered.
	 *
	 * @return void
	 *
	 * @throws \Throwable When OpenRegister refuses the write.
	 *
	 * @spec openspec/changes/archive/2026-11-24-permission-slips
	 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-each-school-audience-offers-its-own-documents-grouped-per-record
	 */
	public function save(array $response, array $request): void {
		$response['tenant_id'] = (new TextValue())->trimmedOrEmpty(value: ($request['tenant_id'] ?? null));
		$earlier = $this->earlierResponseId(requestId: $response['permissionRequestId'], learnerRef: $response['learnerRef']);
		if ($earlier !== null) {
			$response['id'] = $earlier;
		}

		$saved = $this->objectService->saveObject(
			object: $response,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: self::REGISTER, schema: self::RESPONSE_SCHEMA)),
			_rbac: false,
			_multitenancy: false
		);

		// The answer is stored; its document is a copy for the child's
		// documents, so a render that fails does not undo the answer.
		$this->documents?->keep(responseId: self::savedId(saved: $saved, response: $response), response: $response, request: $request);
	}//end save()

	/**
	 * The uuid of the stored answer.
	 *
	 * @param mixed                 $saved    What OpenRegister returned.
	 * @param array<string, string> $response The answer, carrying the id when it replaced an earlier one.
	 *
	 * @return string '' when neither names it.
	 *
	 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-each-school-audience-offers-its-own-documents-grouped-per-record
	 */
	private static function savedId(mixed $saved, array $response): string {
		$row = [];
		if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
			$row = (array)$saved->jsonSerialize();
		} else if (is_array($saved) === true) {
			$row = $saved;
		}

		return (string)($row['id'] ?? ($row['uuid'] ?? ($response['id'] ?? '')));
	}//end savedId()

	/**
	 * The id of the answer already given for this child, so it is replaced.
	 *
	 * @param string $requestId  The request's uuid.
	 * @param string $learnerRef The child's uuid.
	 *
	 * @return string|null
	 */
	private function earlierResponseId(string $requestId, string $learnerRef): ?string {
		$earlier = $this->first(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::RESPONSE_SCHEMA,
					'permissionRequestId' => $requestId,
					'learnerRef' => $learnerRef,
				],
			],
			matches: static fn (array $row): bool => ($row['permissionRequestId'] ?? null) === $requestId && ($row['learnerRef'] ?? null) === $learnerRef
		);

		if ($earlier === null) {
			return null;
		}

		return (string)($earlier['id'] ?? ($earlier['uuid'] ?? ''));
	}//end earlierResponseId()

	/**
	 * One row by uuid, RBAC off (the receiver has no session), or null.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function row(string $schema, string $id): ?array {
		return $this->first(
			config: [
				'filters' => ['register' => self::REGISTER, 'schema' => $schema],
				'ids' => [$id],
			],
			matches: static fn (array $row): bool => ($row['id'] ?? ($row['uuid'] ?? null)) === $id
		);
	}//end row()

	/**
	 * The first row a search finds that matches, RBAC off (the receiver has no session), or null.
	 *
	 * @param array<string, mixed> $config  The search, without its limit.
	 * @param callable             $matches Whether a row is the one asked for.
	 *
	 * @return array<string, mixed>|null
	 */
	private function first(array $config, callable $matches): ?array {
		$objects = $this->objectService->findAll(config: $config + ['limit' => 1], _rbac: false, _multitenancy: false);
		foreach ($objects as $object) {
			$row = (new SerialisedRow())->asArray(object: $object);
			if ($matches($row) === true) {
				return $row;
			}
		}

		return null;
	}//end first()
}//end class
