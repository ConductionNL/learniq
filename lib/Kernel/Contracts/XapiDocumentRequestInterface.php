<?php

/**
 * Contract of XapiDocumentRequest
 *
 * The public surface of XapiDocumentRequest that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Learniq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Kernel\Contracts;

use OCA\Learniq\Exception\XapiRequestException;
use OCP\IRequest;

/**
 * Contract of XapiDocumentRequest.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface XapiDocumentRequestInterface {

	/**
	 * The `agent` parameter, checked to be the authenticated learner.
	 *
	 * @param IRequest $request The request.
	 * @param string   $actorId The authenticated uid.
	 *
	 * @return array<string, mixed> The agent as sent.
	 *
	 * @throws XapiRequestException 400 when absent or not JSON, 403 when it names someone else.
	 *
	 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function agent(IRequest $request, string $actorId): array;

	/**
	 * The request body, refused when it is larger than a document may be.
	 *
	 * @return string The body.
	 *
	 * @throws XapiRequestException 413 when the body is too large.
	 *
	 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function body(): string;

	/**
	 * Enforce If-Match and If-None-Match.
	 *
	 * @param IRequest    $request   The request.
	 * @param string|null $current   The stored document's ETag, or null when there is none.
	 * @param bool        $strictPut Whether a write without either header must not overwrite (agent profile PUT).
	 *
	 * @return void
	 *
	 * @throws XapiRequestException 412 on a failed precondition, 409 on a blind overwrite.
	 *
	 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function checkPreconditions(IRequest $request, ?string $current, bool $strictPut): void;

	/**
	 * The body's content type, `application/octet-stream` when the caller sent none.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return string The content type.
	 *
	 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function contentType(IRequest $request): string;

	/**
	 * The document key the query string names.
	 *
	 * @param IRequest $request The request.
	 * @param string   $kind    The document kind.
	 * @param string   $actorId The authenticated uid.
	 * @param bool     $needsId Whether the verb needs a stateId or profileId.
	 *
	 * @return array<string, string> The key.
	 *
	 * @throws XapiRequestException 400 on a missing or malformed parameter.
	 *
	 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function key(IRequest $request, string $kind, string $actorId, bool $needsId): array;

	/**
	 * The `since` query parameter, validated.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return string The timestamp, or ''.
	 *
	 * @throws XapiRequestException 400 when it is not a timestamp.
	 *
	 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function since(IRequest $request): string;
}//end interface
