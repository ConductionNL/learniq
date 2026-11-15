<?php

/**
 * Contract of XapiDocumentStore
 *
 * The public surface of XapiDocumentStore that other components call, so they depend
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

/**
 * Contract of XapiDocumentStore.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface XapiDocumentStoreInterface {

	/**
	 * Document kind: an activity State document.
	 *
	 * @var string
	 */
	public const KIND_STATE = 'state';

	/**
	 * Document kind: an Agent Profile document.
	 *
	 * @var string
	 */
	public const KIND_AGENT_PROFILE = 'agent-profile';

	/**
	 * Delete one document. Deleting a document that does not exist is not an error.
	 *
	 * @param array<string, string> $key A key from key(), with a documentId.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function delete(array $key): void;

	/**
	 * Delete every document under a key without a documentId (xAPI DELETE of all states).
	 *
	 * @param array<string, string> $key A key from key(), documentId ''.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function deleteAll(array $key): void;

	/**
	 * Read one document.
	 *
	 * @param array<string, string> $key A key from key(), with a documentId.
	 *
	 * @return array{contents: string, contentType: string, etag: string, updated: string}|null The document, or null.
	 *
	 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function get(array $key): ?array;

	/**
	 * The documentIds under a key without a documentId, optionally only those updated after `since`.
	 *
	 * @param array<string, string> $key   A key from key(), documentId ''.
	 * @param string                $since An ISO 8601 timestamp, or ''.
	 *
	 * @return array<int, string> The stateIds or profileIds.
	 *
	 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function listIds(array $key, string $since): array;

	/**
	 * Merge a JSON object into a document (xAPI POST), creating it when absent.
	 *
	 * @param array<string, string> $key      A key from key(), with a documentId.
	 * @param string                $contents The posted JSON object.
	 * @param array<string, mixed>  $context  `agent` and `lessonId`, as for put().
	 *
	 * @return string The new ETag.
	 *
	 * @throws XapiRequestException 400 when the posted or the stored document is not a JSON object.
	 *
	 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function merge(array $key, string $contents, array $context): string;

	/**
	 * Replace (or create) one document.
	 *
	 * @param array<string, string> $key         A key from key(), with a documentId.
	 * @param string                $contents    The raw document body.
	 * @param string                $contentType The body's content type.
	 * @param array<string, mixed>  $context     `agent` (the xAPI agent as sent) and `lessonId`.
	 *
	 * @return string The new ETag.
	 *
	 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#8-xapi-state-and-agent-profile
	 */
	public function put(array $key, string $contents, string $contentType, array $context): string;
}//end interface
