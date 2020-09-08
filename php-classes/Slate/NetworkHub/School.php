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

        $queryParameters = http_build_query(array_merge([
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
}
