<?php
/**
 * Learniq SetupController.
 *
 * The ADR-042 first-time setup contract:
 *
 *   GET  /api/setup/status            per-step state and the option lists
 *   POST /api/setup/config            store a choice step's answer
 *   POST /api/setup/action/{actionId} run a privileged server-side action
 *
 * The wizard asks two things the app acts on: which example set to load (one
 * per kind of organisation, next to the generated set) and what kind of
 * organisation this is (`LearniqSettings.segment`, which the app publishes to
 * the browser as `runtime.workspace.segment`). The contract is written down in
 * openspec/changes/archive/2026-09-28-segment-wizard-choice/contract.md.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\SeedProfileService;
use OCA\Learniq\Service\SegmentService;
use OCA\Learniq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * First-time setup wizard endpoints.
 *
 * @spec exclude First-time-setup action dispatch; ADR-042 contract, specified in
 *     openspec/changes/archive/2026-09-28-segment-wizard-choice/contract.md.
 */
class SetupController extends Controller {
	/**
	 * Setup contract version; matches manifest.setup.version.
	 *
	 * 2 since the wizard asks for the segment: the wizard's dismissal is kept
	 * per version, so an install that dismissed version 1 is offered the new
	 * question once.
	 *
	 * @var integer
	 */
	private const SETUP_VERSION = 2;

	/**
	 * App-config key recording that the example-data step was DEALT WITH.
	 *
	 * Not "objects exist": an operator who declines has finished the step, and
	 * re-offering the import on every visit would make "no thanks" impossible to
	 * express. An OUTSTANDING OPTIONAL step opens the wizard over every page
	 * (nextcloud-vue#806), so a step that can never be marked done is a dialog
	 * that never closes.
	 *
	 * @var string
	 */
	private const DEMO_DECIDED_KEY = 'demo_data_decided';

	/**
	 * App-config key holding the example set the operator picked.
	 *
	 * The `example-set` choice step writes it through `POST /api/setup/config`,
	 * and the `load-example-set` run-action step reads it back. Two steps
	 * because `CnSetupWizard::runAction()` posts with no body: an action cannot
	 * carry the answer.
	 *
	 * @var string
	 */
	private const PROFILE_KEY = 'example_profile';

	/**
	 * The key the single-dataset step used before the sets existed. Still
	 * accepted on write and read, so an older manifest or a script that posts
	 * it keeps working.
	 *
	 * @var string
	 */
	private const LEGACY_DATASET_KEY = 'demo_dataset';

	/**
	 * The segment step's config key; its value is written to LearniqSettings,
	 * not to app config.
	 *
	 * @var string
	 */
	private const SEGMENT_KEY = 'segment';

	/**
	 * The groups whose members may write the segment (`admin`,
	 * `administration-managers`, both declared in the register's
	 * `components.securitySchemes`). The LearniqSettings schema carries no
	 * authorization block of its own yet, so the controller is where this holds.
	 *
	 * The setup endpoints are admin settings, which Nextcloud lets an admin
	 * delegate to any group; every user's menu depends on this one record
	 * (company-segment-menu-gating), so the controller checks the groups itself
	 * before SegmentService writes as a system operation.
	 *
	 * @var string[]
	 */
	private const SEGMENT_GROUPS = ['admin', 'administration-managers'];

	/**
	 * What app config `demo_data_decided` holds after a removal: not empty, so
	 * the load step stays answered and the wizard does not reopen over every
	 * page (an outstanding optional step opens it).
	 *
	 * @var string
	 */
	private const REMOVED = 'removed';

	/**
	 * Constructor.
	 *
	 * @param IRequest           $request      The request.
	 * @param IAppConfig         $appConfig    Records the example-set answer.
	 * @param LoggerInterface    $logger       Records a failed import.
	 * @param SeedProfileService $seedProfiles Lists and imports the example sets.
	 * @param SegmentService     $segments     Lists the six kinds and stores the answer.
	 * @param IUserSession       $userSession  Names the admin who chose the segment.
	 * @param IGroupManager      $groups       Checks who may write the segment.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly SeedProfileService $seedProfiles,
		private readonly SegmentService $segments,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groups,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Report per-step setup status for the wizard.
	 *
	 * `completed` is deliberately TRUE: this app declares no REQUIRED step, so
	 * setup must never gate the app. Every optional step is reported so the
	 * wizard can stop asking once it has an answer.
	 *
	 * @return JSONResponse The status document.
	 *
	 * @spec exclude Setup status document; ADR-042 contract, specified in openspec/changes/archive/2026-09-28-segment-wizard-choice/contract.md.
	 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function status(): JSONResponse {
		$picked      = $this->pickedProfile();
		$demoDecided = $this->appConfig->getValueString(Application::APP_ID, self::DEMO_DECIDED_KEY, '') !== '';
		$profiles    = $this->seedProfiles->listChoices();

		return new JSONResponse(
			data: [
				'version'   => self::SETUP_VERSION,
				'completed' => true,
				// The choice steps read their options from here: they declare
				// `optionsSource` and no options of their own, so an entry
				// missing from these lists is one nobody can pick.
				'profiles'  => $profiles,
				'segments'  => $this->segments->listChoices(),
				// Every id of `manifest.setup.steps`, plus one removal step per
				// loaded set. The cards load themselves (`loadAction`,
				// wizard-dataset-card-load), so there is no load step.
				'steps'     => [
					'welcome'            => ['done' => true],
					'example-set'        => ['done' => ($demoDecided === true || $picked !== '')],
					'segment'            => ['done' => $this->segments->hasSegment()],
					// 🔴 ALWAYS DONE. CnSetupWizard starts an outstanding
					// run-action step the moment it becomes current, and
					// CnAppRoot opens the wizard while any optional step is
					// outstanding: an outstanding removal step would delete the
					// set as soon as someone paged onto it, or reopen the wizard
					// on every page. Done, it runs only when the admin clicks it.
					'remove-example-set' => ['done' => true],
					'done'               => ['done' => true],
				] + $this->seedProfiles->loadedSets()->removalSteps(choices: $profiles),
			]
		);
	}//end status()

	/**
	 * Persist the wizard's choice answers.
	 *
	 * @return JSONResponse `{ success, config }`.
	 *
	 * @spec exclude Setup config write; ADR-042 contract, specified in openspec/changes/archive/2026-09-28-segment-wizard-choice/contract.md.
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function saveConfig(): JSONResponse {
		// 🔴 NAMED KEYS, NEVER CALLER-SUPPLIED ONES. The body arrives from the
		// browser and this app's own settings share the appconfig namespace, so
		// looping over the posted keys would let this endpoint write any of
		// them. The keys are written in the source; only their values come from
		// the request.
		$config = [];

		$profile = $this->request->getParam(self::PROFILE_KEY);
		if ($profile === null) {
			$profile = $this->request->getParam(self::LEGACY_DATASET_KEY);
		}

		if ($profile !== null) {
			$profileId = $this->scalarAnswer(value: $profile);
			if ($this->isSelectableProfile(profileId: $profileId) === false) {
				return $this->badRequest(message: 'No example set is called "' . $profileId . '".');
			}

			$this->appConfig->setValueString(Application::APP_ID, self::PROFILE_KEY, $profileId);
			$config[self::PROFILE_KEY] = $profileId;
		}

		$segment = $this->request->getParam(self::SEGMENT_KEY);
		if ($segment !== null) {
			$code = $this->scalarAnswer(value: $segment);
			if (in_array($code, SegmentService::SEGMENTS, true) === false) {
				return $this->badRequest(message: 'No kind of organisation is called "' . $code . '".');
			}

			if ($this->maySetSegment() === false) {
				return new JSONResponse(
					data: ['success' => false, 'message' => 'Only an administrator or an administration manager can choose the kind of organisation.'],
					statusCode: Http::STATUS_FORBIDDEN,
				);
			}

			$this->segments->setSegment(segment: $code, actor: $this->userSession->getUser()?->getUID());
			$config[self::SEGMENT_KEY] = $code;
		}

		return new JSONResponse(data: ['success' => true, 'config' => $config]);
	}//end saveConfig()

	/**
	 * Run a privileged server-side setup action.
	 *
	 * @param string $actionId One of `load-example-set` | `skip-example-set` | `remove-example-set`, or a legacy alias.
	 *
	 * @return JSONResponse `{ success, message }`.
	 *
	 * @spec exclude Setup action dispatch; ADR-042 contract, specified in openspec/changes/archive/2026-09-28-segment-wizard-choice/contract.md.
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function runAction(string $actionId): JSONResponse {
		// `load-demo-data` and `install-demo-data` are the ids the step used
		// before it offered example sets; kept so an older manifest, a runbook
		// or a script that posts them keeps working.
		if (in_array($actionId, ['load-example-set', 'load-demo-data', 'install-demo-data'], true) === true) {
			return $this->loadExampleSet(actionId: $actionId);
		}

		// DECLINING IS AN ANSWER, and it answers BOTH example-set steps:
		// closing only the load step leaves the choice outstanding, and
		// CnAppRoot opens the wizard while ANY optional step is outstanding.
		if ($actionId === 'remove-example-set') {
			return $this->removeExampleSet();
		}

		// One removal step per loaded set (D34): `remove-example-set-<id>`.
		$setId = $this->seedProfiles->loadedSets()->setIdFromAction(actionId: $actionId);
		if ($setId !== null) {
			if ($setId !== SeedProfileService::GENERATED_PROFILE && $this->seedProfiles->isKnown(profileId: $setId) === false) {
				return $this->badRequest(message: 'No example set is called "' . $setId . '".');
			}

			return $this->removeExampleSet(profileId: $setId);
		}

		if ($actionId === 'skip-example-set' || $actionId === 'skip-demo-data') {
			$this->appConfig->setValueString(Application::APP_ID, self::PROFILE_KEY, SeedProfileService::NONE_PROFILE);
			$this->appConfig->setValueString(Application::APP_ID, self::DEMO_DECIDED_KEY, 'skipped');

			return new JSONResponse(data: ['success' => true, 'message' => 'No example data was loaded.']);
		}

		return new JSONResponse(
			data: ['success' => false, 'message' => 'Unknown setup action: ' . $actionId],
			statusCode: Http::STATUS_NOT_FOUND,
		);
	}//end runAction()

	/**
	 * Import the example set a card's Load button posted as `dataset`, or the
	 * stored pick when nothing is posted.
	 *
	 * Reports the FAILURE rather than a quiet success: an operator who asked for
	 * example data and got none must be told, which is why
	 * SeedProfileService::install() throws instead of returning an empty result.
	 *
	 * @param string $actionId The action that asked, which decides whether an
	 *                         unanswered choice is refused or means the generated set.
	 *
	 * @return JSONResponse `{ success, message, objects, skipped }`.
	 *
	 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
	 */
	private function loadExampleSet(string $actionId): JSONResponse {
		$picked = $this->pickedProfile();

		// The card's Load button names its set in the body. An older wizard
		// posts nothing and relies on the pick stored a step earlier. Nothing is
		// stored before the load succeeds: a failed load must leave the step
		// open for an operator who asked for data and got none.
		$posted = $this->request->getParam('dataset');
		if ($posted !== null) {
			$postedId = $this->scalarAnswer(value: $posted);
			if ($this->isSelectableProfile(profileId: $postedId) === false) {
				return $this->badRequest(message: 'No example set is called "' . $postedId . '".');
			}

			$picked = $postedId;
		}

		// The legacy id carries no answer, so it means the generated set. A
		// caller that posts it has said which one by posting it.
		if ($actionId === 'install-demo-data' && $picked === '') {
			$picked = SeedProfileService::GENERATED_PROFILE;
		}

		// 🔴 NO SILENT DEFAULT. Importing here because the operator clicked Run
		// one step early would plant example objects nobody asked for.
		if ($picked === '') {
			return $this->badRequest(message: 'Pick an example set first.');
		}

		if ($picked === SeedProfileService::NONE_PROFILE) {
			$this->appConfig->setValueString(Application::APP_ID, self::PROFILE_KEY, SeedProfileService::NONE_PROFILE);
			$this->appConfig->setValueString(Application::APP_ID, self::DEMO_DECIDED_KEY, 'skipped');

			return new JSONResponse(data: ['success' => true, 'message' => 'No example data was loaded.']);
		}

		try {
			$imported = $this->seedProfiles->install(profileId: $picked);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Setup load-example-set failed for "' . $picked . '": ' . $e->getMessage(),
				['app' => Application::APP_ID, 'exception' => $e]
			);

			return new JSONResponse(
				data: ['success' => false, 'message' => 'Could not import the example data: ' . $e->getMessage()],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}

		// Loading IS choosing the set, so the pick is recorded too. The removal
		// step reads it back.
		$this->appConfig->setValueString(Application::APP_ID, self::PROFILE_KEY, $picked);
		$this->appConfig->setValueString(Application::APP_ID, self::DEMO_DECIDED_KEY, 'installed');

		// 🔴 SAY WHAT DID NOT ARRIVE. OpenRegister skips an object it cannot
		// place and keeps going, so "Imported 490" was reported while 302 of
		// them never landed. The skips come from the importer's own reply.
		$skipped = (int)($imported['skipped'] ?? 0);
		$message = 'Imported ' . $imported['objects'] . ' example object(s).';
		if ($skipped > 0) {
			$message = 'Imported ' . max(0, ($imported['objects'] - $skipped)) . ' of ' . $imported['objects']
				. ' example object(s). ' . $skipped . ' could not be imported; the Nextcloud log names each one.';
		}

		return new JSONResponse(
			data: [
				'success' => true,
				'message' => $message,
				'objects' => $imported['objects'],
				'skipped' => $skipped,
			]
		);
	}//end loadExampleSet()

	/**
	 * Remove one example set through OpenRegister's recorded import jobs
	 * (openregister PR 4080): the one named by a per-set step, or else the one
	 * stored as the wizard's answer.
	 *
	 * Every outcome is an answer the wizard shows: nothing loaded, nothing
	 * recorded, an OpenRegister without the method (with the occ command that
	 * removes the set instead), errors (with the command that finishes the
	 * job), or the number of objects moved to the trash.
	 *
	 * @param string|null $profileId The set a per-set step names, or null for the wizard's answer.
	 *
	 * @return JSONResponse `{ success, message }`.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-wizard-removes-a-loaded-example-set-through-openregisters-import-jobs
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-administrator-removes-a-loaded-example-set-on-the-admin-page
	 */
	private function removeExampleSet(?string $profileId=null): JSONResponse {
		$picked = ($profileId ?? $this->pickedProfile());
		if ($picked === '' || $picked === SeedProfileService::NONE_PROFILE) {
			return new JSONResponse(data: ['success' => true, 'message' => 'No example data was loaded, so there is nothing to remove.']);
		}

		try {
			$removed = $this->seedProfiles->remove(profileId: $picked);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Setup remove-example-set failed for "' . $picked . '": ' . $e->getMessage(),
				['app' => Application::APP_ID, 'exception' => $e]
			);

			return new JSONResponse(
				data: ['success' => false, 'message' => 'Could not remove the example data: ' . $e->getMessage()],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}

		if ($removed['supported'] === false) {
			return new JSONResponse(data: ['success' => false, 'message' => $this->fallbackMessage(profileId: $picked)]);
		}

		if ($removed['errors'] > 0) {
			// `--import-job` takes one job per run, and purge destroys rows for
			// good, so the advice names one command per job and says so.
			$commands = array_map(
				static fn (string $job): string => 'php occ openregister:objects:purge --import-job ' . $job,
				$removed['failedJobs']
			);

			return new JSONResponse(
				data: [
					'success' => false,
					'message' => 'Moved ' . $removed['softDeleted'] . ' example object(s) to the trash; ' . $removed['errors']
						. ' could not be removed. Run this step again, or finish on the server with: ' . implode('; ', $commands)
						. ' (each shows what it would remove; add --apply to delete for good).',
				]
			);
		}

		if ($removed['jobs'] === []) {
			return new JSONResponse(
				data: [
					'success' => true,
					'message' => 'OpenRegister has no recorded import of this example set, so nothing was removed. '
						. $this->fallbackMessage(profileId: $picked),
				]
			);
		}

		$this->appConfig->setValueString(Application::APP_ID, self::DEMO_DECIDED_KEY, self::REMOVED);

		return new JSONResponse(
			data: ['success' => true, 'message' => 'Moved ' . $removed['softDeleted'] . ' example object(s) to the trash.']
		);
	}//end removeExampleSet()

	/**
	 * How to remove a set without OpenRegister's recorded import jobs.
	 *
	 * @param string $profileId The set.
	 *
	 * @return string The advice, naming the command where one exists.
	 */
	private function fallbackMessage(string $profileId): string {
		if ($profileId === SeedProfileService::GENERATED_PROFILE) {
			return 'This OpenRegister cannot remove the generated example data from here. Update OpenRegister and run this step again.';
		}

		return 'Remove it on the server with: php occ learniq:example-set:remove ' . $profileId . ' --apply';
	}//end fallbackMessage()

	/**
	 * Whether the current user may write the segment.
	 *
	 * @return bool True for a member of `admin` or `administration-managers`.
	 */
	private function maySetSegment(): bool {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return false;
		}

		foreach (self::SEGMENT_GROUPS as $group) {
			if ($this->groups->isInGroup($uid, $group) === true) {
				return true;
			}
		}

		return false;
	}//end maySetSegment()

	/**
	 * The example set the operator picked, or '' when none is stored.
	 *
	 * Falls back to the legacy key so an answer given before the sets existed
	 * still counts.
	 *
	 * @return string The picked id.
	 */
	private function pickedProfile(): string {
		$picked = $this->appConfig->getValueString(Application::APP_ID, self::PROFILE_KEY, '');
		if ($picked !== '') {
			return $picked;
		}

		return $this->appConfig->getValueString(Application::APP_ID, self::LEGACY_DATASET_KEY, '');
	}//end pickedProfile()

	/**
	 * One scalar answer from a posted value.
	 *
	 * The steps are single-select, but the wizard's contract allows a list, so
	 * both shapes are read rather than one of them reaching `(string)`.
	 *
	 * A value that is not a scalar reads as the empty answer, which names no
	 * set and no segment, so every caller refuses it with one check.
	 *
	 * @param mixed $value The posted value.
	 *
	 * @return string The answer, or '' when it is not a scalar.
	 */
	private function scalarAnswer(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value[0] ?? null);
		}

		if (is_scalar($value) === false) {
			return '';
		}

		return (string)$value;
	}//end scalarAnswer()

	/**
	 * Whether a value is one the example-set step may legitimately carry.
	 *
	 * @param string $profileId The submitted value.
	 *
	 * @return bool True when it names a set, or declines one.
	 */
	private function isSelectableProfile(string $profileId): bool {
		if ($profileId === SeedProfileService::NONE_PROFILE) {
			return true;
		}

		// The empty answer names no set, whatever the set lookup would say.
		if ($profileId === '') {
			return false;
		}

		return $this->seedProfiles->isKnown(profileId: $profileId);
	}//end isSelectableProfile()

	/**
	 * A 400 answer with a reason.
	 *
	 * @param string $message What was wrong.
	 *
	 * @return JSONResponse
	 */
	private function badRequest(string $message): JSONResponse {
		return new JSONResponse(
			data: ['success' => false, 'message' => $message],
			statusCode: Http::STATUS_BAD_REQUEST,
		);
	}//end badRequest()
}//end class
