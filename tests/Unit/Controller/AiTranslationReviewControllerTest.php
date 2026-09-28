<?php

/**
 * AiTranslationReviewController tests (ai-translated-catalogue-review).
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-translated-catalogue-review/specs/ai-translated-catalogue/spec.md#requirement-marking-a-key-reviewed-removes-it-from-the-sidecar
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\AiTranslationReviewController;
use OCA\Learniq\Service\AiTranslatedCatalogue;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Admin only; outcomes map to 200, 404 and 409.
 */
class AiTranslationReviewControllerTest extends TestCase {
	/**
	 * The controller over a catalogue double.
	 *
	 * @param AiTranslatedCatalogue $catalogue The double.
	 *
	 * @return AiTranslationReviewController
	 */
	private function controller(AiTranslatedCatalogue $catalogue): AiTranslationReviewController {
		return new AiTranslationReviewController($this->createMock(IRequest::class), $catalogue);
	}//end controller()

	public function testBothEndpointsAreAdminOnly(): void {
		foreach (['index', 'reviewed'] as $method) {
			$reflection = new ReflectionMethod(AiTranslationReviewController::class, $method);
			$this->assertCount(1, $reflection->getAttributes(AuthorizedAdminSetting::class), $method . ' requires the admin setting');
			$this->assertCount(0, $reflection->getAttributes(NoAdminRequired::class), $method . ' is not open to non-admins');
			$this->assertCount(0, $reflection->getAttributes(PublicPage::class), $method . ' is not public');
		}
	}

	public function testTheListingIsPassedThrough(): void {
		$listing   = ['language' => 'nl', 'total' => 1, 'items' => [['key' => 'Publish marks', 'source' => 'Publish marks', 'value' => 'Cijfers publiceren']]];
		$catalogue = $this->createMock(AiTranslatedCatalogue::class);
		$catalogue->method('listing')->willReturn($listing);

		$response = $this->controller($catalogue)->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($listing, $response->getData());
	}

	public function testOutcomesMapToStatuses(): void {
		$cases = [
			AiTranslatedCatalogue::REVIEWED   => Http::STATUS_OK,
			AiTranslatedCatalogue::NOT_LISTED => Http::STATUS_NOT_FOUND,
			AiTranslatedCatalogue::READ_ONLY  => Http::STATUS_CONFLICT,
		];
		foreach ($cases as $outcome => $status) {
			$catalogue = $this->createMock(AiTranslatedCatalogue::class);
			$catalogue->expects($this->once())->method('markReviewed')->with('Publish marks')->willReturn($outcome);

			$response = $this->controller($catalogue)->reviewed('Publish marks');

			$this->assertSame($status, $response->getStatus(), $outcome);
		}
	}

	public function testReadOnlyNamesTheReason(): void {
		$catalogue = $this->createMock(AiTranslatedCatalogue::class);
		$catalogue->method('markReviewed')->willReturn(AiTranslatedCatalogue::READ_ONLY);

		$data = $this->controller($catalogue)->reviewed('Publish marks')->getData();

		$this->assertSame('read-only', $data['error']);
		$this->assertStringContainsString('l10n/ai-translated.json', $data['reason']);
	}
}
