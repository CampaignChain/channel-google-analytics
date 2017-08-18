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

namespace CampaignChain\Channel\GoogleAnalyticsBundle\Service;

use CampaignChain\CoreBundle\Entity\Location;
use CampaignChain\CoreBundle\EntityService\LocationService;
use CampaignChain\Location\GoogleAnalyticsBundle\Entity\Profile;
use CampaignChain\Security\Authentication\Client\OAuthBundle\Entity\Token;
use CampaignChain\Security\Authentication\Client\OAuthBundle\EntityService\ApplicationService;
use CampaignChain\Security\Authentication\Client\OAuthBundle\EntityService\TokenService;
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Doctrine\ManagerRegistry;

class RestClient
{
    /**
     * @var \Google_Service_AnalyticsReporting
     */
    private $client;

    /**
     * @var TokenService
     */
    private $tokenService;

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var Profile
     */
    private $profile;

    public function __construct(ManagerRegistry $doctrine, TokenService $tokenService)
    {
        $this->tokenService = $tokenService;
        $this->em = $doctrine->getManager();
    }

    public function connectByLocation(Location $location)
    {
        // Get Access Token and Token Secret
        $token = $this->tokenService->getToken($location);

        $profileRepo = $this->em->getRepository('CampaignChainLocationGoogleAnalyticsBundle:Profile');
        $this->profile = $profileRepo->findOneBy(array('location' => $location));

        return $this->connect($token);
    }

    public function connect(Token $token)
    {
        $client = new \Google_Client();
        $client->setAuthConfig(array(
            'client_id' => $token->getApplication()->getKey(),
            'client_secret' => $token->getApplication()->getSecret(),
        ));
        $client->setAccessType("offline");
        $client->setAccessToken(
            array(
                'access_token' => $token->getAccessToken(),
                'refresh_token' => $token->getRefreshToken(),
                'expires_in' => $token->getExpiresIn(),
            )
        );
        $client->addScope([\Google_Service_Analytics::ANALYTICS_READONLY]);
        $this->client = new \Google_Service_AnalyticsReporting($client);

        return $this;
    }

    public function getTraffic($startDate, $endDate, $metrics, $segment)
    {
        throw new \Exception('Transitioning to Google API version 2.0 - still work in progress');
        return $this->client->data_ga->get('ga:' . $this->profile->getProfileId(), $startDate, $endDate, $metrics, array(
            'dimensions' => 'ga:date',
            'segment' => $segment,
        ));
    }

    public function getMostActiveVisitors(
        $startDate, $endDate,
        $dimensionName,
        $minSessions, $minAvgSessionDuration, $maxBounceRate
    )
    {
        /*
         * Date ranges
         */
        $dateRange = new \Google_Service_AnalyticsReporting_DateRange();
        $dateRange->setStartDate($startDate);
        $dateRange->setEndDate($endDate);

        /*
         * Metrics
         */
        $sessions = new \Google_Service_AnalyticsReporting_Metric();
        $sessions->setExpression("ga:sessions");
        $sessions->setAlias("sessions");

        $avgSessionDuration = new \Google_Service_AnalyticsReporting_Metric();
        $avgSessionDuration->setExpression("ga:avgSessionDuration");
        $avgSessionDuration->setAlias("avgSessionDuration");

        $bounceRate = new \Google_Service_AnalyticsReporting_Metric();
        $bounceRate->setExpression("ga:bounceRate");
        $bounceRate->setAlias("bounceRate");

        /*
         * Metrics filters
         */
        $sessionsFilter = new \Google_Service_AnalyticsReporting_MetricFilter();
        $sessionsFilter->setMetricName('ga:sessions');
        $sessionsFilter->setOperator('GREATER_THAN');
        $sessionsFilter->setComparisonValue((string) $minSessions);

        $avgSessionDurationFilter = new \Google_Service_AnalyticsReporting_MetricFilter();
        $avgSessionDurationFilter->setMetricName('ga:avgSessionDuration');
        $avgSessionDurationFilter->setOperator('GREATER_THAN');
        $avgSessionDurationFilter->setComparisonValue((string) $minAvgSessionDuration);

        $bounceRateFilter = new \Google_Service_AnalyticsReporting_MetricFilter();
        $bounceRateFilter->setMetricName('ga:bounceRate');
        $bounceRateFilter->setOperator('LESS_THAN');
        $bounceRateFilter->setComparisonValue((string) $maxBounceRate);

        $activeVisitorsFilterClause = new \Google_Service_AnalyticsReporting_MetricFilterClause();
        $activeVisitorsFilterClause->setOperator('AND');
        $activeVisitorsFilterClause->setFilters([$sessionsFilter, $avgSessionDurationFilter, $bounceRateFilter]);

        // Create the Dimension objects.
        $dimension = new \Google_Service_AnalyticsReporting_Dimension();
        $dimension->setName("ga:".$dimensionName);

        // Create the Pivot object.
//        $pivot = new \Google_Service_AnalyticsReporting_Pivot();
//        $pivot->setDimensions(array($age));
//        $pivot->setMaxGroupCount(3);
//        $pivot->setStartGroup(0);
//        $pivot->setMetrics(array($sessions, $pageviews));

        // Create the ReportRequest object.
        $request = new \Google_Service_AnalyticsReporting_ReportRequest();
        $request->setViewId($this->profile->getProfileId());
        $request->setDateRanges(array($dateRange));
        $request->setDimensions(array($dimension));
        //$request->setPivots(array($pivot));
        $request->setMetrics(array($sessions, $avgSessionDuration, $bounceRate));
        $request->setMetricFilterClauses([$activeVisitorsFilterClause]
        );

        // Create the GetReportsRequest object.
        $getReport = new \Google_Service_AnalyticsReporting_GetReportsRequest();
        $getReport->setReportRequests(array($request));

        // Call the batchGet method.
        $body = new \Google_Service_AnalyticsReporting_GetReportsRequest();
        $body->setReportRequests( array($request) );
        /** @var \Google_Service_AnalyticsReporting_Report $report */
        return $this->client->reports->batchGet( $body )->getReports()[0];
    }
}
