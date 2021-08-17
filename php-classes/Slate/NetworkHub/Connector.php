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
use Emergence\EventBus;

use Psr\Log\LoggerInterface;

class Connector extends AbstractConnector implements ISynchronize
{
    public static $title = 'Slate Network Hub';
    public static $connectorId = 'network-hub';

    public static $apiEndpoints = [
        'users' => '/network-api/users',
        'groups' => '/network-api/groups'
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
                $schoolIds = array_keys(SchoolUser::getAllByWhere([
                    'PersonID' => $NetworkUser->ID
                ], [
                    'indexField' => 'SchoolID'
                ]));
                if (!empty($schoolIds)) {
                    $NetworkSchools = School::getAllByWhere([
                        'ID' => [
                            'operator' => 'IN',
                            'values' => $schoolIds
                        ]
                    ],[
                        'indexField' => 'Handle'
                    ]);
                }
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

            EventBus::fireEvent('networkUserLogIn', static::class, [
                'School' => $NetworkSchool,
                'User' => $NetworkUser,
                'requestData' => $_REQUEST
            ]);

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
        $config['groupList'] = !empty($requestData['groups']) ? $requestData['groups'] : [];

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

                $results['skipped']['total'] += $syncResults['skipped'];
                /* Uncomment for debug */
                $results['skipped'][$NetworkHubSchool->Domain] = [
                    // 'usernames' => array_map(
                    //     function($r) {
                    //         return $r['Username'];
                    //     }, $syncResults['skippedUsers']
                    // ),
                    'usernames' => $syncResults['skippedReasons'],
                    // 'users' => $syncResults['skippedUsers']
                    'total' => $syncResults['skipped']
                ];


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

            $groupList = $Job->config['groupList'];
            $networkUsers = $NetworkSchool->fetchNetworkGroupMembers($groupList);

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
                        // $results['skippedUsers'][] = $networkUser;
                        $results['skippedReasons'][$networkUser['Username']] = $syncResults->getInterpolatedMessage();
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
        if ($reasonToSkipSync = static::networkUserShouldNotBeSynced($networkUserRecord, $NetworkSchool)) {
            return $reasonToSkipSync;
        }

        // user exists, lets update their information
        if ($NetworkUserMapping = SchoolUserMapping::getByExternalIdentifier($NetworkSchool, $networkUserRecord['ID'])) {
            $NetworkUser = $NetworkUserMapping->Context->Person;

            if (!$NetworkUser) {

                Debug::dump($NetworkUserMapping, true, 'mapping');
            } else {
                // \Debug::dump([$networkUserRecord, $NetworkUser, $NetworkUserMapping], true, 'network user/mapping');
            }

            $userChanges = static::getNetworkUserChanges($NetworkUser, $networkUserRecord, $logger);

            $SchoolUser = static::addNetworkUserToSchool($NetworkUser, $NetworkSchool, $networkUserRecord, $logger, $pretend);

            if ($userChanges->hasChanges()) {
                $logger->debug(
                    'Updating Network User {schoolEmail}',
                    [
                        'schoolEmail' => $NetworkUser->Email,
                        'changes' => $userChanges
                    ]
                );

                $NetworkUser->setFields($userChanges->getNewValues());
                if (!$pretend) {
                    $NetworkUser->save();
                }

                return new SyncResult(
                    SyncResult::STATUS_UPDATED,
                    'Updated network user {slatePrimaryEmail} @ {slateDomain}',
                    [
                        'slatePrimaryEmail' => $NetworkUser->Email,
                        'slateDomain' => $NetworkSchool->Domain
                    ]
                );
            } else {
                return new SyncResult(
                    SyncResult::STATUS_VERIFIED,
                    'Network user {slatePrimaryEmail} @ {slateDomain} found and verified',
                    [
                        'slatePrimaryEmail' => $NetworkUser->Email,
                        'slateDomain' => $NetworkSchool->Domain
                    ]
                );
            }
        } else { // user mapping does not exist, let's create a user/mapping if neccessary

            if (!$NetworkUser = User::getFromRecord($networkUserRecord)) {
                $NetworkUser = User::createFromRecord($networkUserRecord);
            }

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

            $logger->notice(
                'Created network user for {slatePrimaryEmail}',
                [
                    'slatePrimaryEmail' => $NetworkUser->Email
                ]
            );

            if (!$pretend) {
                $NetworkUser->save(true);
            }

            // add user to school if they do not exist yet
            $SchoolUser = static::addNetworkUserToSchool($NetworkUser, $NetworkSchool, $networkUserRecord, $logger, $pretend);

            $NetworkMapping = SchoolUserMapping::createByExternalIdentifier($SchoolUser, $networkUserRecord['ID'], !$pretend);

            return new SyncResult(
                SyncResult::STATUS_CREATED,
                'Created new network user for {FirstName} {LastName} ({slatePrimaryEmail} @ {slateDomain})',
                [
                    'FirstName' => $NetworkUser->FirstName,
                    'LastName' => $NetworkUser->LastName,
                    'Username' => $NetworkUser->Username,
                    'slatePrimaryEmail' => $NetworkUser->Email,
                    'slateDomain' => $NetworkSchool->Domain,
                    'Mapping' => $NetworkMapping
                ]
            );
        }
    }

    protected static function getNetworkUserChanges(User $NetworkUser, array $networkUserRecord)
    {
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

        // implement after mappings are implemented
        // if ($NetworkUser->Email != $networkUserRecord['PrimaryEmail']['Data']) {
        //     $userChanges->addChange('Email', $networkUserRecord['PrimaryEmail']['Data'], $NetworkUser->Email);
        // }

        return $userChanges;
    }

    protected static function networkUserShouldNotBeSynced(array $networkUserRecord, School $NetworkSchool)
    {
        $requiredFields = [
            'PrimaryEmail',
            'Username',
            'AccountLevel',
            'FirstName',
            'LastName',
            'ID'
        ];

        $missingRequiredField = null;
        foreach ($requiredFields as $requiredField) {
            if (empty($networkUserRecord[$requiredField])) {
                $missingRequiredField = $requiredField;
                break;
            }
        }

        if (!empty($missingRequiredField)) {
            return new SyncResult(
                SyncResult::STATUS_SKIPPED,
                'Skipped record -- missing required field: {missingRequiredField}',
                [
                    'missingRequiredField' => $missingRequiredField,
                    // 'networkUserData' => http_build_query($networkUserRecord)
                ]
            );
        }

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
    }

    protected static function getNetworkUserAccountLevel(array $networkUserData)
    {
        $accountTypeValues = SchoolUser::getFieldOptions('AccountType')['values'];

        $accountType = null;
        if ($networkUserData['Class'] === 'Slate\\People\\Student') {
            $accountType = 'Student';
        } elseif (in_array($networkUserData['AccountLevel'], $accountTypeValues)) {
            $accountType = $networkUserData['AccountLevel'];
        }

        return $accountType;
    }

    // todo: implement logger
    protected static function addNetworkUserToSchool(IUser $User, School $School, array $networkUserData = [], LoggerInterface $logger = null, $pretend = true)
    {
        $conditions = [
            'SchoolID' => $School->ID,
            'PersonID' => $User->ID,
        ];

        $SchoolUser = SchoolUser::getByWhere($conditions);

        if (!$SchoolUser) {
            $SchoolUser = SchoolUser::create($conditions);
        }

        $SchoolUser->setFields([
            'AccountType' => static::getNetworkUserAccountLevel($networkUserData),
            'Username' => $networkUserData['Username']
        ]);

        if ($SchoolUser->isPhantom || $SchoolUser->isDirty) {
            if ($pretend && $SchoolUser->PersonID == 0) { // set person id in pretend-mode to pass validations
                $SchoolUser->PersonID = 1;
            }

            if (!$SchoolUser->validate()) {
                $logger->error(
                    'Invalid Record. Unable to add user {slateEmail} to school ({schoolHandle}) {slateDomain}',
                    [
                        'slateEmail' => $networkUserData['PrimaryEmail']['Data'],
                        'slateDomain' => $School->Domain,
                        'schoolHandle' => $School->Handle
                    ]
                );

                return $SchoolUser;
            }

            if (!$SchoolUser->isPhantom) {
                $logger->notice(
                    'Updated SchoolUser {slateEmail} @ {slateDomain} ({changes})',
                    [
                        'slateEmail' => $networkUserData['PrimaryEmail']['Data'],
                        'slateDomain' => $School->Domain,
                        'changes' => http_build_query(array_intersect_key($SchoolUser->getData(), $SchoolUser->originalValues))
                    ]
                );
            }

            if (!$pretend) {
                $SchoolUser->save(false);
            }


            return $SchoolUser;
        }

        $logger->info(
            'Verified school user {slateEmail} @ {slateDomain}',
            [
                'accountLevel' => $networkUserData['AccountLevel'],
                'slateEmail' => $networkUserData['PrimaryEmail']['Data'],
                'slateDomain' => $School->Domain,
                'userClass' => $networkUserData['Class']
            ]
        );

        return $SchoolUser;
    }
}
