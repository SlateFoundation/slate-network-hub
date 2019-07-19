<?php

namespace Slate\Network;

use ActiveRecord;

use Emergence\Connectors\Mapping;

class User extends ActiveRecord
{
    public static $tableName = 'slate_network_users';

    public static $fields = [
        'Email',
        'FirstName',
        'LastName',
        'SchoolID' => 'uint'
    ];

    public static $relationships = [
        'School' => [
            'class' => School::class,
            'type' => 'one-one'
        ],
        'Mapping' => [
            'type' => 'context-children',
            'class' => Mapping::class
        ]
    ];
}