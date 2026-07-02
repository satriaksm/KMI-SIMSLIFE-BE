<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$tables = DB::select('SHOW TABLES');
$dbName = DB::connection()->getDatabaseName();
$property = "Tables_in_{$dbName}";

$dbml = "";
$refs = "";

foreach ($tables as $tableInfo) {
    $tableName = $tableInfo->$property;
    // Skip Laravel default/system tables if you want
    if (in_array($tableName, ['migrations', 'personal_access_tokens', 'failed_jobs', 'password_reset_tokens'])) continue;

    $dbml .= "Table {$tableName} {\n";
    $columns = DB::select("SHOW COLUMNS FROM {$tableName}");
    
    foreach ($columns as $column) {
        $type = $column->Type;
        $name = $column->Field;
        
        $settings = [];
        if ($column->Key === 'PRI') $settings[] = 'primary key';
        if ($column->Null === 'YES') $settings[] = 'null';
        else $settings[] = 'not null';
        
        $settingStr = !empty($settings) ? ' [' . implode(', ', $settings) . ']' : '';
        
        $dbml .= "  {$name} {$type}{$settingStr}\n";
    }
    $dbml .= "}\n\n";
    
    // get foreign keys
    $fks = DB::select("
        SELECT 
            COLUMN_NAME, 
            REFERENCED_TABLE_NAME, 
            REFERENCED_COLUMN_NAME 
        FROM 
            information_schema.KEY_COLUMN_USAGE 
        WHERE 
            TABLE_SCHEMA = ? 
            AND TABLE_NAME = ? 
            AND REFERENCED_TABLE_NAME IS NOT NULL
    ", [$dbName, $tableName]);
    
    foreach ($fks as $fk) {
        $refs .= "Ref: {$tableName}.{$fk->COLUMN_NAME} > {$fk->REFERENCED_TABLE_NAME}.{$fk->REFERENCED_COLUMN_NAME}\n";
    }
}

file_put_contents('schema.dbml', $dbml . $refs);
echo "DBML generated successfully!\n";
