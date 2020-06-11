<?php

namespace Slate\NetworkHub;

use Site;

use Emergence\Connectors\AbstractConnector;
use Emergence\Connectors\IJob;
use Emergence\Connectors\ISynchronize;
use Emergence\Connectors\Exceptions\SyncException;
use Emergence\Connectors\SyncResult;
use Emergence\KeyedDiff;
use Emergence\People\ContactPoint\Email;
use Emergence\Util\Data as DataUtil;

use Firebase\JWT\JWT;

use Psr\Log\LoggerInterface;

use Slate\NetworkHub\User as NetworkUser;

class Connector extends AbstractConnector implements ISynchronize
{
    public static $title = 'Slate Network Hub';
    public static $connectorId = 'network-hub';

    public static function handleRequest($action = null)
    {
        switch ($action ? $action : $action = static::shiftPath()) {
            case 'login':
                return static::handleNetworkLoginRequest();

            default:
                return static::handleRequest($action);
        }
    }

    public static function handleNetworkLoginRequest($returnUrl = false)
    {
        if (!empty($_COOKIE['JWT'])) {
            // TODO: route user to original returnURL
        }
        // verify & decode JWT token from school request {username, domain}
        if (!empty($_REQUEST['JWT']) && !empty($_REQUEST['domain'])) {
            if (!$NetworkSchool = School::getByWhere([
                'Domain' => [
                    'operator' => 'LIKE',
                    'value' => sprintf('%%%s', $_REQUEST['domain'])
                ]
            ])) {
                return static::throwInvalidRequestError('Domain not found: Unable to complete request. Please contact an administrator for support.');
            }

            try {
                $token = JWT::decode($_REQUEST['JWT'], $NetworkSchool->APIKey, ['HS256']);
            } catch (\Exception $e) {
                // Logger::error();
                return static::throwInvalidRequestError('Unable to decode JWT Token. Please contact an administrator for support.');
            }


            // store cookie/session and proceed to original returnURL
        }

        // find user school from input
        if (!empty($_REQUEST['email'])) {
            // try to redirect user network school's login
            $EmailContactPoint = Email::getByWhere([
                'Data' => $_REQUEST['email']
            ]);
            $NetworkUser = NetworkUser::getByID($EmailContactPoint->PersonID);
            if (
                !$EmailContactPoint ||
                !$NetworkUser ||
                !$NetworkUser->School
            ) {
                return static::throwInvalidRequestError('Unable to find the user within the network. Please contact an admininstrator for support.');
            }

            $token = JWT::encode([
                'username' => $EmailContactPoint->toString(), //$NetworkUser->PrimaryEmail->toString(),
                'domain' => 'http://vm.nafis.me:88',
                'returnUrl' => '/connectors/network-hub/login'
            ], $NetworkUser->School->APIKey);

            $queryParameters = http_build_query([
                'JWT' => $token
            ]);

            $networkSiteLoginUrl = 'http://' . $NetworkUser->School->Domain.'/network-api/login?'.$queryParameters;

            header('Location: '.$networkSiteLoginUrl);
        }
        // show network login screen
        return static::respond('network-login', [
            '_LOGIN' => [
                'return' => $returnUrl
            ]
        ]);
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
                $syncResults = static::pullNetworkUsers($Job, $NetworkHubSchool, $pretend);

                $results['created'][$NetworkHubSchool->Domain] = $syncResults['created'];
                $results['created']['total'] += $syncResults['created'];

                $results['skipped'][$NetworkHubSchool->Domain] = $syncResults['skipped'];
                $results['skipped']['total'] += $syncResults['skipped'];

                $results['updated'][$NetworkHubSchool->Domain] = $syncResults['updated'];
                $results['updated']['total'] += $syncResults['updated'];
            } catch (SyncException $e) {
                $Job->logException($e);
            }
        }

        // save job results
        $Job->Status = 'Completed';
        $Job->Results = $results;

        if (!$pretend) {
            $Job->save();
        }

        return true;
    }

    public static function pullNetworkUsers(IJob $Job, School $NetworkSchool, $pretend = true)
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
                    $syncResults = static::syncNetworkUser($NetworkSchool, $networkUser, $Job, $pretend);

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
                    $Job->logException($s);
                }
            }
        } catch (Exception $e) {
            throw new SyncException($e->getMessage(), []);
        }

        return $results;
    }

    public static function syncNetworkUser(School $NetworkSchool, array $networkUserRecord, LoggerInterface $logger = null, $pretend = true)
    {
        $logger = static::getLogger($logger);

        if (empty($networkUserRecord['PrimaryEmail']['Data'])) {
            return new SyncResult(
                SyncResult::STATUS_SKIPPED,
                'Skipped {slateUsername} @ {slateDomain} due to missing PrimaryEmail',
                [
                    'slateUsername' => $networkUserRecord['Username'],
                    'slateDomain' => $NetworkSchool->Domain
                ]
            );
        }

        $networkUserConditions = [
            'SchoolID' => $NetworkSchool->ID,
            'SchoolUsername' => $networkUserRecord['Username']
        ];

        // create a new network user if it does not exist
        if (!$NetworkUser = NetworkUser::getByWhere($networkUserConditions)) {
            $NetworkUser = NetworkUser::create([
                'FirstName' => $networkUserRecord['FirstName'],
                'LastName' => $networkUserRecord['LastName'],
                'UserClass' => $networkUserRecord['Class'],
                'AccountLevel' => $networkUserRecord['AccountLevel'],
                'StudentNumber' => $networkUserRecord['StudentNumber'],
                'SchoolUsername' => $networkUserRecord['Username'],
                'SchoolID' => $NetworkSchool->ID
            ]);

            $NetworkUser->PrimaryEmail = $NetworkUserPrimaryEmail = Email::fromString(
                $networkUserRecord['PrimaryEmail']['Data'],
                $NetworkUser
            );

            if (!$NetworkUser->validate(true)) {
                $logger->error(
                    'Unable to create network user -- invalid record {validationErrors}',
                    [
                        'networkUserRecord' => $networkUserRecord,
                        'validationErrors' => $NetworkUser->getValidationErrors()
                    ]
                );

                return new SyncResult(
                    SyncResult::STATUS_SKIPPED,
                    'Invalid Record: Unable to sync network user, skipping.',
                    [
                        'slateUsername' => $networkUserRecord['Username'],
                        'slateDomain' => $NetworkSchool->Domain,
                        'validationErrors' => $NetworkUser->getValidationErrors()
                    ]
                );
            }

            if (!$pretend) {
                $NetworkUser->save(true);
            }

            $logger->notice(
                'Created network user for {slatePrimaryEmail}',
                [
                    'slatePrimaryEmail' => $NetworkUserPrimaryEmail->Data
                ]
            );

            return new SyncResult(
                SyncResult::STATUS_CREATED,
                'Created new network user for {FirstName} {LastName} ({slatePrimaryEmail} @ {slateDomain})',
                [
                    'FirstName' => $NetworkUser->FirstName,
                    'LastName' => $NetworkUser->LastName,
                    'Username' => $NetworkUser->Username,
                    'slatePrimaryEmail' => $NetworkUserPrimaryEmail->Data,
                    'slateDomain' => $NetworkSchool->Domain
                ]
            );

        } else {
            $userChanges = new KeyedDiff();
            $emailChanges = new KeyedDiff();

            if ($NetworkUser->FirstName != $networkUserRecord['FirstName']) {
                $userChanges->addChange('FirstName', $networkUserRecord['FirstName'], $NetworkUser->FirstName);
            }

            if ($NetworkUser->LastName != $networkUserRecord['LastName']) {
                $userChanges->addChange('LastName', $networkUserRecord['LastName'], $NetworkUser->LastName);
            }

            if ($NetworkUser->PrimaryEmail->toString() != $networkUserRecord['PrimaryEmail']['Data']) {
                $emailChanges->addChange('Data', $networkUserRecord['PrimaryEmail']['Data'], $NetworkUser->PrimaryEmail->toString());
            }

            if (
                $userChanges->hasChanges() ||
                $emailChanges->hasChanges()
            ) {

                if ($userChanges->hasChanges()) {
                    $NetworkUser->setFields($userChanges->getNewValues());
                    $logger->debug(
                        'Updating Network User {schoolUsername} @ {schoolDomain}',
                        [
                            'schoolUsername' => $NetworkUser->SchoolUsername,
                            'schoolDomain' => $NetworkUser->School->Domain,
                            'changes' => $userChanges
                        ]
                    );
                }

                if ($emailChanges->hasChanges()) {
                    $NetworkUser->PrimaryEmail->setFields($emailChanges->getNewValues());

                    $logger->debug(
                        'Updating Network User {schoolUsername} @ {schoolDomain} primary email: {slatePrimaryEmail}',
                        [
                            'schoolUsername' => $NetworkUser->SchoolUsername,
                            'schoolDomain' => $NetworkUser->School->Domain,
                            'slatePrimaryEmail' => $NetworkUser->PrimaryEmail->toString(),
                            'changes' => $emailChanges
                        ]
                    );
                }

                if (!$pretend) {
                    $NetworkUser->save(true);
                }

                return new SyncResult(
                    SyncResult::STATUS_UPDATED,
                    'Updated network user {slatePrimaryEmail} @ {slateDomain}',
                    [
                        'slatePrimaryEmail' => $NetworkUser->PrimaryEmail->toString(),
                        'slateDomain' => $NetworkSchool->Domain
                    ]
                );
            }
        }

        return new SyncResult(
            SyncResult::STATUS_VERIFIED,
            'Network user {slatePrimaryEmail} @ {slateDomain} found and verified',
            [
                'slatePrimaryEmail' => $NetworkUser->PrimaryEmail->toString(),
                'slateDomain' => $NetworkSchool->Domain
            ]
        );
    }
}
