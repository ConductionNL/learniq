<?php

/**
 * Learniq Audit Entry Attribution
 *
 * Decides which audit-trail entries belong in a tenant's audit pack.
 *
 * OpenRegister's audit trail has no tenant column, and
 * `AuditTrailMapper::findAll()` silently drops any filter outside its column
 * allowlist, so a `tenant_id` filter on the trail returns every tenant's
 * entries. The tenant lives on the object an entry is about, so this class
 * loads those objects and keeps an entry only when its object is a learniq
 * object in the caller's tenant.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/compliance-audit/spec.md#requirement-export-audit-ready-zip-per-regulation-and-date-range
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\AppInfo\Application;
use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * Keeps the audit entries whose object is in the caller's tenant.
 *
 * An entry that cannot be attributed (no object uuid, an object that no
 * longer exists, an object outside the learniq register, or one without a
 * tenant) is left out and counted: the pack's spec only says the pack holds
 * the caller's entries, and an entry nobody can place in a tenant cannot be
 * shown to be the caller's.
 *
 * @spec openspec/specs/compliance-audit/spec.md#requirement-export-audit-ready-zip-per-regulation-and-date-range
 */
class AuditEntryAttribution {

	/**
	 * Most object uuids loaded in one query.
	 *
	 * @var int
	 */
	private const CHUNK = 200;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access, read without RBAC.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The entries of the caller's tenant, optionally narrowed to one regulation.
	 *
	 * @param array<int, array<string, mixed>> $entries        Serialised audit entries, in trail order.
	 * @param string                           $tenantId       The caller's tenant.
	 * @param string                           $regulationSlug The regulation, or '' for every entry.
	 *
	 * @return array{events: array<int, array<string, mixed>>, unattributed: int} The kept entries, in order, and how many could not be attributed.
	 *
	 * @spec openspec/specs/compliance-audit/spec.md#requirement-export-audit-ready-zip-per-regulation-and-date-range
	 */
	public function ownEntries(array $entries, string $tenantId, string $regulationSlug): array {
		$objects      = $this->objectsFor(entries: $entries);
		$events       = [];
		$unattributed = 0;
		foreach ($entries as $entry) {
			$object = ($objects[(string)($entry['objectUuid'] ?? '')] ?? null);
			if ($object === null || (string)($object['tenant_id'] ?? '') === '') {
				$unattributed++;
				continue;
			}

			if ((string)$object['tenant_id'] !== $tenantId) {
				continue;
			}

			if ($regulationSlug !== '' && $this->regulationOf(entry: $entry, object: $object) !== $regulationSlug) {
				continue;
			}

			$events[] = $entry;
		}

		return ['events' => $events, 'unattributed' => $unattributed];
	}//end ownEntries()

	/**
	 * Load the learniq objects the entries point at, keyed by uuid.
	 *
	 * Grouped by schema and chunked, read with RBAC and multitenancy off: the
	 * caller's own rights decide nothing here, only the object's tenant does.
	 *
	 * @param array<int, array<string, mixed>> $entries Serialised audit entries.
	 *
	 * @return array<string, array<string, mixed>> Objects by uuid.
	 */
	private function objectsFor(array $entries): array {
		$bySchema = [];
		foreach ($entries as $entry) {
			$uuid   = (string)($entry['objectUuid'] ?? '');
			$schema = (string)($entry['schema'] ?? '');
			if ($uuid !== '' && $schema !== '') {
				$bySchema[$schema][$uuid] = true;
			}
		}

		$objects = [];
		foreach ($bySchema as $schema => $uuids) {
			foreach (array_chunk(array_keys($uuids), self::CHUNK) as $chunk) {
				foreach ($this->load(schema: (string)$schema, uuids: $chunk) as $row) {
					$uuid = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
					if ($uuid !== '') {
						$objects[$uuid] = $row;
					}
				}
			}
		}

		return $objects;
	}//end objectsFor()

	/**
	 * One chunk of objects of one schema in the learniq register.
	 *
	 * A schema that is not learniq's cannot be resolved inside the learniq
	 * register, and the failure answers no objects: those entries stay
	 * unattributed rather than reaching the pack.
	 *
	 * @param string             $schema The schema id from the audit entry.
	 * @param array<int, string> $uuids  The object uuids.
	 *
	 * @return array<int, array<string, mixed>> The objects as arrays.
	 */
	private function load(string $schema, array $uuids): array {
		try {
			$rows = $this->objectService->findAll(
				config: [
					'filters' => ['register' => Application::APP_ID, 'schema' => $schema],
					'ids'     => $uuids,
					'limit'   => count($uuids),
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			return [];
		}

		$objects = [];
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				$row = (array)$row->jsonSerialize();
			}

			$objects[] = $row;
		}

		return $objects;
	}//end load()

	/**
	 * The regulation an entry is about: the object's own `regulationSlug`,
	 * else the value the entry itself changed.
	 *
	 * OpenRegister records `changed` as `{field: {old, new}}`, so a changed
	 * regulation is read from its new value, then its old one.
	 *
	 * @param array<string, mixed> $entry  The audit entry.
	 * @param array<string, mixed> $object The object it is about.
	 *
	 * @return string The regulation slug, or ''.
	 */
	private function regulationOf(array $entry, array $object): string {
		if (is_string($object['regulationSlug'] ?? null) === true && $object['regulationSlug'] !== '') {
			return $object['regulationSlug'];
		}

		$changed = ($entry['changed'] ?? []);
		if (is_string($changed) === true) {
			$changed = (array)json_decode($changed, associative: true);
		}

		$value = null;
		if (is_array($changed) === true) {
			$value = ($changed['regulationSlug'] ?? null);
		}

		if (is_array($value) === true) {
			$value = ($value['new'] ?? ($value['old'] ?? null));
		}

		if (is_string($value) === false) {
			return '';
		}

		return $value;
	}//end regulationOf()
}//end class
