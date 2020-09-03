<?php

namespace Slate\NetworkHub;

use ActiveRecord;
use Emergence\People\Person;

class SchoolUser extends ActiveRecord
{
    public static $tableName = 'slate_network_school_users';

    public static $fields = [
        'PersonID' => [
            'type' => 'integer',
            'unsigned' => true,
            'index' => true
        ],
        'SchoolID' => [
            'type' => 'integer',
            'unsigned' => true,
            'index' => true
        ],
        'Username',
        'AccountType' => [
            'type' => 'enum',
            'notnull' => true,
            'values' => [
                'Student',
                'Staff',
                'Teacher',
                'Administrator',
                'Developer'
            ]
        ]
    ];

    public static $validators = [
        'SchoolID' => [
            'validator' => 'require-relationship'
        ],
        'PersonID' => [
            'validator' => 'require-relationship'
        ]
    ];

    public static $indexes = [
        'SchoolUser' => [
            'fields' => ['SchoolID', 'PersonID'],
            'unique' => true
        ]
    ];

    public static $relationships = [
        'Person' => [
            'type' => 'one-one',
            'class' => Person::class
        ],
        'School' => [
            'type' => 'one-one',
            'class' => School::class
        ]
    ];

    public function validate($deep = true)
    {
        // call parent
        parent::validate($deep);

        // disallow 'system' username
        if ($this->isFieldDirty('Username') && strtolower($this->Username) === 'system') {
            $this->_validator->addError('Username', "Username 'system' is forbidden");
        }

        // check username uniqueness
        if ($this->isDirty && !$this->_validator->hasErrors('Username') && $this->Username) {
            $ExistingUser = static::getByWhere([
                'Username' => $this->Username,
                'SchoolID' => $this->SchoolID
            ]);

            if ($ExistingUser && ($ExistingUser->ID != $this->ID)) {
                $this->_validator->addError('Username', 'Username already registered for school.');
            }
        }

        $this->_validator->validate(array(
            'field' => 'AccountType',
            'validator' => 'selection',
            'choices' => self::$fields['AccountType']['values'],
            'required' => true
        ));

        // save results
        return $this->finishValidation();
    }
}
