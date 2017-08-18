<?php
/*
 * Copyright 2016 CampaignChain, Inc. <info@campaignchain.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *    http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace CampaignChain\Channel\GoogleAnalyticsBundle\Controller;

use CampaignChain\Channel\GoogleAnalyticsBundle\Service\RestClient;
use CampaignChain\CoreBundle\Entity\Channel;
use CampaignChain\CoreBundle\Entity\Location;
use CampaignChain\Location\GoogleAnalyticsBundle\Entity\Profile;
use CampaignChain\Security\Authentication\Client\OAuthBundle\Entity\Token;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use CampaignChain\CoreBundle\Util\ParserUtil;

class GoogleAnalyticsController extends Controller
{
    const RESOURCE_OWNER = 'Google';

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
        /** @var RestClient $analyticsClient */
        $analyticsClient = $this->get('campaignchain.channel.google_analytics.rest_client');
        $analyticsClient->connect($request->getSession()->get('token'));

        $profileIds = $this->getDoctrine()->getRepository('CampaignChainLocationGoogleAnalyticsBundle:Profile')->getGoogleIds();

        $allProfiles = [];
        $allConnected = true;
        foreach ($analyticsClient->listManagementAccounts() as $account) {
            $profiles = $analyticsClient->listManagementProfiles($account->getId(), '~all');
            /** @var \Google_Service_Analytics_Profile $profile */
            foreach ($profiles as $profile) {
                $allProfiles[] = $profile;
                if(!in_array($profile->getWebPropertyId(), $profileIds)){
                    $allConnected = false;
                }
            }
        }

        return $this->render(
            '@CampaignChainChannelGoogleAnalytics/list_properties.html.twig',
            array(
                'page_title' => 'Connect with Google Analytics',
                'profiles' => $allProfiles,
                'dbProfileIds' => $profileIds,
                'all_connected' => $allConnected,
            )
        );
    }

    public function createLocationAction(Request $request)
    {

        $selectedIds = $request->get('google-analytics-profile-id', []);

        if (empty($selectedIds)) {
            $this->addFlash('warning', 'Please select at least one Google Analytics property');

            return $this->redirectToRoute('campaignchain_channel_google_analytics_list_properties');
        }

        /** @var Token $token */
        $token = $request->getSession()->get('token');
        $em = $this->getDoctrine()->getManager();
        $token = $em->merge($token);

        $websiteChannelModule = $em->getRepository('CampaignChainCoreBundle:ChannelModule')->findOneBy([
            'identifier' => 'campaignchain-website'
        ]);

        $googleAnalyticsChannelModule = $em->getRepository('CampaignChainCoreBundle:ChannelModule')->findOneBy([
            'identifier' => 'campaignchain-google-analytics'
        ]);

        foreach ($selectedIds as $analyticsId) {
            list($accountId, $propertyId, $profileId) = explode('|', $analyticsId);
            /** @var RestClient $analyticsClient */
            $analyticsClient = $this->get('campaignchain.channel.google_analytics.rest_client');
            $analyticsClient->connect($request->getSession()->get('token'));
            /** @var \Google_Service_Analytics_Profile $profile */
            $profile = $analyticsClient->getManagementProfile($accountId, $propertyId, $profileId);

            $wizard = $this->get('campaignchain.core.channel.wizard');
            $wizard->start(new Channel(), $googleAnalyticsChannelModule);
            $wizard->setName($profile->getName());
            // Get the location module.
            $locationService = $this->get('campaignchain.core.location');
            $locationModule = $locationService->getLocationModule('campaignchain/location-google-analytics', 'campaignchain-google-analytics');

            $location = new Location();
            $location->setIdentifier($profile->getId());
            $location->setName($profile->getName());
            $location->setLocationModule($locationModule);
            $google_base_url = 'https://www.google.com/analytics/web/#report/visitors-overview/';
            $location->setUrl($google_base_url . 'a' . $profile->getAccountId() . 'w' . $profile->getInternalWebPropertyId() . 'p' . $profile->getId());

            $em->persist($location);

            try {
                $em->flush();
            } catch (UniqueConstraintViolationException $e) {
                //This GA endpoint is already connected
                $this->addFlash(
                    'warning',
                    'The Google Analytics Property <a href="#">'.$profile->getName().'</a> is already connected.'
                );

                continue;
            }

            $wizard->addLocation($location->getIdentifier(), $location);

            $wizard->persist();
            $wizard->end();
            //Check if the if the belonging website location exists, if not create a new website location
            $website = $em->getRepository('CampaignChainCoreBundle:Location')
                ->findOneByUrl($profile->getWebsiteUrl());
            //Create website location that belongs to the GA location
            if (!$website) {
                $websiteLocationModule = $locationService->getLocationModule('campaignchain/location-website', 'campaignchain-website');
                $websiteLocationName = ParserUtil::getHTMLTitle($profile->getWebsiteUrl());
                $websiteLocation = new Location();
                $websiteLocation->setLocationModule($websiteLocationModule);
                $websiteLocation->setName($websiteLocationName);
                $websiteLocation->setUrl($profile->getWebsiteUrl());
                $em->persist($websiteLocation);

                $wizard->start(new Channel(), $websiteChannelModule);
                $wizard->setName($websiteLocationName);
                $wizard->addLocation($profile->getWebsiteUrl(), $websiteLocation);
                $wizard->persist();

                $website = $websiteLocation;
            }

            $entityToken = clone $token;
            $entityToken->setLocation($location);
            $entityToken->setApplication($token->getApplication());
            $em->persist($entityToken);

            $analyticsProfile = new Profile();
            $analyticsProfile->setAccountId($profile->getAccountId());
            $analyticsProfile->setPropertyId($profile->getWebPropertyId());
            $analyticsProfile->setProfileId($profile->getId());
            $analyticsProfile->setIdentifier($profile->getId());
            $analyticsProfile->setDisplayName($profile->getName());
            $analyticsProfile->setLocation($location);

            $analyticsProfile->setBelongingLocation($website);

            $em->persist($analyticsProfile);
            $em->flush();

            $this->addFlash(
                'success',
                'The Google Analytics Property <a href="#">'.$profile->getName().'</a> was connected successfully.'
            );
        }

        return $this->redirectToRoute('campaignchain_core_location');
    }
}