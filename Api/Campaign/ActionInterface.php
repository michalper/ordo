<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Campaign;

/**
 * One executable action type (e.g. "send this email", "add this tag", "generate a coupon").
 * Registered by string key in di.xml (Model\Campaign\ActionPool). Multiple actions on the same
 * campaign run in their configured order — e.g. "generate_coupon" then "send_email" so the
 * email action can read the coupon code the previous action put into the context.
 */
interface ActionInterface
{
    /**
     * @param array<string, mixed> $context   event-specific data, mutable — actions can add to it
     *                                        for later actions in the same campaign to read
     * @param array<string, mixed> $params    action configuration saved on the campaign
     */
    public function execute(array &$context, array $params): void;
}
