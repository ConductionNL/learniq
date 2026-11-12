<?php

/**
 * Learniq AI Processing Disclosure Controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * One read-only endpoint (sovereign-ai-guarantee): composes Hermiq's
 * `agentaifeature` register (cross-app, when installed), Learniq's own
 * `AiFeature` AVG Art. 30 processing-activity carrier (`scholiq-ai-features`
 * — avg-verwerkingsregister's existing catalogue entry, read from
 * `learniq_register.json` the same way `SettingsService::loadConfiguration()`
 * already reads it, NOT duplicated as a second declaration), and the
 * {@see AiLocalityClassifier}/{@see SovereigntyPolicyService} verdict for the
 * currently active Hermiq chat provider into one DPO-facing disclosure
 * payload. No verdict in this payload is ever "compliant" unless
 * `verified: true` — an unverifiable claim shows as `unverified`, never green.
 *
 * Legitimate PHP per ADR-031: composes two cross-app registers plus derived
 * classification into one payload — not a single declarative OR query.
 * Identical justification {@see \OCA\Learniq\Lifecycle\AssessmentPublishGuard}'s
 * own docblock already gives for its Hermiq read: "Requires a cross-schema
 * query ... and conditional logic."
 *
 * No write path lives here: `SovereigntyPolicy` create/update goes through
 * OpenRegister's existing generic object-create/update endpoint directly from
 * `LearniqAiProcessingDisclosure.vue`, mirroring `CourseTemplate`'s
 * frontend-orchestration precedent (no bespoke write controller, per ADR-022).
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/ai-locality-guarantee/spec.md#requirement-the-system-shall-compose-an-ai-processing-disclosure-a-school-can-hand-to-its-dpo
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\AiLocalityClassifier;
use OCA\Learniq\Service\SovereigntyPolicyService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Composes the AI-processing disclosure a school hands to its DPO.
 *
 * @spec openspec/specs/ai-locality-guarantee/spec.md#requirement-the-system-shall-compose-an-ai-processing-disclosure-a-school-can-hand-to-its-dpo
 */
class AiProcessingDisclosureController extends Controller {

	/**
	 * App id of the central AI governance/inference app (Hermiq).
	 */
	private const HERMIQ_APP_ID = 'hermiq';

	/**
	 * OR register slug that holds Hermiq's high-risk AI-feature inventory.
	 */
	private const HERMIQ_REGISTER = 'hermiq';

	/**
	 * OR schema slug of Hermiq's AI-feature inventory entry.
	 */
	private const HERMIQ_AI_FEATURE_SCHEMA = 'agentaifeature';

	/**
	 * OR register slug for Learniq objects.
	 */
	private const LEARNIQ_REGISTER = 'learniq';

	/**
	 * OR schema slug for the SovereigntyPolicy singleton.
	 */
	private const SOVEREIGNTY_POLICY_SCHEMA = 'sovereignty-policy';

	/**
	 * Schema key (register JSON `components.schemas` key) carrying the
	 * `scholiq-ai-features` AVG Art. 30 processing-activity annotation.
	 */
	private const AI_FEATURE_SCHEMA_KEY = 'AiFeature';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request.
	 * @param IUserSession $userSession Current user session.
	 * @param IAppManager $appManager Tells "Hermiq absent" from "Hermiq installed".
	 * @param ObjectService $objectService OR object service for the Hermiq/SovereigntyPolicy reads.
	 * @param AiLocalityClassifier $localityClassifier Derives the active provider's locality verdict.
	 * @param SovereigntyPolicyService $policyService Evaluates a verdict against the school's policy.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IAppManager $appManager,
		private readonly ObjectService $objectService,
		private readonly AiLocalityClassifier $localityClassifier,
		private readonly SovereigntyPolicyService $policyService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Compose the disclosure payload.
	 *
	 * Defence-in-depth only (mirrors `avg-verwerkingsregister`'s documented
	 * posture): the real enforcement layer is OpenRegister's own RBAC on the
	 * `SovereigntyPolicy` write path and this app's `visibleIf` navigation
	 * gate; this endpoint itself only requires an authenticated session.
	 *
	 * @return JSONResponse `{sovereigntyPolicy, hermiqInstalled, features: [...]}`.
	 *
	 * @spec openspec/specs/ai-locality-guarantee/spec.md#scenario-the-disclosure-page-lists-every-hermiq-governed-feature-with-its-locality-verdict
	 * @spec openspec/specs/ai-locality-guarantee/spec.md#scenario-an-unverified-locality-never-renders-as-compliant
	 * @spec openspec/specs/ai-locality-guarantee/spec.md#scenario-hermiq-absent-degrades-gracefully
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$verdict = $this->localityClassifier->classifyActiveProvider();
		$verdict['policyCompliant'] = $this->policyService->isCompliant(locality: $verdict['locality'], verified: $verdict['verified']);
		$verdict['aiProcessingActivity'] = $this->loadAiProcessingActivity();
		$hermiqInstalled = $this->appManager->isInstalled(self::HERMIQ_APP_ID);

		$features = [];
		if ($hermiqInstalled === true) {
			$features = array_map(
				static fn (array $feature): array => self::disclosedFeature(feature: $feature, verdict: $verdict),
				$this->fetchHermiqFeatures()
			);
		}

		return new JSONResponse(
			data: ['sovereigntyPolicy' => $this->loadSovereigntyPolicy(), 'hermiqInstalled' => $hermiqInstalled, 'features' => $features]
		);
	}//end index()

	/**
	 * One AI feature as disclosed: its identity and lifecycle, plus the
	 * processing activity and the locality verdict every feature shares.
	 *
	 * @param array<string,mixed> $feature The Hermiq agentaifeature row.
	 * @param array<string,mixed> $verdict The locality verdict, policy compliance and processing activity.
	 *
	 * @return array<string,mixed>
	 */
	private static function disclosedFeature(array $feature, array $verdict): array {
		return [
			'slug' => $feature['slug'] ?? '',
			'name' => $feature['name'] ?? '',
			'riskCategory' => $feature['riskCategory'] ?? null,
			'lifecycle' => $feature['lifecycle'] ?? 'disabled',
			'aiProcessingActivity' => $verdict['aiProcessingActivity'],
			'locality' => $verdict['locality'],
			'verified' => $verdict['verified'],
			'evidence' => $verdict['evidence'],
			'policyCompliant' => $verdict['policyCompliant'],
		];
	}//end disclosedFeature()

	/**
	 * Cross-app read of Hermiq's `agentaifeature` register. Never throws —
	 * a read failure degrades to an empty feature list, same posture as
	 * `AssessmentPublishGuard`'s own fail-closed-but-never-fatal Hermiq reads.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function fetchHermiqFeatures(): array {
		$features = $this->readRows(
			config: ['filters' => ['register' => self::HERMIQ_REGISTER, 'schema' => self::HERMIQ_AI_FEATURE_SCHEMA]],
			failure: '[AiProcessingDisclosureController] Hermiq agentaifeature read failed ({message}); '
			. 'returning an empty feature list rather than erroring.'
		);

		$normalised = [];
		foreach ($features as $feature) {
			$row = self::asArray(row: $feature);
			if ($row !== null) {
				$normalised[] = $row;
			}
		}

		return $normalised;
	}//end fetchHermiqFeatures()

	/**
	 * Read the school's current `SovereigntyPolicy` singleton — the stored
	 * record when one exists, or the schema-documented default shape when
	 * none has ever been created.
	 *
	 * @return array<string,mixed> `{id, policy, rationale, setBy, setAt}`.
	 */
	private function loadSovereigntyPolicy(): array {
		$existing = $this->readRows(
			config: ['filters' => ['register' => self::LEARNIQ_REGISTER, 'schema' => self::SOVEREIGNTY_POLICY_SCHEMA], 'limit' => 1],
			failure: '[AiProcessingDisclosureController] SovereigntyPolicy read failed ({message}); '
			. 'returning the schema default.'
		);

		$row = [];
		if (empty($existing) === false) {
			$row = self::asArray(row: $existing[0]) ?? [];
		}

		return [
			'id' => $row['id'] ?? ($row['uuid'] ?? null),
			'policy' => $row['policy'] ?? $this->policyService->currentPolicy(),
			'rationale' => $row['rationale'] ?? null,
			'setBy' => $row['setBy'] ?? null,
			'setAt' => $row['setAt'] ?? null,
		];
	}//end loadSovereigntyPolicy()

	/**
	 * The rows a findAll returns, or none (logged) when the read fails.
	 *
	 * @param array<string,mixed> $config  The findAll config.
	 * @param string              $failure The log message for a failed read, with a {message} placeholder.
	 *
	 * @return array<int|string,mixed>
	 */
	private function readRows(array $config, string $failure): array {
		try {
			return $this->objectService->findAll($config);
		} catch (Throwable $e) {
			$this->logger->info($failure, ['message' => $e->getMessage()]);
			return [];
		}
	}//end readRows()

	/**
	 * A row as an array: an array as it is, an object through jsonSerialize(), anything else null.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function asArray(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$serialized = $row->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return null;
	}//end asArray()

	/**
	 * Read the `scholiq-ai-features` AVG Art. 30 catalogue annotation
	 * (`AiFeature.x-openregister-processing`) directly from
	 * `learniq_register.json` — the SAME file-read pattern
	 * `SettingsService::loadConfiguration()` already uses, so this disclosure
	 * can never drift from the register's own declaration (no second,
	 * hand-copied carrier).
	 *
	 * @return array<string,mixed> `{code, doelbinding, rechtsgrond, dataCategories}`, or an
	 *                             empty array if the annotation cannot be read.
	 */
	private function loadAiProcessingActivity(): array {
		try {
			$configPath = __DIR__ . '/../Settings/learniq_register.json';
			$raw = file_get_contents($configPath);
			if ($raw === false) {
				return [];
			}

			$decoded = json_decode($raw, true);
			if (is_array($decoded) === false) {
				return [];
			}

			$processing = $decoded['components']['schemas'][self::AI_FEATURE_SCHEMA_KEY]['x-openregister-processing'] ?? null;
			if (is_array($processing) === false) {
				return [];
			}

			return [
				'code' => $processing['code'] ?? null,
				'doelbinding' => $processing['doelbinding'] ?? null,
				'rechtsgrond' => $processing['rechtsgrond'] ?? null,
				'dataCategories' => $processing['dataCategories'] ?? [],
			];
		} catch (Throwable $e) {
			$this->logger->info(
				'[AiProcessingDisclosureController] Failed to read the scholiq-ai-features AVG '
				. 'annotation ({message}); returning an empty aiProcessingActivity.',
				['message' => $e->getMessage()]
			);
			return [];
		}//end try

	}//end loadAiProcessingActivity()
}//end class
