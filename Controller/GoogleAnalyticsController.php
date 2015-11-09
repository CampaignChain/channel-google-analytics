<?php
/*
 * This file is part of the CampaignChain package.
 *
 * (c) CampaignChain Inc. <info@campaignchain.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CampaignChain\Channel\GoogleAnalyticsBundle\Controller;

use CampaignChain\CoreBundle\Entity\Location;
use CampaignChain\Location\GoogleAnalyticsBundle\Entity\Profile;
use CampaignChain\Security\Authentication\Client\OAuthBundle\Entity\Token;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class GoogleAnalyticsController extends Controller
{
    const RESOURCE_OWNER = 'GoogleAnalytics';

    private $applicationInfo = array(
        'key_labels' => array('id', 'App Key'),
        'secret_labels' => array('secret', 'App Secret'),
        'config_url' => 'https://code.google.com',
        'parameters' => array(
            "approval_prompt" => 'force',
            "access_type" => "offline",
            "scope" => 'https://www.googleapis.com/auth/plus.me https://www.googleapis.com/auth/analytics.edit https://www.googleapis.com/auth/analytics https://www.googleapis.com/auth/userinfo.profile'
        ),
    );


    public function createAction()
    {
        $oauthApp = $this->get('campaignchain.security.authentication.client.oauth.application');

        $application = $oauthApp->getApplication(self::RESOURCE_OWNER);

        if(!$application){
            return $oauthApp->newApplicationTpl(self::RESOURCE_OWNER, $this->applicationInfo);
        }
        else {
            return $this->render(
                'CampaignChainChannelGoogleAnalyticsBundle::index.html.twig',
                array(
                    'page_title' => 'Connect with Google Analytics',
                    'app_id' => $application->getKey(),
                )
            );
        }
    }
    public function loginAction(Request $request){
        $oauth = $this->get('campaignchain.security.authentication.client.oauth.authentication');
        $status = $oauth->authenticate(self::RESOURCE_OWNER, $this->applicationInfo);
        $profile = $oauth->getProfile();
        if($status){
            try {
                $request->getSession()->set('token', $oauth->getToken());
            } catch (\Exception $e) {
                throw $e;
            }
        }

        return $this->render(
            'CampaignChainChannelTwitterBundle:Create:login.html.twig',
            array(
                'redirect' => $this->generateUrl('campaignchain_channel_google_analytics_list_properties')
            )
        );
    }

    public function listPropertiesAction(Request $request)
    {
        $token = $request->getSession()->get('token');
        $analyticsClient = $this->get('campaignchain_report_google_analytics.service_client')->getService($token);

        $allProfiles = [];
        foreach ($analyticsClient->management_accounts->listManagementAccounts() as $account) {
            $profiles = $analyticsClient->management_profiles
                ->listManagementProfiles($account->getId(), '~all');
            foreach ($profiles as $profile) {
                $allProfiles[] = $profile;
            }

        }

        return $this->render(
            '@CampaignChainChannelGoogleAnalytics/list_properties.html.twig',
            array(
                'page_title' => 'Connect with Google Analytics',
                'profiles' => $allProfiles
            )
        );
    }

    public function createLocationAction(Request $request)
    {

        /** @var Token $token */
        $token = $request->getSession()->get('token');
        list($accountId, $profileId) = explode('|', $request->get('google-analytics-property-id'));
        $analyticsClient = $this->get('campaignchain_report_google_analytics.service_client')->getService($token);
        $profile = $analyticsClient->management_webproperties->get($accountId, $profileId);

        $wizard = $this->get('campaignchain.core.channel.wizard');
        $wizard->setName($profile->getName());
        // Get the location module.
        $locationService = $this->get('campaignchain.core.location');
        $locationModule = $locationService->getLocationModule('campaignchain/location-google-analytics', 'campaignchain-google-analytics');

        $oauthApp = $this->get('campaignchain.security.authentication.client.oauth.application');

        $application = $oauthApp
            ->getApplication(self::RESOURCE_OWNER);

        $em = $this->getDoctrine()->getManager();

        $location = new Location();
        $location->setIdentifier($profile->getId());
        $location->setName($profile->getName());
        $location->setLocationModule($locationModule);
        $location->setUrl($profile->getWebsiteUrl());
        $em->persist($location);
        $em->flush();
        $wizard->addLocation($location->getIdentifier(), $location);

        $channel = $wizard->persist();
        $wizard->end();

        $tokenEntity = $em->merge($token);
        $tokenEntity->setLocation($location);
        $em->persist($tokenEntity);

        $analyticsProfile = new Profile();
        $analyticsProfile->setAccountId($profile->getAccountId());
        $analyticsProfile->setProfileId($profile->getId());
        $analyticsProfile->setIdentifier($profile->getId());
        $analyticsProfile->setDisplayName($profile->getName());
        $analyticsProfile->setIdentifier($profile->getWebsiteUrl());
        $analyticsProfile->setLocation($location);
        $em->persist($analyticsProfile);

        $em->flush();

        $this->get('session')->getFlashBag()->add(
            'success',
            'The Google Analytics Property <a href="#">'.$profile->getName().'</a> was connected successfully.'
        );
        return $this->redirectToRoute('campaignchain_core_channel');
    }
}