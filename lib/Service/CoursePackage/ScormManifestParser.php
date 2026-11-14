<?php

/**
 * Learniq SCORM Manifest Parser
 *
 * Reads a SCORM package's `imsmanifest.xml`: which SCORM version it is (1.2 or
 * 2004), the SCOs of its default organization in the order a learner meets
 * them, each with its title and launch href, and every file the package's
 * resources name. Pure: it reads the manifest text and touches no storage, so
 * the import can decide what to create before anything is unpacked.
 *
 * @category Service
 * @package  OCA\Learniq\Service\CoursePackage
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/content-scorm-package-upload/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CoursePackage;

use DOMDocument;
use DOMElement;
use DOMNode;
use InvalidArgumentException;

/**
 * Parses a SCORM 1.2 or 2004 manifest into its version, SCOs and files.
 *
 * @spec openspec/changes/content-scorm-package-upload/tasks.md
 */
class ScormManifestParser {

	/**
	 * The manifest's file name at the package root.
	 */
	public const MANIFEST = 'imsmanifest.xml';

	/**
	 * SCORM 1.2.
	 */
	public const VERSION_12 = '1.2';

	/**
	 * SCORM 2004, any edition.
	 */
	public const VERSION_2004 = '2004';

	/**
	 * Parse a manifest.
	 *
	 * @param string $xml The text of `imsmanifest.xml`.
	 *
	 * @return array{version: string, title: string, scos: list<array{identifier: string, title: string, href: string}>, files: list<string>}
	 *
	 * @throws InvalidArgumentException When the text is not a SCORM manifest, or names no SCO.
	 *
	 * @spec openspec/changes/content-scorm-package-upload/tasks.md
	 */
	public function parse(string $xml): array {
		$manifest = $this->manifestElement(xml: $xml);
		$resources = $this->resources(manifest: $manifest);
		$organization = $this->defaultOrganization(manifest: $manifest);

		$scos = [];
		if ($organization !== null) {
			foreach ($this->itemsInOrder(parent: $organization) as $item) {
				$sco = $this->scoOf(item: $item, resources: $resources);
				if ($sco !== null) {
					$scos[] = $sco;
				}
			}
		}

		if ($scos === []) {
			throw new InvalidArgumentException('The SCORM manifest names no SCO to launch.');
		}

		return [
			'version' => $this->version(manifest: $manifest),
			'title' => $this->childText(parent: $organization, name: 'title'),
			'scos' => $scos,
			'files' => $this->files(resources: $resources),
		];
	}//end parse()

	/**
	 * Whether a manifest text looks like a SCORM manifest: an IMS manifest whose
	 * resources carry a SCORM type. Used to route a package to this parser.
	 *
	 * @param string $xml The text of `imsmanifest.xml`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/content-scorm-package-upload/tasks.md
	 */
	public function isScorm(string $xml): bool {
		try {
			$manifest = $this->manifestElement(xml: $xml);
		} catch (InvalidArgumentException $e) {
			return false;
		}

		foreach ($this->elements(parent: $manifest, name: 'resource', deep: true) as $resource) {
			if ($this->scormType(resource: $resource) !== '') {
				return true;
			}
		}

		return false;
	}//end isScorm()

	/**
	 * The manifest root, refused when the text is not XML, carries a DOCTYPE or is not a `manifest`.
	 *
	 * @param string $xml The manifest text.
	 *
	 * @return DOMElement
	 *
	 * @throws InvalidArgumentException When it is not a manifest.
	 */
	private function manifestElement(string $xml): DOMElement {
		// A DOCTYPE is where entity tricks live; a SCORM manifest never needs one.
		if (stripos($xml, '<!DOCTYPE') !== false) {
			throw new InvalidArgumentException('The SCORM manifest may not carry a DOCTYPE.');
		}

		$document = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = $document->loadXML($xml, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		$root = $document->documentElement;
		if ($loaded === false || $root === null || $root->localName !== 'manifest') {
			throw new InvalidArgumentException('The package has no readable imsmanifest.xml.');
		}

		return $root;
	}//end manifestElement()

	/**
	 * The SCORM version: 2004 when the manifest says so in its schema version
	 * or uses the 2004 namespace, else 1.2.
	 *
	 * @param DOMElement $manifest The manifest root.
	 *
	 * @return string VERSION_12 or VERSION_2004.
	 */
	private function version(DOMElement $manifest): string {
		$metadata = $this->firstChild(parent: $manifest, name: 'metadata');
		$declared = strtolower($this->childText(parent: $metadata, name: 'schemaversion'));
		if (str_contains($declared, '2004') === true || str_contains($declared, 'cam 1.3') === true) {
			return self::VERSION_2004;
		}

		foreach ($this->elements(parent: $manifest, name: 'resource', deep: true) as $resource) {
			foreach ($resource->attributes as $attribute) {
				if (str_contains((string)$attribute->namespaceURI, 'adlcp_v1p3') === true) {
					return self::VERSION_2004;
				}
			}
		}

		return self::VERSION_12;
	}//end version()

	/**
	 * The organization the manifest names as default, else its first.
	 *
	 * @param DOMElement $manifest The manifest root.
	 *
	 * @return DOMElement|null
	 */
	private function defaultOrganization(DOMElement $manifest): ?DOMElement {
		$organizations = $this->firstChild(parent: $manifest, name: 'organizations');
		if ($organizations === null) {
			return null;
		}

		$all = $this->elements(parent: $organizations, name: 'organization', deep: false);
		$default = $organizations->getAttribute('default');
		foreach ($all as $organization) {
			if ($default !== '' && $organization->getAttribute('identifier') === $default) {
				return $organization;
			}
		}

		return ($all[0] ?? null);
	}//end defaultOrganization()

	/**
	 * Every item under a parent, depth first in document order: the order a learner meets them.
	 *
	 * @param DOMElement $parent An organization or item.
	 *
	 * @return list<DOMElement>
	 */
	private function itemsInOrder(DOMElement $parent): array {
		$items = [];
		foreach ($this->elements(parent: $parent, name: 'item', deep: false) as $item) {
			$items[] = $item;
			array_push($items, ...$this->itemsInOrder(parent: $item));
		}

		return $items;
	}//end itemsInOrder()

	/**
	 * The SCO an item launches, or null when it points at no SCO resource.
	 *
	 * @param DOMElement                $item      The item.
	 * @param array<string, DOMElement> $resources The resources by identifier.
	 *
	 * @return array{identifier: string, title: string, href: string}|null
	 */
	private function scoOf(DOMElement $item, array $resources): ?array {
		$resource = ($resources[$item->getAttribute('identifierref')] ?? null);
		if ($resource === null || strtolower($this->scormType(resource: $resource)) !== 'sco') {
			return null;
		}

		$href = $this->baseOf(node: $resource).$resource->getAttribute('href');
		if ($href === '') {
			return null;
		}

		return [
			'identifier' => $item->getAttribute('identifier'),
			'title' => $this->childText(parent: $item, name: 'title'),
			'href' => $href.$item->getAttribute('parameters'),
		];
	}//end scoOf()

	/**
	 * The resources by identifier.
	 *
	 * @param DOMElement $manifest The manifest root.
	 *
	 * @return array<string, DOMElement>
	 */
	private function resources(DOMElement $manifest): array {
		$resources = [];
		$container = $this->firstChild(parent: $manifest, name: 'resources');
		foreach ($this->elements(parent: $container, name: 'resource', deep: false) as $resource) {
			$resources[$resource->getAttribute('identifier')] = $resource;
		}

		return $resources;
	}//end resources()

	/**
	 * Every file the resources name, each once, in document order, with any `xml:base` applied.
	 *
	 * @param array<string, DOMElement> $resources The resources.
	 *
	 * @return list<string>
	 */
	private function files(array $resources): array {
		$files = [];
		foreach ($resources as $resource) {
			$base = $this->baseOf(node: $resource);
			foreach ($this->elements(parent: $resource, name: 'file', deep: false) as $file) {
				$href = $file->getAttribute('href');
				if ($href !== '') {
					$files[$base.$href] = true;
				}
			}
		}

		return array_keys($files);
	}//end files()

	/**
	 * The `xml:base` a resource inherits from `resources` and sets itself, joined.
	 *
	 * @param DOMElement $node A resource.
	 *
	 * @return string
	 */
	private function baseOf(DOMElement $node): string {
		$base = '';
		$parent = $node->parentNode;
		if ($parent instanceof DOMElement) {
			$base = $parent->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'base');
		}

		return $base.$node->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'base');
	}//end baseOf()

	/**
	 * The SCORM type of a resource: `adlcp:scormtype` (1.2) or `adlcp:scormType` (2004), or empty.
	 *
	 * @param DOMElement $resource The resource.
	 *
	 * @return string
	 */
	private function scormType(DOMElement $resource): string {
		foreach ($resource->attributes as $attribute) {
			if (strtolower((string)$attribute->localName) === 'scormtype') {
				return (string)$attribute->value;
			}
		}

		return '';
	}//end scormType()

	/**
	 * The child elements of a parent with this local name, namespace-blind.
	 *
	 * @param DOMNode|null $parent The parent.
	 * @param string       $name   The local name.
	 * @param bool         $deep   Whether to search all descendants.
	 *
	 * @return list<DOMElement>
	 */
	private function elements(?DOMNode $parent, string $name, bool $deep): array {
		if ($parent === null) {
			return [];
		}

		$found = [];
		$nodes = $parent->childNodes;
		if ($deep === true && $parent instanceof DOMElement) {
			$nodes = $parent->getElementsByTagNameNS('*', $name);
		}

		foreach ($nodes as $node) {
			if ($node instanceof DOMElement && $node->localName === $name) {
				$found[] = $node;
			}
		}

		return $found;
	}//end elements()

	/**
	 * The first child element with this local name.
	 *
	 * @param DOMNode|null $parent The parent.
	 * @param string       $name   The local name.
	 *
	 * @return DOMElement|null
	 */
	private function firstChild(?DOMNode $parent, string $name): ?DOMElement {
		return ($this->elements(parent: $parent, name: $name, deep: false)[0] ?? null);
	}//end firstChild()

	/**
	 * The trimmed text of the first child element with this name, or empty.
	 *
	 * @param DOMNode|null $parent The parent.
	 * @param string       $name   The local name.
	 *
	 * @return string
	 */
	private function childText(?DOMNode $parent, string $name): string {
		return trim((string)$this->firstChild(parent: $parent, name: $name)?->textContent);
	}//end childText()
}//end class
