<?php
/**
 * Learniq SharedCodeFilter.
 *
 * Keeps a second example set from creating a second row for a code another
 * set already created (VCA and NIS2 in the company and training sets).
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drops the rows of an example set whose own code already exists under
 * another uuid.
 *
 * Regulation carries its identity in its slug, the code (`^[A-Z0-9_-]+$`,
 * unique). Two sets that each ship a VCA row under their own uuid would make
 * the importer, which matches by uuid, create two rows with the code VCA.
 * The set loaded second therefore leaves out a row whose code is already
 * there under a different uuid; its courses and certificates point at the
 * regulation by code (`regulationSlug`), so they find the row the first set
 * made. A row that exists under its own uuid is kept, so loading the same set
 * again still updates it.
 *
 * 🔴 A READ FAILURE KEEPS EVERY ROW. Filtering is an improvement on a second
 * load, never a reason for a load to fail or lose rows.
 *
 * @spec openspec/changes/example-set-regulation-dedupe/specs/example-sets/spec.md#requirement-a-second-example-set-does-not-duplicate-a-regulation-code
 */
class SharedCodeFilter {
	/**
	 * The schemas whose rows carry their identity in their slug.
	 *
	 * @var array<int, string>
	 */
	public const OWN_CODE_SCHEMAS = ['regulation'];

	/**
	 * OpenRegister's object service, named by string so learniq loads without it.
	 *
	 * @var string
	 */
	private const OBJECT_SERVICE = 'OCA\OpenRegister\Service\ObjectService';

	/**
	 * The most rows read per schema; regulations number in the tens.
	 *
	 * @var integer
	 */
	private const MAX_ROWS = 1000;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's object service.
	 * @param LoggerInterface    $logger    Records a read that failed.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The descriptor without the own-code rows another uuid already holds.
	 *
	 * @param array<string, mixed> $data The decoded descriptor.
	 *
	 * @return array<string, mixed> The descriptor to import.
	 *
	 * @spec openspec/changes/example-set-regulation-dedupe/specs/example-sets/spec.md#requirement-a-second-example-set-does-not-duplicate-a-regulation-code
	 */
	public function withoutCodesHeldElsewhere(array $data): array {
		foreach (self::OWN_CODE_SCHEMAS as $schema) {
			$rows = ($data['x-openregister']['seedData']['objects'][$schema] ?? null);
			if (is_array($rows) === false || $rows === []) {
				continue;
			}

			$existing = $this->existingCodes(schema: $schema);
			$kept     = array_values(
				array_filter(
					$rows,
					static function (mixed $row) use ($existing): bool {
						if (is_array($row) === false || is_string($row['slug'] ?? null) === false) {
							return true;
						}

						$code = $row['slug'];
						return isset($existing[$code]) === false || $existing[$code] === ($row['uuid'] ?? null);
					}
				)
			);

			$data['x-openregister']['seedData']['objects'][$schema] = $kept;
		}

		return $data;
	}//end withoutCodesHeldElsewhere()

	/**
	 * Code => uuid for every row of a schema in the learniq register.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, string> The codes, empty when they cannot be read.
	 */
	private function existingCodes(string $schema): array {
		try {
			$rows = $this->container->get(self::OBJECT_SERVICE)->findAll(
				['filters' => ['register' => 'learniq', 'schema' => $schema], 'limit' => self::MAX_ROWS],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->info('[SharedCodeFilter] could not read existing ' . $schema . ' rows: ' . $e->getMessage());
			return [];
		}

		$codes = [];
		foreach ((array)$rows as $row) {
			$fields = $this->toArray(row: $row);
			$code   = ($fields['slug'] ?? null);
			$uuid   = ($fields['@self']['id'] ?? ($fields['id'] ?? ($fields['uuid'] ?? null)));
			if (is_string($code) === true && is_string($uuid) === true) {
				$codes[$code] = $uuid;
			}
		}

		return $codes;
	}//end existingCodes()

	/**
	 * A findAll() entry as an array.
	 *
	 * @param mixed $row An ObjectEntity-like object or an array.
	 *
	 * @return array<string, mixed> The fields, empty when unreadable.
	 */
	private function toArray(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === false) {
			return [];
		}

		return $row;
	}//end toArray()
}//end class
