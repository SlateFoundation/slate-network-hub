<?php

namespace Slate\NetworkHub;

use Emergence\Connectors\Mapping;

class SchoolUserMapping extends Mapping
{
    public static function create($data = [], $save = false)
    {
        return Mapping::create($data, $save);
    }

    public static function getByExternalIdentifier(School $NetworkSchool, $Id)
    {
        $conditions = [
            'Connector' => $NetworkSchool->Handle,
            'ContextClass' => SchoolUser::getRootClass(),
            'ExternalKey' => 'user[ID]',
            'ExternalIdentifier' => $Id
        ];

        return static::getByWhere($conditions);
    }

    public static function createByExternalIdentifier(SchoolUser $SchoolUser, $Id, $autoSave = false)
    {
        $conditions = [
            'Connector' => $SchoolUser->School->Handle,
            'ContextClass' => $SchoolUser->getRootClass(),
            'ContextID' => $SchoolUser->ID,
            'ExternalKey' => 'user[ID]',
            'ExternalIdentifier' => $Id
        ];

        return static::create($conditions, $autoSave);
    }

    public function getNetworkUser()
    {
        return $this->Context;
    }
}