<?php

/**
 * Learniq Credential Wallet Offer Controller
 *
 * The holder of a certificate puts it in their wallet ("Naar wallet",
 * "Nieuwe code maken" on the learner's certificate page). Learniq checks that
 * the signed-in user holds the certificate, then runs the Credential's
 * `offerToWallet` transition as learniq's service account, with OpenRegister's
 * checks on. The learner gets no right on the certificate itself.
 *
 * A plain Controller, not an OCSController, so a refusal reaches the caller
 * with its own status.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
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
 * @spec openspec/changes/credential-wallet-offer-for-the-learner/specs/certification/spec.md#requirement-the-holder-starts-the-wallet-offer
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Exception\ServiceAccountUnavailableException;
use OCA\Learniq\Service\CredentialLearner;
use OCA\Learniq\Service\ServiceAccount;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Security\RateLimiting\ILimiter;
use OCP\Security\RateLimiting\IRateLimitExceededException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Starts a wallet offer for the signed-in holder of a certificate.
 *
 * @spec openspec/changes/credential-wallet-offer-for-the-learner/specs/certification/spec.md#requirement-the-holder-starts-the-wallet-offer
 */
class CredentialWalletOfferController extends Controller {

	private const REGISTER = 'learniq';
	private const SCHEMA = 'credential';

	/**
	 * Offers one holder may start for one certificate per period.
	 *
	 * @var int
	 */
	public const OFFERS_PER_PERIOD = 5;

	/**
	 * The rate-limit period, in seconds.
	 *
	 * @var int
	 */
	public const PERIOD_SECONDS = 3600;

	/**
	 * Constructor.
	 *
	 * @param IRequest          $request        The request.
	 * @param IUserSession      $userSession    The signed-in user.
	 * @param ObjectService     $objects        OpenRegister object access, as the caller.
	 * @param CredentialLearner $learners       Whose certificate it is.
	 * @param ServiceAccount    $serviceAccount The account the offer runs as.
	 * @param TransitionEngine  $transitions    Runs `offerToWallet`.
	 * @param ILimiter          $limiter        Bounds new codes per certificate.
	 * @param IL10N             $l10n           Refusal messages.
	 * @param LoggerInterface   $logger         Logs a failed transition.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ObjectService $objects,
		private readonly CredentialLearner $learners,
		private readonly ServiceAccount $serviceAccount,
		private readonly TransitionEngine $transitions,
		private readonly ILimiter $limiter,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Start a wallet offer, or replace the current one with a new code.
	 *
	 * @param string $id The credential uuid.
	 *
	 * @return JSONResponse 200 with the offer fields, or 401 / 403 / 404 / 409 / 422 / 429 / 502 / 503.
	 *
	 * @spec openspec/changes/credential-wallet-offer-for-the-learner/specs/certification/spec.md#requirement-the-holder-starts-the-wallet-offer
	 */
	#[NoAdminRequired]
	public function create(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->refuse(error: 'unauthorized', status: Http::STATUS_UNAUTHORIZED, message: $this->l10n->t('Sign in first.'));
		}

		$credential = $this->read(id: $id);
		if ($credential === null) {
			return $this->refuse(error: 'not_found', status: Http::STATUS_NOT_FOUND, message: $this->l10n->t('This certificate was not found.'));
		}

		if ($this->learners->belongsTo(credential: $credential, userId: $user->getUID()) === false) {
			return $this->refuse(
				error: 'not_holder',
				status: Http::STATUS_FORBIDDEN,
				message: $this->l10n->t('Only the person this certificate belongs to can put it in a wallet.')
			);
		}

		if (($credential['lifecycle'] ?? '') !== 'issued') {
			return $this->refuse(
				error: 'not_issued',
				status: Http::STATUS_CONFLICT,
				message: $this->l10n->t('This certificate is no longer valid, so it cannot go to a wallet.')
			);
		}

		if ($this->serviceAccount->problem() !== null) {
			return $this->serviceAccountMissing();
		}

		try {
			$this->limiter->registerUserRequest('learniq-wallet-offer-' . $id, self::OFFERS_PER_PERIOD, self::PERIOD_SECONDS, $user);
		} catch (IRateLimitExceededException) {
			return $this->refuse(
				error: 'too_many_codes',
				status: Http::STATUS_TOO_MANY_REQUESTS,
				message: $this->l10n->t('You made several codes this hour. Try again in an hour.')
			);
		}

		return $this->offer(id: $id);
	}//end create()

	/**
	 * Run `offerToWallet` as the service account and answer with the outcome.
	 *
	 * @param string $id The credential uuid.
	 *
	 * @return JSONResponse
	 */
	private function offer(string $id): JSONResponse {
		try {
			$this->serviceAccount->runAs(
				fn () => $this->transitions->transition($id, 'offerToWallet')
			);
		} catch (ServiceAccountUnavailableException) {
			return $this->serviceAccountMissing();
		} catch (Throwable $e) {
			$this->logger->warning(
				'Learniq: the wallet offer for credential {id} was refused: {msg}',
				['app' => Application::APP_ID, 'id' => $id, 'msg' => $e->getMessage()]
			);
			return $this->refuse(
				error: 'refused',
				status: Http::STATUS_UNPROCESSABLE_ENTITY,
				message: $this->l10n->t('This certificate cannot go to a wallet.')
			);
		}

		$credential = ($this->read(id: $id) ?? []);
		$uri = ($credential['walletOfferUri'] ?? null);
		if (is_string($uri) === false || $uri === '') {
			return $this->refuse(
				error: 'offer_failed',
				status: Http::STATUS_BAD_GATEWAY,
				message: $this->l10n->t('The wallet code could not be made. Try again later, or ask your school.')
			);
		}

		return new JSONResponse(
			data: [
				'walletOfferStatus' => ($credential['walletOfferStatus'] ?? null),
				'walletOfferUri' => $uri,
				'walletOfferedAt' => ($credential['walletOfferedAt'] ?? null),
				'walletClaimedAt' => ($credential['walletClaimedAt'] ?? null),
			]
		);
	}//end offer()

	/**
	 * The refusal when no service account can run the offer.
	 *
	 * @return JSONResponse
	 */
	private function serviceAccountMissing(): JSONResponse {
		return $this->refuse(
			error: 'service_account_missing',
			status: Http::STATUS_SERVICE_UNAVAILABLE,
			message: $this->l10n->t('Your school has not set up wallet codes yet. Ask your school to set up the learniq service account.')
		);
	}//end serviceAccountMissing()

	/**
	 * A refusal with its code and a sentence for the person.
	 *
	 * @param string $error   The machine code.
	 * @param int    $status  The HTTP status.
	 * @param string $message The sentence.
	 *
	 * @return JSONResponse
	 */
	private function refuse(string $error, int $status, string $message): JSONResponse {
		return new JSONResponse(data: ['error' => $error, 'message' => $message], statusCode: $status);
	}//end refuse()

	/**
	 * The credential as the caller may read it, or null.
	 *
	 * @param string $id The uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function read(string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$row = $this->objects->find(id: $id, register: self::REGISTER, schema: self::SCHEMA, _render: false)?->jsonSerialize();
		} catch (Throwable) {
			return null;
		}

		if (is_array($row) === false) {
			return null;
		}

		return $row;
	}//end read()
}//end class
