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
 * ConsentManager::hasTopicConsent()'s own default-consented-unless-explicit-opt-out semantics
 * needs to stay simple - see that method's own call sites for how each existing sender was
 * classified.
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
}
