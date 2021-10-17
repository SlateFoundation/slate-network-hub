<?php

namespace Slate\NetworkHub;

use ActiveRecord;
use Emergence\Logger;
use Emergence\People\Person;

class School extends ActiveRecord
{
    public static $tableName = 'slate_network_schools';

    public static $fields = [
        'Protocol' => [
            'default' => 'http://'
        ],
        'Domain',
        'APIKey',
        'Handle'
    ];

    public static $relationships = [
        'Users' => [
            'type' => 'many-many',
            'class' => Person::class,
            'linkClass' => UserSchool::class,
            'linkLocal' => 'SchoolID',
            'linkForeign' => 'PersonID'
        ]
    ];

    public function fetchNetworkUsers($params = [])
    {
        if (!$this->Domain) {
            throw new \Exception('Domain must be configured to retrieve network users.');
        }

        if (!$this->APIKey) {
            throw new \Exception('APIKey must be configured to retrieve network users.');
        }

        $queryParameters = http_build_query(array_merge_recursive([
            'apiKey' => $this->APIKey,
            'limit' => 0,
            'include' => 'PrimaryEmail',
            'format' => 'json'
        ], $params));

        $curlUrl = $this->Protocol . $this->Domain . Connector::$apiEndpoints['users'] . '?' . $queryParameters;
        $ch = curl_init($curlUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $results = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpStatus == 200) {
            $response = json_decode($results, true);
            return $response['data'];
        } else {
            Logger::general_error('Slate Network School API Error', [
                'exceptionClass' => static::class,
                'exceptionMessage' => $results,
                'exceptionCode' => $httpStatus
            ]);
            throw new \Exception("Error reading ($curlUrl) response: [$httpStatus] $response");
        }
    }

    public function fetchNetworkGroups($params = [])
    {
        if (!$this->Domain) {
            throw new \Exception('Domain must be configured to retrieve network users.');
        }

        if (!$this->APIKey) {
            throw new \Exception('APIKey must be configured to retrieve network users.');
        }

        $queryParameters = http_build_query(array_merge([
            'apiKey' => $this->APIKey,
            'limit' => 0,
            'include' => 'PrimaryEmail',
            'format' => 'json'
        ], $params));

        $curlUrl = $this->Protocol . $this->Domain . Connector::$apiEndpoints['groups'] . '?' . $queryParameters;
        $ch = curl_init($curlUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $results = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpStatus == 200) {
            $response = json_decode($results, true);
            return $response['data'];
        } else {
            Logger::general_error('Slate Network School API Error', [
                'exceptionClass' => static::class,
                'exceptionMessage' => $results,
                'exceptionCode' => $httpStatus
            ]);
            throw new \Exception("Error reading ($curlUrl) response: [$httpStatus] $response");
        }
    }


    public static $fetchDefaultGroups = ['students', 'staff'];
    public function fetchNetworkGroupMembers($groups = [])
    {
        $groupsToInclude = !empty($groups) ? $groups : static::$fetchDefaultGroups;
        $groups = $this->fetchNetworkGroups([
            'parentGroup' => 'any'
        ]);
        $users = $this->fetchNetworkUsers([
            'include' => 'groupIDs'
        ]);

        // if (!is_array($groups) || !empty($_REQUEST['debug'])) {
        //     \Debug::dump($groups, true, 'groups');
        // }
        $groupsById = [];
        foreach ($groups as $group) {
            if (
                in_array(strtolower($group['Handle']), $groupsToInclude) ||
                array_key_exists($group['ParentID'], $groupsById)
            ) {
                $groupsByHandle[$group['Handle']] = $group;
                $groupsById[$group['ID']] = $group['Handle'];
            }
        }

        $filteredUsers = [];
        foreach ($users as $user) {
            if (!empty(array_intersect($user['groupIDs'], array_keys($groupsById)))) {
                $filteredUsers[$user['ID']] = array_merge($user, [
                    'groups' => array_intersect_key($groupsById, array_flip($user['groupIDs']))
                ]);
            }
        }

        return $filteredUsers;
    }
}
