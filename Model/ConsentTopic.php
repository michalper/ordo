<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

/**
 * A further split of a message-delivery channel's own opt-out (ConsentChannel::Email/Sms/Push/
 * WhatsApp — deliberately NOT ConsentChannel::Ads, which isn't a delivery channel at all) into
 * what the send is actually about, so a customer can opt out of promotional sends while keeping
 * account/order-related ones, or vice versa, instead of an all-or-nothing per-channel toggle. See
 * docs/adr/0026 for why this is a second, orthogonal opt-out register
 * (Model\CustomerConsentTopic) rather than a change to ordo_customer_consent itself.
 *
 * Deliberately only two topics, not an open-ended admin-defined list: every existing
 * consent-gated send in this module (the five email reminder crons, the four send_* campaign
 * actions) maps cleanly onto one of these two, and a small, fixed enum is exactly what
 * ConsentManager::hasTopicConsent()'s own per-topic default-consent semantics (see
 * defaultsToConsented() below) needs to stay simple - see that method's own call sites for how
 * each existing sender was classified.
 */
enum ConsentTopic: string
{
    /** Marketing/recovery sends driven by business goals, not the customer's own account state:
     *  win-back, abandoned cart, offer expiry, and the default for any admin-built campaign
     *  send_email/send_sms/send_whatsapp/send_push action that didn't set a topic explicitly. */
    case Promotional = 'promotional';

    /** Reminders about the customer's own account/order state: reorder reminders ("you're due to
     *  reorder X"), credit limit alerts ("you're near your limit"). Not transactional (order
     *  confirmation, approval decisions) - those were never consent-gated at all, see
     *  Model\ConsentManager's own docblock. */
    case AccountUpdates = 'account_updates';

    /**
     * Whether a customer with no explicit ordo_customer_consent_topic row for this topic should
     * be treated as consented by default. AccountUpdates keeps this module's original
     * default-opt-out-register behavior (see ConsentManager's class docblock) - these are
     * account/order-service notices, the same standard as transactional mail.
     *
     * Promotional does not: this is electronic marketing, and EU ePrivacy/PECR-style rules
     * require an affirmative opt-in for that specifically, not merely an honored opt-out - "no
     * explicit opt-out recorded" is not the same thing as "the customer actually agreed to
     * receive marketing." See docs/adr/0027 for the decision and its consequences (in particular:
     * this makes every existing customer with no row default to NOT consented for Promotional,
     * a deliberate behavior change, not an oversight).
     */
    public function defaultsToConsented(): bool
    {
        return match ($this) {
            self::Promotional => false,
            self::AccountUpdates => true,
        };
    }
}
