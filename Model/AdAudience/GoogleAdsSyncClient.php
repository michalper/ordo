<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\AdAudience;

use Ordo\Automation\Api\AdAudience\SyncClientInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Http\JsonApiClient;

/**
 * Syncs a hashed-email list to Google Ads as a Customer Match user list via the Data Manager
 * API's IngestionService.ingestAudienceMembers - the only way to do this for a new integration.
 * Google retired the Google Ads API's own OfflineUserDataJobService route for Customer Match on
 * new Google Cloud projects (requests fail with CUSTOMER_NOT_ALLOWLISTED_FOR_THIS_FEATURE unless
 * the project already had Customer Match traffic in a narrow 2025-2026 grandfathering window -
 * see ADR 0035 for the full finding). Real HTTP calls to the documented REST endpoint - not the
 * googleads/data-manager PHP client (currently alpha-quality per its own README, and pulls in
 * google/gax), same "plain HTTP over a new SDK" choice this class already made before (RssFetcher
 * precedent) and still the right one.
 *
 * Also a genuine simplification, not just a forced migration: unlike the old 3-step job
 * create/addOperations/run dance (the last step of which is a long-running operation this class
 * used to fire-and-forget without ever checking the real outcome - see ADR 0035), ingestion here
 * is one synchronous call. A 2xx response means the batch was actually accepted and processed,
 * not just queued.
 *
 * $externalAudienceId is the target audience list's numeric id (Data Manager API's own
 * Destination.productDestinationId wants the bare id, never a resource name) - creating that
 * list itself is still a one-time setup step done directly in the Google Ads UI/API, out of this
 * class's scope, same as before. Accepts the old customers/{id}/userLists/{id} resource-name
 * shape too (extracts the trailing numeric segment) so a value saved before this migration still
 * works without an admin having to re-enter it.
 *
 * @see https://developers.google.com/data-manager/api/devguides/audiences/google-ads/customer-match
 * @see https://developers.google.com/data-manager/api/reference/rest/v1/audienceMembers/ingest
 */
class GoogleAdsSyncClient implements SyncClientInterface
{
    private const string API_BASE_URL = 'https://datamanager.googleapis.com/v1';

    /**
     * Data Manager API's own documented cap on how many AudienceMember resources a single
     * ingest call may carry - same reasoning the old Google Ads API batching had: an over-limit
     * request is rejected outright, not truncated, so a segment larger than this must be split
     * into multiple requests.
     *
     * @see https://developers.google.com/data-manager/api/devguides/limits
     */
    private const int MAX_MEMBERS_PER_BATCH = 10000;

    public function __construct(
        private readonly JsonApiClient $jsonApiClient,
        private readonly Config $config,
        private readonly GoogleOAuthTokenProvider $tokenProvider
    ) {
    }

    public function sync(?string $externalAudienceId, array $hashedEmails): ?string
    {
        if ($externalAudienceId === null || $externalAudienceId === '') {
            throw new \RuntimeException(
                'GoogleAdsSyncClient requires an existing audience list id as external_audience_id — '
                . 'creating the list itself is a one-time setup step done directly in Google Ads.'
            );
        }

        $accessToken = $this->tokenProvider->getAccessToken();
        $destination = $this->buildDestination($externalAudienceId);

        foreach (array_chunk($hashedEmails, self::MAX_MEMBERS_PER_BATCH) as $batch) {
            $this->ingestBatch($destination, $batch, $accessToken);
        }

        return null;
    }

    /**
     * @return array{operatingAccount: array{accountType: string, accountId: string}, productDestinationId: string}
     */
    private function buildDestination(string $externalAudienceId): array
    {
        // Accepts both the bare numeric id Data Manager API itself wants and the old Google Ads
        // API customers/{id}/userLists/{id} resource name - a value saved before this migration
        // still works unchanged.
        $segments = explode('/', $externalAudienceId);
        $productDestinationId = end($segments);

        return [
            'operatingAccount' => [
                'accountType' => 'GOOGLE_ADS',
                'accountId' => $this->config->getGoogleAdsAccountId(),
            ],
            'productDestinationId' => $productDestinationId,
        ];
    }

    /**
     * @param array{
     *     operatingAccount: array{accountType: string, accountId: string},
     *     productDestinationId: string
     * } $destination
     * @param array<int, string> $hashedEmailBatch at most MAX_MEMBERS_PER_BATCH entries
     */
    private function ingestBatch(array $destination, array $hashedEmailBatch, string $accessToken): void
    {
        $audienceMembers = array_map(
            static fn (string $hashedEmail): array => [
                'userData' => [
                    'userIdentifiers' => [
                        ['emailAddress' => $hashedEmail],
                    ],
                ],
            ],
            $hashedEmailBatch
        );

        $this->jsonApiClient->postJson(
            self::API_BASE_URL . '/audienceMembers:ingest',
            [
                'destinations' => [$destination],
                'audienceMembers' => $audienceMembers,
                'encoding' => 'HEX', // Model\AdAudience\PiiHasher hashes to lowercase hex.
                'termsOfService' => ['customerMatchTermsOfServiceStatus' => 'ACCEPTED'],
            ],
            ['Authorization' => 'Bearer ' . $accessToken],
            'Data Manager API'
        );
    }
}
