<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

/**
 * One message-delivery channel's own consent state - the channel-level opt-in/out plus its two
 * topic-level ones (Model\ConsentTopic::Promotional/AccountUpdates). A dedicated, strongly typed
 * nested object rather than a generic array<string, bool> map: a bare associative array doesn't
 * survive Magento's own WebAPI/REST output serialization correctly (confirmed live against a
 * real customer token - string keys were silently dropped, and a nested array serialized as a
 * literal JSON-encoded string instead of a nested object), the same reason every other nested
 * structure in this module's Api\Data layer (e.g. OrderApprovalDecisionLinksInterface) is always
 * its own typed interface, never a raw array.
 */
interface ConsentChannelPreferenceInterface
{
    /**
     * @return bool
     */
    public function getConsented(): bool;

    /**
     * @param bool $consented
     * @return $this
     */
    public function setConsented(bool $consented): self;

    /**
     * @return bool
     */
    public function getPromotional(): bool;

    /**
     * @param bool $promotional
     * @return $this
     */
    public function setPromotional(bool $promotional): self;

    /**
     * @return bool
     */
    public function getAccountUpdates(): bool;

    /**
     * @param bool $accountUpdates
     * @return $this
     */
    public function setAccountUpdates(bool $accountUpdates): self;
}
