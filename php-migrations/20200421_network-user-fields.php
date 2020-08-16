<?php

namespace Slate\NetworkHub;

use DB;
use SQL;

$tableName = User::$tableName;
$historyTableName = User::getHistoryTableName();
$columnNames = [
    'SchoolID',
    'SchoolUsername',
    'UserClass'
];

if (!static::tableExists($tableName)) {
    printf('Table `%s` does not exist, skipping.', $tableName);
    return static::STATUS_SKIPPED;
}

$columnsExist = true;
foreach ($columnNames as $columnName) {
    if (static::columnExists($tableName, $columnName)) {
        printf('Column `%s`.`%s` already exists, skipping.', $tableName, $columnName);
    } else {
        $columnsExist = false;
    }
}

if ($columnsExist) {
    return static::STATUS_SKIPPED;
}

$fieldDefinitions = [];
foreach ($columnNames as $columnName) {
    $fieldDefinitions[] = SQL::getFieldDefinition(User::class, $columnName, false);
}


DB::nonQuery(
    'ALTER TABLE `%s` ADD %s',
    [
        $tableName,
        join(', ADD ', $fieldDefinitions)
    ]
);


// update history table
$fieldDefinitions = [];
foreach ($columnNames as $columnName) {
    $fieldDefinitions[] = SQL::getFieldDefinition(User::class, $columnName, true);
}

DB::nonQuery(
    'ALTER TABLE `%s` ADD %s',
    [
        $historyTableName,
        join(', ADD ', $fieldDefinitions)
    ]
);

$columnsExist = true;
foreach ($columnNames as $columnName) {
    if (
        !static::columnExists($tableName, $columnName) ||
        !static::columnExists($historyTableName, $columnName)
    ) {
        $columnsExist = false;
    }
}

return $columnsExist === false ? static::STATUS_FAILED : static::STATUS_EXECUTED;
