<?php

namespace Slate\NetworkHub;

use Site;

use Emergence\Connectors\AbstractConnector;
use Emergence\Connectors\IJob;
use Emergence\Connectors\ISynchronize;
use Emergence\Connectors\Exceptions\SyncException;

use Firebase\JWT\JWT;

use Slate\NetworkHub\User as NetworkUser;

class Connector extends AbstractConnector implements ISynchronize
{
    public static $title = 'Slate Network Hub';
    public static $connectorId = 'slate-network-hub';

    public static function handleRequest($action = null)
    {
        switch ($action ? $action : $action = static::shiftPath()) {
            case 'login':
                return static::handleNetworkLoginRequest();
            default:
                return parent::handleRequest($action);
        }
    }

    public static function handleNetworkLoginRequest()
    {
        if (!empty($_COOKIE['JWT'])) {
            // TODO: route user to original returnURL
        }
        // verify & decode JWT token from school request {username, domain}
        if (!empty($_REQUEST['JWT']) && !empty($_REQUEST['domain'])) {
            if (!$NetworkSchool = School::getByField('Domain', $_REQUEST['domain'])) {
                return static::throwInvalidRequestError('Unable to complete request. Please contact an administrator for support.');
            }

            try {
                $token = JWT::decode($_REQUEST['JWT'], $NetworkSchool->APIKey, ['HS256']);
                \MICS::dump($token, 'decoded token');
            } catch (\Exception $e) {
#                Logger::error();
                return static::throwInvalidRequestError('Unable to decode JWT Token. Please contact an administrator for support.');
            }


            // store cookie/session and proceed to original returnURL
        }

        // find user school from input
        if (!empty($_REQUEST['email'])) {
            // try to redirect user network school's login
            $NetworkUser = NetworkUser::getByWhere([
                'Email' => $_REQUEST['email']
            ]);

            if (!$NetworkUser) {
                return static::throwInvalidRequestError('Unable to find the user within the network. Please contact an admininstrator for support.');
            }

            $token = JWT::encode([
                'username' => $NetworkUser->Email,
                'domain' => Site::getConfig('primary_hostname'),
                'returnUrl' => '/connectors/slate-network-hub/login'
            ], $NetworkUser->School->APIKey);

            $queryParameters = http_build_query([
                'JWT' => $token
            ]);

            $networkSiteLoginUrl = 'http://'.$NetworkUser->School->Domain.'/network-api/login?'.$queryParameters;
            header("Location:{$networkSiteLoginUrl}");
        }
        // show network login screen
        return static::respond('network-login');
    }

    public static function synchronize(IJob $Job, $pretend = true)
    {
        $results = [
            'created' => [
                'total' => 0
            ]
        ];

        $NetworkHubSchools = School::getAll();
        if (empty($NetworkHubSchools)) {
            $Job->error(
                'No network schools found. Please ensure they have been added.',
                [
                    'query_results' => $NetworkHubSchools
                ]
            );
            return false;
        }

        foreach ($NetworkHubSchools as $NetworkHubSchool) {
            try {
                $syncResults = static::pullNetworkUsers($NetworkHubSchool, $pretend);
                $results['created'][$NetworkHubSchool->Domain] = $syncResults['created'];
                $results['created']['total'] += $syncResults['created'];
            } catch (SyncException $e) {
                $Job->logException($e);
            }
        }

        return $results;
    }

    public static function pullNetworkUsers(School $NetworkSchool, $pretend = true)
    {
        $results = [
            'created' => 0,
            'updated' => 0,
            'verified' => 0,
            'skipped' => 0,
            'skippedUsers' => []
        ];
        try {
            $networkUsers = $NetworkSchool->getNetworkUsers();
            foreach ($networkUsers as $networkUser) {
                try {
                    $syncResults = static::syncNetworkUser($Job, $NetworkSchool, $networkUser);

                    if ($syncResults->getStatus() === SyncResult::STATUS_CREATED) {
                        $results['created']++;
                    } elseif ($syncResults->getStatus() === SyncResult::STATUS_UPDATED) {
                        $results['updated']++;
                    } elseif ($syncResults->getStatus() === SyncResult::STATUS_VERIFIED) {
                        $results['verified']++;
                    } elseif ($syncResults->getStatus() === SyncResult::STATUS_SKIPPED) {
                        $results['skippedUsers'][] = $networkUser;
                        $results['skipped']++;
                    }
                } catch (SyncException $s) {
                    $Job->logException($e);
                }
            }
        } catch (\Exception $e) {
            return new SyncException($e->getMessage(), []);
        }
    }

    protected static function syncNetworkUser(IJob $Job, School $NetworkSchool, array $networkUser)
    {
        if (empty($networkUser['PrimaryEmail']['Data'])) {
            return new SyncResult(
                SyncResult::STATUS_SKIPPED,
                'Skipped {slateUsername} @ {slateDomain} due to missing PrimaryEmail',
                [
                    'slateUsername' => $networkUser['Username'],
                    'slateDomain' => $NetworkSchool->Domain
                ]
            );
        }

        if (!$NetworkUserRecord = NetworkUser::getByField('Email', $networkUser['PrimaryEmail']['Data'])) {
                $NetworkUserRecord = NetworkUser::create([
                    'Email' => $networkUser['PrimaryEmail']['Data'],
                    'FirstName' => $networkUser['FirstName'],
                    'LastName' => $networkUser['LastName'],
                    'SchoolID' => $NetworkSchool->ID
                ], true);
                return new SyncResult(
                    SyncResult::STATUS_CREATED,
                    'Created new network user for {slateUsername} @ {slateDomain}',
                    [
                        'slateUsername' => $NetworkUserRecord->Email,
                        'slateDomain' => $NetworkSchool->Domain
                    ]
                );
            } elseif (
                $NetworkUserRecord->FirstName != $networkUser['FirstName'] ||
                $NetworkUserRecord->LastName != $networkUser['LastName']
            ) {
                $NetworkUserRecord->setFields([
                    'FirstName' => $networkUser['FirstName'],
                    'LastName' => $networkUser['LastName']
                ]);
                $NetworkUserRecord->save();
                return new SyncResult(
                    SyncResult::STATUS_UPDATED,
                    'Updated network user {slateUsername} @ {slateDomain}',
                    [
                        'slateUsername' => $NetworkUserRecord->Email,
                        'slateDomain' => $NetworkSchool->Domain
                    ]
                );
            }

            return new SyncResult(
                SyncResult::STATUS_VERIFIED,
                'Network user {slateUsername} @ {slateDomain} found and verified',
                [
                    'slateUsername' => $NetworkUserRecord->Email,
                    'slateDomain' => $NetworkSchool->Domain
                ]
            );
    }
}
