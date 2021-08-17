<?php

namespace Slate\NetworkHub;

class User extends \Emergence\People\User
{
    public static $fields = [
        'StudentNumber',
        'SchoolUsername'
    ];

    public static $relationships = [
        'Schools' => [
            'class' => School::class,
            'type' => 'many-many',
            'linkClass' => SchoolUser::class,
            'linkLocal' => 'PersonID',
            'linkForeign' => 'SchoolID'
        ]
    ];

    public static function getFromRecord(array $networkUserRecord)
    {
        return static::getByWhere([
            'Email' => $networkUserRecord['PrimaryEmail']['Data']
        ]);
    }

    public static function createFromRecord(array $networkUserRecord)
    {
        return static::create([
            'FirstName' => $networkUserRecord['FirstName'],
            'LastName' => $networkUserRecord['LastName'],
            'Email' => $networkUserRecord['PrimaryEmail']['Data'],
            'StudentNumber' => $networkUserRecord['StudentNumber'],
            'Username' => static::getUniqueUsername($networkUserRecord['FirstName'], $networkUserRecord['LastName']),
        ]);
    }
}
