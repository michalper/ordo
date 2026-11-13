<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\DataObject;
use Ordo\Automation\Api\Data\ConsentChannelPreferenceInterface;
use Ordo\Automation\Api\Data\ConsentPreferencesInterface;

class ConsentPreferences extends DataObject implements ConsentPreferencesInterface
{
    public function getEmail(): ConsentChannelPreferenceInterface
    {
        /** @var ConsentChannelPreferenceInterface $email */
        $email = $this->getData('email');
        return $email;
    }

    public function setEmail(ConsentChannelPreferenceInterface $email): self
    {
        $this->setData('email', $email);
        return $this;
    }

    public function getSms(): ConsentChannelPreferenceInterface
    {
        /** @var ConsentChannelPreferenceInterface $sms */
        $sms = $this->getData('sms');
        return $sms;
    }

    public function setSms(ConsentChannelPreferenceInterface $sms): self
    {
        $this->setData('sms', $sms);
        return $this;
    }

    public function getPush(): ConsentChannelPreferenceInterface
    {
        /** @var ConsentChannelPreferenceInterface $push */
        $push = $this->getData('push');
        return $push;
    }

    public function setPush(ConsentChannelPreferenceInterface $push): self
    {
        $this->setData('push', $push);
        return $this;
    }

    public function getWhatsapp(): ConsentChannelPreferenceInterface
    {
        /** @var ConsentChannelPreferenceInterface $whatsapp */
        $whatsapp = $this->getData('whatsapp');
        return $whatsapp;
    }

    public function setWhatsapp(ConsentChannelPreferenceInterface $whatsapp): self
    {
        $this->setData('whatsapp', $whatsapp);
        return $this;
    }

    public function getRcs(): ConsentChannelPreferenceInterface
    {
        /** @var ConsentChannelPreferenceInterface $rcs */
        $rcs = $this->getData('rcs');
        return $rcs;
    }

    public function setRcs(ConsentChannelPreferenceInterface $rcs): self
    {
        $this->setData('rcs', $rcs);
        return $this;
    }

    public function getAds(): bool
    {
        return (bool) $this->getData('ads');
    }

    public function setAds(bool $ads): self
    {
        $this->setData('ads', $ads);
        return $this;
    }
}
