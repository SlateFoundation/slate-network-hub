<?php

namespace Slate\NetworkHub;

use ActiveRecord;
use Emergence\Logger;

class School extends ActiveRecord
{
    public static $tableName = 'slate_network_schools';
    public static $networkApiEndpoints = [
        'users' => '/network-api/users'
    ];

    public static $fields = [
        'Domain',
        'APIKey',
        'Handle'
    ];

    public function getNetworkUsers()
    {
        if (!$this->Domain) {
            throw new \Exception('Domain must be configured to retrieve network users.');
        }

        if (!$this->APIKey) {
            throw new \Exception('APIKey must be configured to retrieve network users.');
        }

        $queryParameters = http_build_query([
            'apiKey' => $this->APIKey,
            'limit' => 0,
            'include' => 'Mapping,PrimaryEmail',
            'format' => 'json'
        ]);

        $curlUrl = $this->Domain . static::$networkApiEndpoints['users'] . '?' . $queryParameters;
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