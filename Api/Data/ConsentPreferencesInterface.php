<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

/**
 * One customer's own consent preferences, self-service edition — the same data
 * Block\Adminhtml\Gdpr\Index already computes for an admin looking a customer up, exposed to the
 * customer themselves. One typed ConsentChannelPreferenceInterface getter per message-delivery
 * channel (Model\ConsentChannel::Email/Sms/Push/WhatsApp/Rcs), plus a plain bool for Ads (which
 * has no topic concept) - see ConsentChannelPreferenceInterface's own docblock for why this
 * isn't a generic array<string, ...> map instead.
 */
interface ConsentPreferencesInterface
{
    /**
     * @return \Ordo\Automation\Api\Data\ConsentChannelPreferenceInterface
     */
    public function getEmail(): ConsentChannelPreferenceInterface;

    /**
     * @param \Ordo\Automation\Api\Data\ConsentChannelPreferenceInterface $email
     * @return $this
     */
    public function setEmail(ConsentChannelPreferenceInterface $email): self;

    /**
     * @return \Ordo\Automation\Api\Data\ConsentChannelPreferenceInterface
     */
    public function getSms(): ConsentChannelPreferenceInterface;

    /**
     * @param \Ordo\Automation\Api\Data\ConsentChannelPreferenceInterface $sms
     * @return $this
     */
    public function setSms(ConsentChannelPreferenceInterface $sms): self;

    /**
     * @return \Ordo\Automation\Api\Data\ConsentChannelPreferenceInterface
     */
    public function getPush(): ConsentChannelPreferenceInterface;

    /**
     * @param \Ordo\Automation\Api\Data\ConsentChannelPreferenceInterface $push
     * @return $this
     */
    public function setPush(ConsentChannelPreferenceInterface $push): self;

    /**
     * @return \Ordo\Automation\Api\Data\ConsentChannelPreferenceInterface
     */
    public function getWhatsapp(): ConsentChannelPreferenceInterface;

    /**
     * @param \Ordo\Automation\Api\Data\ConsentChannelPreferenceInterface $whatsapp
     * @return $this
     */
    public function setWhatsapp(ConsentChannelPreferenceInterface $whatsapp): self;

    /**
     * @return \Ordo\Automation\Api\Data\ConsentChannelPreferenceInterface
     */
    public function getRcs(): ConsentChannelPreferenceInterface;

    /**
     * @param \Ordo\Automation\Api\Data\ConsentChannelPreferenceInterface $rcs
     * @return $this
     */
    public function setRcs(ConsentChannelPreferenceInterface $rcs): self;

    /**
     * @return bool
     */
    public function getAds(): bool;

    /**
     * @param bool $ads
     * @return $this
     */
    public function setAds(bool $ads): self;
}
