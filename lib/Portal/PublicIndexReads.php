<?php

/**
 * Learniq PublicIndexReads
 *
 * The reads and words the parts of a portal's public index share
 * (portal-public-index): the rows of one schema, in an example set's uuid
 * namespace when one is given; a row's id; a word in the portal's language;
 * a day as "22 okt" and a month as "Oktober 2026".
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-a-school-offers-its-portal-an-index-of-its-public-courses-programmes-and-school-days
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Shared reads and words of the public index.
 *
 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-a-school-offers-its-portal-an-index-of-its-public-courses-programmes-and-school-days
 */
class PublicIndexReads {

	private const REGISTER = 'learniq';

	/**
	 * The most rows of one schema read.
	 */
	private const LIMIT = 1000;

	/**
	 * Dutch month names, so a facet reads the same on every server.
	 */
	private const MONTHS = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OpenRegister reads (system context: the index holds public things only).
	 * @param LoggerInterface $logger        Logs a read that failed.
	 * @param IL10N|null      $l10n          The words in the portal's language.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
		private readonly ?IL10N $l10n=null,
	) {
	}//end __construct()

	/**
	 * The rows of a schema, as arrays, in the namespace when one is given.
	 *
	 * @param string               $schema    The schema slug.
	 * @param string               $namespace The uuid namespace, '' for all.
	 * @param array<string, mixed> $filters   Extra equality filters.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-a-school-offers-its-portal-an-index-of-its-public-courses-programmes-and-school-days
	 */
	public function rows(string $schema, string $namespace, array $filters=[]): array {
		try {
			$objects = $this->objectService->findAll(
				config: ['filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters), 'limit' => self::LIMIT],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Learniq: public index read failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return [];
		}

		$rows = [];
		foreach ((array)$objects as $object) {
			$row = $this->asRow(object: $object);
			if ($row !== null && ($namespace === '' || str_starts_with($this->idOf(row: $row), $namespace) === true)) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rows()

	/**
	 * A row's id: `id`, `uuid` or the `@self` envelope's.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-a-school-offers-its-portal-an-index-of-its-public-courses-programmes-and-school-days
	 */
	public function idOf(array $row): string {
		return (string)($row['id'] ?? ($row['uuid'] ?? ($row['@self']['id'] ?? '')));
	}//end idOf()

	/**
	 * A word in the portal's language.
	 *
	 * @param string            $text The English text.
	 * @param array<int, mixed> $args Its placeholders.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-a-school-offers-its-portal-an-index-of-its-public-courses-programmes-and-school-days
	 */
	public function word(string $text, array $args=[]): string {
		if ($this->l10n !== null) {
			return $this->l10n->t($text, $args);
		}

		return vsprintf($text, $args);
	}//end word()

	/**
	 * "22 okt".
	 *
	 * @param string $day A day, `YYYY-MM-DD`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-a-school-offers-its-portal-an-index-of-its-public-courses-programmes-and-school-days
	 */
	public function shortDay(string $day): string {
		$month = (self::MONTHS[((int)substr($day, 5, 2)) - 1] ?? '');

		return ((int)substr($day, 8, 2)) . ' ' . mb_substr($month, 0, 3);
	}//end shortDay()

	/**
	 * "Oktober 2026".
	 *
	 * @param string $day A day, `YYYY-MM-DD`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-a-school-offers-its-portal-an-index-of-its-public-courses-programmes-and-school-days
	 */
	public function month(string $day): string {
		$month = (self::MONTHS[((int)substr($day, 5, 2)) - 1] ?? '');

		return ucfirst($month) . ' ' . substr($day, 0, 4);
	}//end month()

	/**
	 * An object as an array, or null.
	 *
	 * @param mixed $object The object.
	 *
	 * @return array<string, mixed>|null
	 */
	private function asRow(mixed $object): ?array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			return (array)$object->jsonSerialize();
		}

		return null;
	}//end asRow()
}//end class
