<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Ordo\Automation\Model\Campaign\ConditionPool;
use Ordo\Automation\Model\CampaignActionFactory;
use Ordo\Automation\Model\CampaignCondition;
use Ordo\Automation\Model\CampaignConditionFactory;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\CampaignTriggerFactory;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition as CampaignConditionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger as CampaignTriggerResource;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt as SurveyPromptResource;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt\CollectionFactory as SurveyPromptCollectionFactory;
use Ordo\Automation\Model\SurveyPrompt;
use PHPUnit\Framework\TestCase;

/**
 * Real end-to-end coverage of the generic_survey action / survey_response_equals condition
 * added alongside the existing nps_survey/nps_score_at_least pair (see docs/adr 0059) — real DI,
 * real dev database, no mocks. Proves the whole claim/deliver/respond lifecycle for the new
 * text/choice question shape:
 *
 *  1. the real CampaignDispatcher/ActionPool wiring actually creates a real ordo_survey_prompt
 *     row with question_type/choices set (not just that the class's own unit test calls save()),
 *  2. that row can still be claimed exactly once through the same real conditional UPDATE
 *     Controller\Track\Poll uses (same proof CampaignVisitorPopupScenarioTest already runs for
 *     popups), now against the widened table shape,
 *  3. a real answer written to response_text is read back correctly by the real
 *     survey_response_equals condition (both isSatisfied() and the bulk resolveCustomerIds()
 *     path) through the real ConditionPool,
 *  4. and a downstream campaign gated on survey_response_equals only fires once that real answer
 *     exists — mirroring nps_survey/nps_score_at_least's own MFTF precedent
 *     (AdminCampaignNpsSurveyActionTest) at the Integration layer instead, since nothing here is
 *     newly admin/storefront-UI-visible beyond a generic select/text field the Flow canvas
 *     already renders generically for every other condition/action type.
 *
 * No transactional rollback (see magento-integration-test-lite) — every test cleans up what it
 * creates in tearDown().
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/michalper/ordo/Test/Integration/GenericSurveyScenarioTest.php
 */
class GenericSurveyScenarioTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    private CampaignDispatcher $dispatcher;
    private CacheInterface $cache;
    private CustomerTagManager $tagManager;

    /** @var int[] */
    private array $campaignIds = [];

    /** @var int[] */
    private array $customerIds = [];

    /** @var array{customerId: int, tag: string}[] */
    private array $tagsToClean = [];

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        self::$objectManager->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
        self::$objectManager->get(\Magento\Framework\Registry::class)->register('isSecureArea', true);
    }

    protected function setUp(): void
    {
        $this->dispatcher = self::$objectManager->get(CampaignDispatcher::class);
        $this->cache = self::$objectManager->get(CacheInterface::class);
        $this->tagManager = self::$objectManager->get(CustomerTagManager::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tagsToClean as $entry) {
            $this->tagManager->removeTag($entry['customerId'], $entry['tag']);
        }
        $this->tagsToClean = [];

        foreach ($this->campaignIds as $campaignId) {
            $this->deleteCampaign($campaignId);
        }
        $this->campaignIds = [];

        foreach ($this->customerIds as $customerId) {
            $this->deleteSurveyPromptsForCustomer($customerId);
            $this->deleteCustomer($customerId);
        }
        $this->customerIds = [];
    }

    // --- fixture builders ------------------------------------------------------------------

    private function createCampaign(string $triggerEvent): int
    {
        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);

        $campaign = $campaignFactory->create();
        $campaign->setName('Integration test generic survey campaign ' . uniqid('', true));
        $campaign->setEnabled(true);
        $campaignResource->save($campaign);
        $campaignId = (int) $campaign->getEntityId();
        $this->campaignIds[] = $campaignId;

        $triggerFactory = self::$objectManager->get(CampaignTriggerFactory::class);
        $triggerResource = self::$objectManager->get(CampaignTriggerResource::class);
        /** @var CampaignTrigger $trigger */
        $trigger = $triggerFactory->create();
        $trigger->setData(['campaign_id' => $campaignId, 'trigger_event' => $triggerEvent]);
        $triggerResource->save($trigger);

        return $campaignId;
    }

    private function addCondition(int $campaignId, string $type, array $params): void
    {
        $factory = self::$objectManager->get(CampaignConditionFactory::class);
        $resource = self::$objectManager->get(CampaignConditionResource::class);
        /** @var CampaignCondition $condition */
        $condition = $factory->create();
        $condition->setData([
            'campaign_id' => $campaignId,
            'type' => $type,
            'params' => json_encode($params),
            'sort_order' => 0,
        ]);
        $resource->save($condition);
    }

    private function addAction(int $campaignId, string $type, array $params): void
    {
        $factory = self::$objectManager->get(CampaignActionFactory::class);
        $resource = self::$objectManager->get(CampaignActionResource::class);
        $action = $factory->create();
        $action->setData([
            'campaign_id' => $campaignId,
            'type' => $type,
            'params' => json_encode($params),
            'sort_order' => 0,
            'delay_minutes' => 0,
        ]);
        $resource->save($action);
    }

    private function createCustomer(): int
    {
        $customerRepository = self::$objectManager->get(\Magento\Customer\Api\CustomerRepositoryInterface::class);
        $customerFactory = self::$objectManager->get(\Magento\Customer\Api\Data\CustomerInterfaceFactory::class);

        $email = 'ordo-automation-test-' . uniqid('', true) . '@example.test';
        $customer = $customerFactory->create();
        $customer->setEmail($email);
        $customer->setFirstname('Integration');
        $customer->setLastname('Test');
        $customer->setWebsiteId(
            (int) self::$objectManager->get(\Magento\Store\Model\StoreManagerInterface::class)->getWebsite()->getId()
        );

        $saved = $customerRepository->save($customer);
        $customerId = (int) $saved->getId();
        $this->customerIds[] = $customerId;

        return $customerId;
    }

    private function deleteCustomer(int $customerId): void
    {
        try {
            self::$objectManager->get(\Magento\Customer\Api\CustomerRepositoryInterface::class)->deleteById($customerId);
        } catch (\Throwable $e) {
            // Already gone or never fully committed — nothing left to clean up.
        }
    }

    private function deleteSurveyPromptsForCustomer(int $customerId): void
    {
        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $resourceConnection->getConnection()->delete(
            $resourceConnection->getTableName('ordo_survey_prompt'),
            ['customer_id = ?' => $customerId]
        );
    }

    private function deleteCampaign(int $campaignId): void
    {
        $campaignFactory = self::$objectManager->get(CampaignFactory::class);
        $campaignResource = self::$objectManager->get(CampaignResource::class);
        $campaign = $campaignFactory->create();
        $campaignResource->load($campaign, $campaignId);
        if ($campaign->getEntityId()) {
            $campaignResource->delete($campaign);
        }
    }

    private function latestSurveyPromptForCustomer(int $customerId): ?SurveyPrompt
    {
        $collection = self::$objectManager->get(SurveyPromptCollectionFactory::class)->create();
        $collection->addFieldToFilter('customer_id', $customerId);
        $collection->setOrder('entity_id', 'DESC');
        $collection->setPageSize(1);

        /** @var SurveyPrompt|null $prompt */
        $prompt = $collection->getFirstItem();

        return ($prompt !== null && $prompt->getId()) ? $prompt : null;
    }

    // --- tests -------------------------------------------------------------------------------

    public function testGenericSurveyActionWritesARealChoiceSurveyPromptThroughTheRealEngine(): void
    {
        $customerId = $this->createCustomer();
        $triggerEvent = 'test_generic_survey_' . uniqid('', true);
        $question = 'What is your favorite color?';

        $campaignId = $this->createCampaign($triggerEvent);
        $this->addAction($campaignId, 'generic_survey', [
            'question' => $question,
            'question_type' => 'choice',
            'choices' => 'Red,Blue,Green',
        ]);
        $this->cache->clean([CampaignDispatcher::CACHE_TAG]);

        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $customerId]);

        $prompt = $this->latestSurveyPromptForCustomer($customerId);
        self::assertNotNull($prompt, 'a real ordo_survey_prompt row must exist for this customer');
        self::assertSame($question, $prompt->getQuestion());
        self::assertSame('choice', $prompt->getQuestionType());
        self::assertSame('Red,Blue,Green', $prompt->getChoices());
        self::assertNull($prompt->getDeliveredAt());
        self::assertNull($prompt->getResponseText());
    }

    public function testGenericSurveyPromptCanBeClaimedExactlyOnceThroughARealConditionalUpdate(): void
    {
        $customerId = $this->createCustomer();
        $triggerEvent = 'test_generic_survey_claim_' . uniqid('', true);

        $campaignId = $this->createCampaign($triggerEvent);
        $this->addAction($campaignId, 'generic_survey', [
            'question' => 'How do you usually shop?',
            'question_type' => 'text',
        ]);
        $this->cache->clean([CampaignDispatcher::CACHE_TAG]);
        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $customerId]);

        $prompt = $this->latestSurveyPromptForCustomer($customerId);
        self::assertNotNull($prompt);

        $resourceConnection = self::$objectManager->get(ResourceConnection::class);
        $connection = $resourceConnection->getConnection();
        $table = $resourceConnection->getTableName('ordo_survey_prompt');

        // First claim succeeds against the real table...
        $firstClaim = $connection->update(
            $table,
            ['delivered_at' => date('Y-m-d H:i:s')],
            ['entity_id = ?' => (int) $prompt->getId(), 'delivered_at IS NULL']
        );
        self::assertSame(1, $firstClaim);

        // ...a second attempt at the same row must affect zero rows — the real conditional
        // UPDATE still prevents double delivery for this new question_type/choices row shape.
        $secondClaim = $connection->update(
            $table,
            ['delivered_at' => date('Y-m-d H:i:s')],
            ['entity_id = ?' => (int) $prompt->getId(), 'delivered_at IS NULL']
        );
        self::assertSame(0, $secondClaim);
    }

    public function testSurveyResponseEqualsConditionReadsARealAnsweredResponseText(): void
    {
        $customerId = $this->createCustomer();
        $unansweredCustomerId = $this->createCustomer();
        $triggerEvent = 'test_generic_survey_respond_' . uniqid('', true);
        $question = 'What brought you back today?';

        $campaignId = $this->createCampaign($triggerEvent);
        $this->addAction($campaignId, 'generic_survey', [
            'question' => $question,
            'question_type' => 'choice',
            'choices' => 'A sale,A friend,An ad',
        ]);
        $this->cache->clean([CampaignDispatcher::CACHE_TAG]);

        // Both customers get asked the same question...
        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $customerId]);
        $this->dispatcher->dispatch($triggerEvent, ['customer_id' => $unansweredCustomerId]);

        // ...only one of them actually answers it — simulating what a real
        // Controller\Track\SubmitSurveyResponse call validates and then does.
        $prompt = $this->latestSurveyPromptForCustomer($customerId);
        self::assertNotNull($prompt);
        $surveyPromptResource = self::$objectManager->get(SurveyPromptResource::class);
        $prompt->setResponseText('A sale');
        $prompt->setRespondedAt(date('Y-m-d H:i:s'));
        $surveyPromptResource->save($prompt);

        /** @var ConditionPool $conditionPool */
        $conditionPool = self::$objectManager->get(ConditionPool::class);
        $condition = $conditionPool->get('survey_response_equals');
        self::assertNotNull($condition, 'survey_response_equals must be registered in ConditionPool');

        self::assertTrue($condition->isSatisfied(
            ['customer_id' => $customerId],
            ['question' => $question, 'response_text' => 'A sale']
        ), 'the real answer must satisfy the condition for the matching expected text');

        self::assertFalse($condition->isSatisfied(
            ['customer_id' => $customerId],
            ['question' => $question, 'response_text' => 'A friend']
        ), 'a different expected text must not be satisfied');

        self::assertFalse($condition->isSatisfied(
            ['customer_id' => $unansweredCustomerId],
            ['question' => $question, 'response_text' => 'A sale']
        ), 'a customer who never answered must fail closed');

        $matchingIds = $condition->resolveCustomerIds(['question' => $question, 'response_text' => 'A sale']);
        self::assertContains($customerId, $matchingIds);
        self::assertNotContains($unansweredCustomerId, $matchingIds);
    }

    public function testDownstreamCampaignOnlyFiresAfterARealSurveyAnswerThroughTheRealDispatcher(): void
    {
        $customerId = $this->createCustomer();
        $askTrigger = 'test_generic_survey_ask_' . uniqid('', true);
        $gateTrigger = 'test_generic_survey_gate_' . uniqid('', true);
        $question = 'Which feature matters most to you?';
        $tag = 'wants-automation-' . uniqid('', true);

        $askCampaignId = $this->createCampaign($askTrigger);
        $this->addAction($askCampaignId, 'generic_survey', [
            'question' => $question,
            'question_type' => 'choice',
            'choices' => 'Automation,Reporting,Support',
        ]);

        $gateCampaignId = $this->createCampaign($gateTrigger);
        $this->addCondition($gateCampaignId, 'survey_response_equals', [
            'question' => $question,
            'response_text' => 'Automation',
        ]);
        $this->addAction($gateCampaignId, 'add_tag', ['tags' => [$tag]]);
        $this->tagsToClean[] = ['customerId' => $customerId, 'tag' => $tag];

        $this->cache->clean([CampaignDispatcher::CACHE_TAG]);

        $this->dispatcher->dispatch($askTrigger, ['customer_id' => $customerId]);

        // Not yet answered — the gated campaign must not fire.
        $this->dispatcher->dispatch($gateTrigger, ['customer_id' => $customerId]);
        self::assertFalse(
            $this->tagManager->hasTag($customerId, $tag),
            'condition not yet satisfied — the tag must not have been added'
        );

        // Answer for real, then dispatch the gate trigger again.
        $prompt = $this->latestSurveyPromptForCustomer($customerId);
        self::assertNotNull($prompt);
        $surveyPromptResource = self::$objectManager->get(SurveyPromptResource::class);
        $prompt->setResponseText('Automation');
        $prompt->setRespondedAt(date('Y-m-d H:i:s'));
        $surveyPromptResource->save($prompt);

        $this->dispatcher->dispatch($gateTrigger, ['customer_id' => $customerId]);

        self::assertTrue(
            $this->tagManager->hasTag($customerId, $tag),
            'a real answered response_text must gate the downstream campaign through the real dispatcher'
        );
    }
}
