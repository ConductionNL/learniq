<?php

/**
 * Learniq Common Cartridge Manifest Parser
 *
 * Walks an extracted IMS Common Cartridge 1.3 `imsmanifest.xml`: the
 * organization tree (folders + leaf items, order preserved) and the flat
 * resource list, classifying each `<resource type="...">` into the fidelity-
 * table categories `CoursePackageImportService` routes on. Does not create
 * any OpenRegister object itself — this is a pure parser, the orchestrator
 * owns object creation.
 *
 * Legitimate PHP per ADR-031 §"External-format import": parsing XML from an
 * external interchange format cannot be expressed declaratively. Same
 * exception category as `QtiImportService`, at course-package scope.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DOMDocument;
use DOMElement;
use DOMXPath;
use OCA\Learniq\Service\CoursePackage\CommonCartridgeItemTree;
use RuntimeException;

/**
 * Parses an extracted Common Cartridge 1.3 package's `imsmanifest.xml`.
 */
class CommonCartridgeParser {

	/**
	 * IMS Content Packaging namespace used by the manifest root/organizations/resources elements.
	 */
	private const IMSCP_NS = 'http://www.imsglobal.org/xsd/imscp_v1p1';

	/**
	 * Type marker to fidelity-table category, in the order they are tried.
	 */
	private const CLASSIFICATION_MARKERS = [
		'imsqti_test' => 'imsqti_test',
		'imsqti' => 'imsqti_item',
		'basiclti' => 'basiclti',
		'imswl' => 'weblink',
		'imsdt' => 'discussion',
		'webcontent' => 'webcontent',
		'associatedcontent' => 'webcontent',
	];

	/**
	 * Parse the manifest at `$dir/imsmanifest.xml`.
	 *
	 * @param string $dir Absolute path to the extracted CC package directory.
	 *
	 * `organizationNodes`: one row per organization folder/item, in manifest
	 * order, each `{identifier, title, order, parentIdentifier, isFolder,
	 * resourceIdentifier}`.
	 * `resources`: one row per `<resource>`, each
	 * `{identifier, type, classification, href, title}`.
	 *
	 * @return array{organizationNodes: array<int, array<string, mixed>>, resources: array<int, array<string, mixed>>}
	 *
	 * @throws \RuntimeException When `imsmanifest.xml` is missing or not parseable XML.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
	 */
	public function parseManifest(string $dir): array {
		$xpath = new DOMXPath($this->loadManifest(dir: $dir));
		$xpath->registerNamespace('imscp', self::IMSCP_NS);

		$resources = $this->parseResources(xpath: $xpath);

		return [
			'organizationNodes' => $this->parseOrganizations(xpath: $xpath),
			'resources' => $resources,
		];
	}//end parseManifest()

	/**
	 * Load `$dir/imsmanifest.xml` as a document.
	 *
	 * @param string $dir Absolute path to the extracted CC package directory.
	 *
	 * @return \DOMDocument
	 *
	 * @throws \RuntimeException When `imsmanifest.xml` is missing or not parseable XML.
	 */
	private function loadManifest(string $dir): DOMDocument {
		$manifestPath = $dir . '/imsmanifest.xml';
		if (file_exists($manifestPath) === false) {
			throw new RuntimeException("No imsmanifest.xml found in '{$dir}' — not a recognisable Common Cartridge package.");
		}

		// `loadXML(file_get_contents())`, NOT `load($path)`. Nextcloud's
		// OC::init() installs `libxml_set_external_entity_loader(fn() => null)`
		// (lib/base.php) to block XXE, and libxml routes the PRIMARY document
		// of `DOMDocument::load()` through that same loader — so inside a real
		// Nextcloud `load()` always returns false and Common Cartridge import
		// never worked. `loadXML()` takes a string and is unaffected, while
		// still resolving no external entities. Measured, not assumed.
		$xml = new DOMDocument();
		libxml_use_internal_errors(true);
		$loaded = $xml->loadXML((string)file_get_contents($manifestPath));
		libxml_clear_errors();
		if ($loaded === false) {
			throw new RuntimeException("Could not parse imsmanifest.xml in '{$dir}' as XML.");
		}

		return $xml;
	}//end loadManifest()

	/**
	 * Walk every organization's `<item>` tree, preserving manifest order and
	 * parent/child relationships, with one order counter across organizations.
	 *
	 * @param \DOMXPath $xpath Manifest XPath, with the `imscp` namespace registered.
	 *
	 * @return array<int, array<string, mixed>> One row per organization node.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
	 */
	private function parseOrganizations(DOMXPath $xpath): array {
		$orgNodes = $xpath->query('//imscp:organizations/imscp:organization');
		if ($orgNodes === false || $orgNodes->length === 0) {
			$orgNodes = $xpath->document->getElementsByTagName('organization');
		}

		$tree = new CommonCartridgeItemTree();
		foreach ($orgNodes as $orgNode) {
			if ($orgNode instanceof DOMElement) {
				$tree->walk(parent: $orgNode, parentIdentifier: null);
			}
		}

		return $tree->nodes();
	}//end parseOrganizations()

	/**
	 * Parse the manifest's `<resources><resource>` list.
	 *
	 * @param \DOMXPath $xpath Manifest XPath, with the `imscp` namespace registered.
	 *
	 * @return array<int, array<string, mixed>> One row per resource: `{identifier, type, classification, href, title}`.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
	 */
	private function parseResources(DOMXPath $xpath): array {
		$resources = [];
		foreach ($this->resourceElements(xpath: $xpath) as $node) {
			if ($node instanceof DOMElement) {
				$resources[] = $this->resourceRow(node: $node);
			}
		}

		return $resources;
	}//end parseResources()

	/**
	 * The `<resource>` elements: the namespaced ones, else any `<resource>`.
	 *
	 * @param \DOMXPath $xpath Manifest XPath, with the `imscp` namespace registered.
	 *
	 * @return iterable<\DOMNode>
	 */
	private function resourceElements(DOMXPath $xpath): iterable {
		$resourceNodes = $xpath->query('//imscp:resources/imscp:resource');
		if ($resourceNodes === false || $resourceNodes->length === 0) {
			$resourceNodes = $xpath->query('//resource');
		}

		if ($resourceNodes === false) {
			return [];
		}

		return $resourceNodes;
	}//end resourceElements()

	/**
	 * One `<resource>` as a row. The href falls back to the first `<file>`'s,
	 * and the title is the href's file name, or the identifier when there is no href.
	 *
	 * @param \DOMElement $node The `<resource>` element.
	 *
	 * @return array<string, mixed> `{identifier, type, classification, href, title}`.
	 */
	private function resourceRow(DOMElement $node): array {
		$identifier = $node->getAttribute('identifier');
		$type       = $node->getAttribute('type');
		$href       = $this->resourceHref(node: $node);

		return [
			'identifier' => $identifier,
			'type' => $type,
			'classification' => $this->classify(type: $type),
			'href' => $href,
			'title' => self::fileNameOr(href: $href, fallback: $identifier),
		];
	}//end resourceRow()

	/**
	 * The file name of the href, or the fallback when there is no href.
	 *
	 * @param string|null $href     The resource href.
	 * @param string      $fallback The title to use without an href.
	 *
	 * @return string
	 */
	private static function fileNameOr(?string $href, string $fallback): string {
		if ($href === null) {
			return $fallback;
		}

		return basename($href);
	}//end fileNameOr()

	/**
	 * The resource's `href`, else the first `<file>`'s, or null when both are empty.
	 *
	 * @param \DOMElement $node The `<resource>` element.
	 *
	 * @return string|null
	 */
	private function resourceHref(DOMElement $node): ?string {
		$href = $node->getAttribute('href');
		$firstFile = $node->getElementsByTagName('file')->item(0);
		if ($href === '' && $firstFile instanceof DOMElement) {
			$href = $firstFile->getAttribute('href');
		}

		if ($href === '') {
			return null;
		}

		return $href;
	}//end resourceHref()

	/**
	 * Classify a CC `<resource type="...">` string into a fidelity-table category:
	 * the category of the first marker the lower-cased type contains.
	 *
	 * @param string $type The raw `type` attribute value.
	 *
	 * @return string One of `imsqti_item`, `imsqti_test`, `basiclti`, `weblink`, `webcontent`, `discussion`, `other`.
	 *
	 * @spec openspec/changes/archive/2026-07-16-course-package-import-export/design.md#fidelity--loss-table
	 */
	private function classify(string $type): string {
		$lower = strtolower($type);
		foreach (self::CLASSIFICATION_MARKERS as $marker => $classification) {
			if (str_contains($lower, $marker) === true) {
				return $classification;
			}
		}

		return 'other';
	}//end classify()
}//end class
