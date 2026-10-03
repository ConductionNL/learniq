<?php

/**
 * A mapper that answers find($id) the way OpenRegister's RegisterMapper and
 * SchemaMapper do: with an entity whose getSlug() is the slug, or an exception
 * for an unknown id.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Support
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Support;

use RuntimeException;

/**
 * Id => slug lookups, with a count of how often each id was asked.
 */
final class SlugMapper {

	/**
	 * How often find() was called, per id.
	 *
	 * @var array<string, int>
	 */
	public array $calls = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $slugs Id => slug.
	 */
	public function __construct(
		private readonly array $slugs,
	) {
	}//end __construct()

	/**
	 * Find an entity by id.
	 *
	 * @param int|string $id The id.
	 *
	 * @return object An entity with getSlug().
	 */
	public function find(int|string $id): object {
		$key = (string)$id;
		$this->calls[$key] = (($this->calls[$key] ?? 0) + 1);
		if (isset($this->slugs[$key]) === false) {
			throw new RuntimeException('Did expect one result but found none');
		}

		$slug = $this->slugs[$key];

		return new class ($slug) {

			/**
			 * Constructor.
			 *
			 * @param string $slug The slug.
			 */
			public function __construct(
				private readonly string $slug,
			) {
			}//end __construct()

			/**
			 * The slug.
			 *
			 * @return string The slug.
			 */
			public function getSlug(): string {
				return $this->slug;
			}//end getSlug()
		};
	}//end find()
}//end class
