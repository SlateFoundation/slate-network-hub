<?php

namespace Slate\NetworkHub;

use Site;

use Emergence\Connectors\AbstractConnector;
use Emergence\Connectors\IJob;
use Emergence\Connectors\ISynchronize;
use Emergence\Connectors\Exceptions\SyncException;
use Emergence\Connectors\SyncResult;
use Emergence\KeyedDiff;
use Emergence\People\IUser;
use Emergence\Util\Url as UrlUtil;

use Psr\Log\LoggerInterface;

class Connector extends AbstractConnector implements ISynchronize
{
    public static $title = 'Slate Network Hub';
    public static $connectorId = 'network-hub';

    public static $apiEndpoints = [
        'users' => '/network-api/users'
    ];

    public static function handleRequest($action = null)
    {
        switch ($action ? $action : $action = static::shiftPath()) {
            case 'login':
                return static::handleNetworkLoginRequest();

            default:
                return parent::handleRequest($action);
        }
    }

    public static function handleNetworkLoginRequest($returnUrl = false)
    {

        // redirect to school login
        if (!empty($_REQUEST['email'])) {
            // try to redirect user to network school's login
            $NetworkUser = User::getByField('Email', $_REQUEST['email']);
            $NetworkSchools = [];

            if ($NetworkUser) {
                $NetworkSchools = School::getAllByWhere([
                    'ID' => [
                        'operator' => 'IN',
                        'values' => [
                            array_keys(SchoolUser::getAllByWhere([
                                'PersonID' => $NetworkUser->ID
                            ], [
                                'indexField' => 'SchoolID'
                            ]))
                        ]
                    ]
                ],[
                    'indexField' => 'Handle'
                ]);
            }
            // error out if...
            if (
                !$NetworkUser || // ...user not found
                ( // ...user is not associated with any schools and is NOT an Admin
                    empty($NetworkSchools) &&
                    $NetworkUser->hasAccountLevel('Administrator')
                ) ||
                ( // ...selected school handle can not be found for some reason
                    !empty($_REQUEST['SchoolHandle']) &&
                    !array_key_exists($_REQUEST['SchoolHandle'], $NetworkSchools)
                )
            ) {
                return static::throwInvalidRequestError('Unable to find the user within the network. Please contact an admininstrator for support.');
            } elseif (count($NetworkSchools) > 1) {

                if (empty($_REQUEST['SchoolHandle'])) {
                    return static::respond('login/login', [
                        '_LOGIN' => [
                            'return' => $returnUrl,
                            'username' => $_REQUEST['email']
                        ],
                        'Schools' => $NetworkSchools
                    ]);
                }

                $NetworkSchool = $NetworkSchools[$_REQUEST['SchoolHandle']];
            } elseif (count($NetworkSchools) === 1) {
                $NetworkSchool = reset($NetworkSchools);
            }

            $queryParameters = http_build_query([
                'username' => $NetworkUser->Email,
                'redirectUrl' => UrlUtil::buildAbsolute($_REQUEST['_LOGIN']['return'])
            ]);

            $networkSiteLoginUrl = $NetworkSchool->Protocol . $NetworkSchool->Domain . '/network-api/login?' . $queryParameters;

            header('Location: ' . $networkSiteLoginUrl);
        }

        // show network login screen
        return static::respond('login/login', [
            '_LOGIN' => [
                'return' => $returnUrl
            ]
        ]);
    }


    // workflow implementations
    protected static function _getJobConfig(array $requestData)
    {
        $config = parent::_getJobConfig($requestData);

        $config['pullUsers'] = !empty($requestData['pullUsers']);
        $config['schools'] = !empty($requestData['schools']) ? $requestData['schools'] : [];

        return $config;
    }


    public static function synchronize(IJob $Job, $pretend = true)
    {
        $results = [
            'created' => [
                'total' => 0
            ],
            'skipped' => [
                'total' => 0
            ],
            'updated' => [
                'total' => 0
            ],
            'verified' => [
                'total' => 0
            ],
        ];

        foreach (School::getAll() as $networkSchool) {
            if (array_key_exists($networkSchool->Handle, $Job->Config['schools'])) {
                $NetworkHubSchools[] = $networkSchool;
            }
        }

        if (empty($NetworkHubSchools)) {
            $Job->error(
                'No network schools selected. Please ensure at least one school is selected.',
                [
                    'query_results' => $Job->Config['schools']
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

                $results['verified'][$NetworkHubSchool->Domain] = $syncResults['verified'];
                $results['verified']['total'] += $syncResults['verified'];
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
            $networkUsers = $NetworkSchool->fetchNetworkUsers();
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

        // skip slate users
        if (empty($networkUserRecord['PrimaryEmail']['Data'])) {
            return new SyncResult(
                SyncResult::STATUS_SKIPPED,
                'Skipped {slateUsername} @ {slateDomain} due to missing PrimaryEmail',
                [
                    'slateUsername' => $networkUserRecord['Username'],
                    'slateDomain' => $NetworkSchool->Domain
                ]
            );
        } elseif (empty($networkUserRecord['Username'])) {
            return new SyncResult(
                SyncResult::STATUS_SKIPPED,
                'Skipped {slateUsername} @ {slateDomain} due to missing Slate username',
                [
                    'slateUsername' => $networkUserRecord['PrimaryEmail']['Data'],
                    'slateDomain' => $NetworkSchool->Domain
                ]
            );
        } elseif (empty($networkUserRecord['AccountLevel']) || $networkUserRecord['AccountLevel'] === 'Disabled') {
            return new SyncResult(
                SyncResult::STATUS_SKIPPED,
                'Skipped {slateUsername} @ {slateDomain} due to AccountLevel = Disabled',
                [
                    'slateUsername' => $networkUserRecord['PrimaryEmail']['Data'],
                    'slateDomain' => $NetworkSchool->Domain
                ]
            );
        }

        $networkUserConditions = [
            'Email' => $networkUserRecord['PrimaryEmail']['Data']
        ];

        $NetworkUser = Person::getByWhere($networkUserConditions);

        // skip disabled hub users
        if ($NetworkUser->AccountLevel === 'Disabled') {
            return new SyncResult(
                SyncResult::STATUS_SKIPPED,
                'Skipped updating {slateUsername} @ {slateDomain} due to AccountLevel = Disabled',
                [
                    'slateUsername' => $networkUserRecord['PrimaryEmail']['Data'],
                    'slateDomain' => $NetworkSchool->Domain
                ]
            );
        }

        // create a new network user if it does not exist
        if (!$NetworkUser) {
            $NetworkUser = User::create([
                'FirstName' => $networkUserRecord['FirstName'],
                'LastName' => $networkUserRecord['LastName'],
                'Email' => $networkUserRecord['PrimaryEmail']['Data'],
                'StudentNumber' => $networkUserRecord['StudentNumber'],
            ]);

            if (!$NetworkUser->validate(true)) {
                $logger->error(
                    'Unable to create network user {slatePrimaryEmail} -- invalid record {validationErrors}',
                    [
                        'slatePrimaryEmail' => $NetworkUser->Email,
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
                    'slatePrimaryEmail' => $NetworkUser->Email
                    // UPDATED because we no longer extend SLATE
                    // $NetworkUserPrimaryEmail->Data
                ]
            );

            // add user to school if they do not exist yet
            static::addUserToSchool($NetworkUser, $NetworkSchool, $networkUserRecord);

            return new SyncResult(
                SyncResult::STATUS_CREATED,
                'Created new network user for {FirstName} {LastName} ({slatePrimaryEmail} @ {slateDomain})',
                [
                    'FirstName' => $NetworkUser->FirstName,
                    'LastName' => $NetworkUser->LastName,
                    'Username' => $NetworkUser->Username,
                    'slatePrimaryEmail' => $NetworkUser->Email,
                    'slateDomain' => $NetworkSchool->Domain
                ]
            );

        } else {
            $userChanges = new KeyedDiff();

            if ($NetworkUser->FirstName != $networkUserRecord['FirstName']) {
                $userChanges->addChange('FirstName', $networkUserRecord['FirstName'], $NetworkUser->FirstName);
            }

            if ($NetworkUser->LastName != $networkUserRecord['LastName']) {
                $userChanges->addChange('LastName', $networkUserRecord['LastName'], $NetworkUser->LastName);
            }

            if ($NetworkUser->StudentNumber != $networkUserRecord['StudentNumber']) {
                $userChanges->addChange('StudentNumber', $networkUserRecord['StudentNumber'], $NetworkUser->StudentNumber);
            }

            // todo: remove because we are using email as primary key
            // if ($NetworkUser->Email != $networkUserRecord['PrimaryEmail']['Data']) {
            //     $userChanges->addChange('Email', $networkUserRecord['PrimaryEmail']['Data'], $NetworkUser->Email);
            // }

            if (
                $userChanges->hasChanges()
            ) {

                if ($userChanges->hasChanges()) {
                    $NetworkUser->setFields($userChanges->getNewValues());
                    $logger->debug(
                        'Updating Network User {schoolEmail}',
                        [
                            'schoolEmail' => $NetworkUser->Email,
                            'changes' => $userChanges
                        ]
                    );
                }

                static::addUserToSchool($NetworkUser, $NetworkSchool, $networkUserRecord);

                if (!$pretend) {
                    $NetworkUser->save(true);
                }

                return new SyncResult(
                    SyncResult::STATUS_UPDATED,
                    'Updated network user {slatePrimaryEmail} @ {slateDomain}',
                    [
                        'slatePrimaryEmail' => $NetworkUser->Email,
                        'slateDomain' => $NetworkSchool->Domain
                    ]
                );
            }
        }

        return new SyncResult(
            SyncResult::STATUS_VERIFIED,
            'Network user {slatePrimaryEmail} @ {slateDomain} found and verified',
            [
                'slatePrimaryEmail' => $NetworkUser->Email,
                'slateDomain' => $NetworkSchool->Domain
            ]
        );
    }

    protected static function addUserToSchool(IUser $User, School $School, array $networkUserData = [])
    {
        $conditions = [
            'SchoolID' => $School->ID,
            'PersonID' => $User->ID,
        ];

        // set account type
        if (!empty($networkUserData)) {
            $accountTypeValues = SchoolUser::getFieldOptions('AccountType')['values'];

            if ($networkUserData['Class'] === 'Slate\\People\\Student') {
                $accountType = 'Student';
            } elseif (in_array($networkUserData['AccountLevel'], $accountTypeValues)) {
                $accountType = $networkUserData['AccountLevel'];
            }
        }

        $SchoolUser = SchoolUser::getByWhere($conditions);
        if (!$SchoolUser) {
            // create school-user
            $SchoolUser = SchoolUser::create($conditions);
        }

        $SchoolUser->setFields([
            'AccountType' => $accountType,
            'Username' => $networkUserData['Username']
        ]);

        $SchoolUser->save(true);
    }
}
