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

CampaignChain\Channel\GoogleAnalyticsBundle\Service;

use CampaignChain\CoreBundle\EntityService\LocationService;
use CampaignChain\Security\Authentication\Client\OAuthBundle\EntityService\ApplicationService;
use CampaignChain\Security\Authentication\Client\OAuthBundle\EntityService\TokenService;

class RestClient
{

    /**
     * @var LocationService
     */
    private $locationService;

    /**
     * @var ApplicationService
     */
    private $applicationService;

    /**
     * @var TokenService
     */
    private $tokenService;

    const RESOURCE_OWNER = 'Google';

    public function __construct(LocationService $locationService, ApplicationService $applicationService, TokenService $tokenService)
    {
        $this->locationService = $locationService;
        $this->applicationService = $applicationService;
        $this->tokenService = $tokenService;
    }

    /**
     * @return \Google_Service_Analytics
     * @throws \Exception
     * @throws \Google_Exception
     */
    public function getService($token = null)
    {


        $application = $this
            ->applicationService
            ->getApplication(self::RESOURCE_OWNER);

        if ($token === null) {
            $token = $this->getToken();
        }

        $authConfig = array(
            'web' => array(
                'client_id' => $application->getKey(),
                'client_secret' => $application->getSecret()

            )
        );

        $client = new \Google_Client();
        $client->setAuthConfig(json_encode($authConfig));
        $client->setAccessToken(
            json_encode(
                array(
                    'access_token' => $token->getAccessToken(),
                    'refresh_token' => $token->getRefreshToken(),
                )
            )
        );
        return new \Google_Service_Analytics($client);

    }

    private function getToken()
    {
        $locationModule = $this
            ->locationService
            ->getLocationModule('campaignchain/location-google-analytics', 'campaignchain-google-analytics');

        foreach($locationModule->getLocations() as $location) {
            return $this->tokenService->getToken($location);
        }

        return null;
    }
}
