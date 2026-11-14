<?php

/**
 * Learniq platform properties: the app runs without any AI installed.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Platform
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/platform-properties/spec.md#REQ-PLAT-003
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Platform;

use OCA\Learniq\Controller\AiProcessingDisclosureController;
use OCA\Learniq\Lifecycle\AssessmentPublishGuard;
use OCA\Learniq\Service\AiLocalityClassifier;
use OCA\Learniq\Service\ItemPoolFilter;
use OCA\Learniq\Service\SovereigntyPolicyService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * With hermiq absent, the AI disclosure answers an empty list and a manually proctored assessment publishes.
 */
class RunsWithoutAiTest extends TestCase {

	/**
	 * An app manager on which hermiq is not installed.
	 *
	 * @return IAppManager
	 */
	private function withoutHermiq(): IAppManager {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $app): bool => $app !== 'hermiq');

		return $apps;
	}//end withoutHermiq()

	/**
	 * The AI disclosure answers 200 with no features when hermiq is not installed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/platform-properties/spec.md#REQ-PLAT-003
	 */
	public function testTheDisclosureAnswersAnEmptyListWithoutHermiq(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));

		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturn([]);

		$classifier = $this->createMock(AiLocalityClassifier::class);
		$classifier->method('classifyActiveProvider')->willReturn(['locality' => 'unknown', 'verified' => false, 'evidence' => '']);

		$controller = new AiProcessingDisclosureController(
			$this->createMock(IRequest::class),
			$session,
			$this->withoutHermiq(),
			$objects,
			$classifier,
			$this->createMock(SovereigntyPolicyService::class),
			$this->createMock(LoggerInterface::class)
		);

		$response = $controller->index();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertFalse($response->getData()['hermiqInstalled']);
		self::assertSame([], $response->getData()['features']);
	}//end testTheDisclosureAnswersAnEmptyListWithoutHermiq()

	/**
	 * A manually proctored assessment publishes without hermiq; an AI-assisted one does not.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/platform-properties/spec.md#REQ-PLAT-003
	 */
	public function testAManuallyProctoredAssessmentPublishesWithoutHermiq(): void {
		$guard = new AssessmentPublishGuard(
			$this->createMock(ObjectService::class),
			new ItemPoolFilter(),
			$this->withoutHermiq(),
			$this->createMock(AiLocalityClassifier::class),
			$this->createMock(SovereigntyPolicyService::class),
			$this->createMock(LoggerInterface::class)
		);

		$manual = ['itemRefs' => ['item-1'], 'proctoring' => ['flagReviewMode' => 'manual']];
		$assisted = ['itemRefs' => ['item-1'], 'proctoring' => ['flagReviewMode' => 'ai-assisted']];

		self::assertTrue($guard->check(object: $manual, action: 'publish', userId: 'teacher')->isAllowed());
		self::assertFalse($guard->check(object: $assisted, action: 'publish', userId: 'teacher')->isAllowed());
	}//end testAManuallyProctoredAssessmentPublishesWithoutHermiq()
}//end class
