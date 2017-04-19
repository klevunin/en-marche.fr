<?php

namespace AppBundle\FeatureFlag;

use GeoIp2\Database\Reader;
use Symfony\Bundle\TwigBundle\Controller\ExceptionController;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;

class CampaignSilenceListener implements EventSubscriberInterface
{
    private $geoip;
    private $twig;
    private $disableAmerica;
    private $disableEurope;

    public function __construct(Reader $geoip, \Twig_Environment $twig, bool $disableAmerica, bool $disableEurope)
    {
        $this->geoip = $geoip;
        $this->twig = $twig;
        $this->disableAmerica = $disableAmerica;
        $this->disableEurope = $disableEurope;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            'kernel.controller' => 'onKernelController',
        ];
    }

    public function onKernelController(FilterControllerEvent $event)
    {
        $request = $event->getRequest();
        $request->attributes->set('_campaign_expired', false);

        if ($event->getController()[0] instanceof ExceptionController) {
            return;
        }

        $clientIp = $request->getClientIps();
        $clientIp = end($clientIp);

        try {
            $country = $this->geoip->country($clientIp)->country->isoCode;
        } catch (\Exception $e) {
            $country = 'GP'; // By default, be large
        }

        /*
         * For each timezone of the found country, if at least one timezone is exceeding
         * the time at which the website should be disabled, we disable it.
         *
         * If the absolute time is before the Europe/Lisbon time, expires on Thursday, else on Friday.
         */
        $isAmerica = false;
        $lisbonTime = (new \DateTime('now', new \DateTimeZone('Europe/Lisbon')));

        foreach (\DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY, $country) as $timezone) {
            $clientTime = (new \DateTime('now', new \DateTimeZone($timezone)));

            if ((int) $clientTime->format('dHis') < (int) $lisbonTime->format('dHis')) {
                $isAmerica = true;
                break;
            }
        }

        $expired = $this->disableEurope || ($isAmerica && $this->disableAmerica);
        $request->attributes->set('_campaign_expired', $expired);

        if (!$expired || $request->attributes->get('_enable_campaign_silence', false)) {
            return;
        }

        $event->setController([$this, 'campaignIsSilentAction']);
    }

    public function campaignIsSilentAction()
    {
        return new Response($this->twig->render('campaign_silent.html.twig'));
    }
}