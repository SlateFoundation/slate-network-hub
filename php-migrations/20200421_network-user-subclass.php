<?php

namespace Slate\NetworkHub;

use DB;
use SQL;

$tableName = User::$tableName;
$columnName = 'Class';

if (!static::tableExists($tableName)) {
    printf('Table `%s` does not exist, skipping.', $tableName);
    return static::STATUS_SKIPPED;
}

$columnExists = static::columnExists($tableName, $columnName);

printf('%s column `%s`.`%s`.', $columnExists ? 'Updating' : 'Adding', $tableName, $columnName);
$fieldDefinition = SQL::getFieldDefinition(User::class, $columnName, false);

DB::nonQuery(
    'ALTER TABLE `%s` %s %s',
    [
        $tableName,
        $columnExists ? 'MODIFY' : 'ADD',
        $fieldDefinition
    ]
);

return static::columnExists($tableName, $columnName) ? static::STATUS_EXECUTED : static::STATUS_FAILED;