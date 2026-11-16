<?php

declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Shared "try the save, log and swallow on failure" body - confirmed byte-for-byte identical
 * across Notify, ShowPopup, NpsSurvey and GenericSurvey before this extraction; only the entity
 * being saved differed.
 *
 * Deliberately swallows rather than re-throws, even though
 * CampaignDispatcher::runOneActionOrSplitStoppingChainOnFailure() (ADR 0068) would now catch and
 * stop the rest of this campaign's action chain on an uncaught throw from execute(). A failed
 * save here means only this one action's own row never got written - the rest of the chain has
 * no reason to depend on it the way a later action might depend on, say, a coupon a prior action
 * generated - so this keeps the narrower "skip and log just this action" failure mode these four
 * actions already had before ADR 0068 existed, rather than escalating it to "stop the chain"
 * after the fact.
 *
 * @property LoggerInterface $logger the using class's own constructor-promoted property - not
 *   declared by this trait (a trait can't declare a constructor), but required by name for this
 *   trait's method to compile.
 */
trait LogsSaveFailureTrait
{
    abstract protected function actionLabel(): string;

    /**
     * @param callable(): mixed $save
     */
    private function saveOrLogFailure(callable $save): void
    {
        try {
            $save();
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: campaign %s action failed to save: %s',
                $this->actionLabel(),
                $e->getMessage()
            ));
        }
    }
}
