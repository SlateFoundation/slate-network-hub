<?php

namespace Slate\NetworkHub;

class User extends \Emergence\People\User
{
    public static $fields = [
        'SchoolNumber'
    ];

    public static $relationships = [
        'Schools' => [
            'class' => School::class,
            'type' => 'one-many',
            'linkClass' => SchoolUser::class,
            'linkLocal' => 'PersonID',
            'linkForeign' => 'SchoolID'
        ]
    ];
}
