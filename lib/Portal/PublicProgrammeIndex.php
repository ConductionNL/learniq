<?php

/**
 * Learniq PublicProgrammeIndex
 *
 * The programmes part of a portal's public index (portal-public-index): one
 * item per published programme, with its level and learning path read from
 * the opening of its description ("Niveau 4, beroepsopleidende leerweg
 * (bol)", the way a school writes it); a programme that does not say so
 * carries no such facet.
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

/**
 * Builds the programme items of the public index.
 *
 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-a-school-offers-its-portal-an-index-of-its-public-courses-programmes-and-school-days
 */
class PublicProgrammeIndex {

	/**
	 * The learning paths a description may name, as a visitor reads them.
	 */
	private const PATHS = ['bol' => 'BOL', 'bbl' => 'BBL'];

	/**
	 * Constructor.
	 *
	 * @param PublicIndexReads $reads The shared reads and words.
	 */
	public function __construct(
		private readonly PublicIndexReads $reads,
	) {
	}//end __construct()

	/**
	 * The programme items.
	 *
	 * @param string $namespace The uuid namespace, '' for all.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-a-school-offers-its-portal-an-index-of-its-public-courses-programmes-and-school-days
	 */
	public function items(string $namespace): array {
		$out = [];
		foreach ($this->reads->rows(schema: 'programme', namespace: $namespace) as $programme) {
			$name = trim((string)($programme['name'] ?? ''));
			if (($programme['lifecycle'] ?? null) === 'published' && $name !== '') {
				$out[] = $this->item(programme: $programme, name: $name);
			}
		}

		return $out;
	}//end items()

	/**
	 * One programme card.
	 *
	 * @param array<string, mixed> $programme The programme.
	 * @param string               $name      Its name.
	 *
	 * @return array<string, mixed>
	 */
	private function item(array $programme, string $name): array {
		$description = (string)($programme['description'] ?? '');
		$item        = [
			'id'      => 'programme:' . $this->reads->idOf(row: $programme),
			'type'    => 'programme',
			'kind'    => $this->reads->word(text: 'Programme'),
			'title'   => $name,
			'summary' => $description,
		];

		$facets = [];
		$meta   = [];
		if (preg_match('/\bniveau\s+([1-4])\b/i', $description, $level) === 1) {
			$word = $this->reads->word(text: 'Level %s', args: [$level[1]]);
			$facets[$this->reads->word(text: 'Level')] = [$word];
			$meta[] = $word;
		}

		$paths = $this->pathsOf(description: $description);
		if ($paths !== []) {
			$facets[$this->reads->word(text: 'Learning path')] = $paths;
			$meta[] = implode(' ' . $this->reads->word(text: 'or') . ' ', $paths);
		}

		if ($facets !== []) {
			$item['facets'] = $facets;
			$item['meta']   = $meta;
		}

		return $item;
	}//end item()

	/**
	 * The learning paths a description names: "(bol)", "(bbl)".
	 *
	 * @param string $description The description.
	 *
	 * @return array<int, string>
	 */
	private function pathsOf(string $description): array {
		$out = [];
		foreach (self::PATHS as $token => $label) {
			if (preg_match('/\(' . $token . '\)/i', $description) === 1) {
				$out[] = $label;
			}
		}

		return $out;
	}//end pathsOf()
}//end class
