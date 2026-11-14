<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\CoursePackage;

use DOMDocument;
use DOMElement;
use OCA\Learniq\Service\CoursePackage\CommonCartridgeItemTree;
use PHPUnit\Framework\TestCase;

/**
 * CommonCartridgeItemTree flattens manifest `<item>` elements into ordered rows.
 *
 * @covers \OCA\Learniq\Service\CoursePackage\CommonCartridgeItemTree
 */
class CommonCartridgeItemTreeTest extends TestCase {

	/**
	 * The `<organization>` elements of a namespaced manifest fragment.
	 *
	 * @param string $organizations The organizations' XML.
	 *
	 * @return array<int, DOMElement>
	 */
	private function organizations(string $organizations): array {
		$doc = new DOMDocument();
		$doc->loadXML(
			'<manifest xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1"><organizations>'
			. $organizations
			. '</organizations></manifest>'
		);

		$out = [];
		foreach ($doc->getElementsByTagName('organization') as $organization) {
			$out[] = $organization;
		}

		return $out;
	}//end organizations()

	/**
	 * A fresh tree has no rows.
	 *
	 * @return void
	 */
	public function testFreshTreeHasNoNodes(): void {
		$this->assertSame([], (new CommonCartridgeItemTree())->nodes());
	}//end testFreshTreeHasNoNodes()

	/**
	 * Nested items are walked depth first, folders recognised, resources referenced.
	 *
	 * @return void
	 */
	public function testNestedItemsAreFlattenedDepthFirst(): void {
		[$organization] = $this->organizations(
			'<organization identifier="ORG">'
			. '<title>Org title is not an item</title>'
			. '<item identifier="ROOT">'
			. '<item identifier="M1"><title> Module 1 </title>'
			. '<item identifier="L1" identifierref="RES1"><title>Lesson 1</title></item>'
			. '<item identifier="L2" identifierref="RES2"><title>Lesson 2</title></item>'
			. '</item>'
			. '<item identifier="M2"><title>Module 2</title>'
			. '<item identifier="L3" identifierref="RES3"><title>Lesson 3</title></item>'
			. '</item>'
			. '</item>'
			. '</organization>'
		);

		$tree = new CommonCartridgeItemTree();
		$tree->walk(parent: $organization, parentIdentifier: null);

		$this->assertSame(
			[
				['identifier' => 'ROOT', 'title' => 'ROOT', 'order' => 0, 'parentIdentifier' => null, 'isFolder' => true, 'resourceIdentifier' => null],
				['identifier' => 'M1', 'title' => 'Module 1', 'order' => 1, 'parentIdentifier' => 'ROOT', 'isFolder' => true, 'resourceIdentifier' => null],
				['identifier' => 'L1', 'title' => 'Lesson 1', 'order' => 2, 'parentIdentifier' => 'M1', 'isFolder' => false, 'resourceIdentifier' => 'RES1'],
				['identifier' => 'L2', 'title' => 'Lesson 2', 'order' => 3, 'parentIdentifier' => 'M1', 'isFolder' => false, 'resourceIdentifier' => 'RES2'],
				['identifier' => 'M2', 'title' => 'Module 2', 'order' => 4, 'parentIdentifier' => 'ROOT', 'isFolder' => true, 'resourceIdentifier' => null],
				['identifier' => 'L3', 'title' => 'Lesson 3', 'order' => 5, 'parentIdentifier' => 'M2', 'isFolder' => false, 'resourceIdentifier' => 'RES3'],
			],
			$tree->nodes()
		);
	}//end testNestedItemsAreFlattenedDepthFirst()

	/**
	 * A blank or missing title falls back to the identifier; an empty identifierref is null.
	 *
	 * @return void
	 */
	public function testTitleFallsBackToIdentifierAndEmptyRefIsNull(): void {
		[$organization] = $this->organizations(
			'<organization identifier="ORG">'
			. '<item identifier="A" identifierref=""><title>   </title></item>'
			. '<item identifier="B"/>'
			. '</organization>'
		);

		$tree = new CommonCartridgeItemTree();
		$tree->walk(parent: $organization, parentIdentifier: 'PARENT');

		$this->assertSame(
			[
				['identifier' => 'A', 'title' => 'A', 'order' => 0, 'parentIdentifier' => 'PARENT', 'isFolder' => false, 'resourceIdentifier' => null],
				['identifier' => 'B', 'title' => 'B', 'order' => 1, 'parentIdentifier' => 'PARENT', 'isFolder' => false, 'resourceIdentifier' => null],
			],
			$tree->nodes()
		);
	}//end testTitleFallsBackToIdentifierAndEmptyRefIsNull()

	/**
	 * The order counter runs on across every organization walked by one tree.
	 *
	 * @return void
	 */
	public function testOrderRunsAcrossOrganizations(): void {
		[$first, $second] = $this->organizations(
			'<organization identifier="O1"><item identifier="X" identifierref="R1"><title>X</title></item></organization>'
			. '<organization identifier="O2"><item identifier="Y" identifierref="R2"><title>Y</title></item></organization>'
		);

		$tree = new CommonCartridgeItemTree();
		$tree->walk(parent: $first, parentIdentifier: null);
		$tree->walk(parent: $second, parentIdentifier: null);

		$this->assertSame([0, 1], array_column($tree->nodes(), 'order'));
		$this->assertSame(['X', 'Y'], array_column($tree->nodes(), 'identifier'));
	}//end testOrderRunsAcrossOrganizations()

	/**
	 * Only direct `<item>` children are walked; other elements and nested non-items are ignored.
	 *
	 * @return void
	 */
	public function testNonItemChildrenAreIgnored(): void {
		[$organization] = $this->organizations(
			'<organization identifier="ORG">'
			. '<metadata><item identifier="HIDDEN"/></metadata>'
			. '<item identifier="ONLY" identifierref="R"><title>Only</title><metadata/></item>'
			. '</organization>'
		);

		$tree = new CommonCartridgeItemTree();
		$tree->walk(parent: $organization, parentIdentifier: null);

		$this->assertSame(['ONLY'], array_column($tree->nodes(), 'identifier'));
		$this->assertFalse($tree->nodes()[0]['isFolder']);
	}//end testNonItemChildrenAreIgnored()
}//end class
