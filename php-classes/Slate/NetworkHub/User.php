<?php

namespace Slate\NetworkHub;

use ActiveRecord;

use Emergence\Connectors\Mapping;

class User extends \Emergence\People\User
{
    public static $fields = [
        'SchoolID' => 'uint',
        'SchoolUsername',
        'UserClass'
    ];

    public static $relationships = [
        'School' => [
            'class' => School::class,
            'type' => 'one-one'
        ]
    ];

    public static $validators = [
        'Username' => null,
        'StudentNumber' => null,
        'School' => 'require-relationship',
        'SchoolUsername' => [
            'validator' => 'handle',
            'required' => true,
            'errorMessage' => 'SchoolUsername can only contain letters, numbers, hyphens, and underscores.'
        ]
    ];
}