<?php
require __DIR__ . '/vendor/autoload.php';
use Illuminate\Support\Str;

$dir = __DIR__ . '/database/migrations';
$files = glob($dir . '/*.php');

$dbml = "";
$refsArray = [];

foreach ($files as $file) {
    $content = file_get_contents($file);
    
    // Match Schema::create('table_name', function (Blueprint $table) { ... });
    preg_match_all('/Schema::create\s*\(\s*\'([^\']+)\'\s*,\s*function\s*\([^\)]+\)\s*\{([\s\S]*?)\}\);/u', $content, $matches, PREG_SET_ORDER);
    
    foreach ($matches as $match) {
        $tableName = $match[1];
        $body = $match[2];
        
        $dbml .= "Table {$tableName} {\n";
        
        // Split by lines instead of tricky regex
        $lines = explode("\n", $body);
        $hasId = preg_match('/\$table->id\(\)/', $body);
        $hasTimestamps = preg_match('/\$table->timestamps\(\)/', $body);
        $hasSoftDeletes = preg_match('/\$table->softDeletes\(\)/', $body);
        $hasRememberToken = preg_match('/\$table->rememberToken\(\)/', $body);
        
        if ($hasId) {
            $dbml .= "  id integer [primary key]\n";
        }
        
        foreach ($lines as $line) {
            if (preg_match('/\$table->([a-zA-Z0-9_]+)\(\s*\'([^\']+)\'(.*)$/', $line, $col)) {
                $type = $col[1];
                $name = $col[2];
                $rest = $col[3];
                
                if (in_array($type, ['index', 'unique', 'foreign', 'dropColumn'])) continue;
                
                $dbmlType = 'varchar';
                if (strpos($type, 'integer') !== false || strpos($type, 'Id') !== false) $dbmlType = 'integer';
                if (strpos($type, 'text') !== false || strpos($type, 'string') !== false) $dbmlType = 'varchar';
                if (strpos($type, 'boolean') !== false) $dbmlType = 'boolean';
                if (strpos($type, 'timestamp') !== false || strpos($type, 'date') !== false) $dbmlType = 'timestamp';
                if (strpos($type, 'decimal') !== false || strpos($type, 'float') !== false || strpos($type, 'double') !== false) $dbmlType = 'decimal';
                if (strpos($type, 'json') !== false) $dbmlType = 'json';
                if (strpos($type, 'enum') !== false) $dbmlType = 'enum';
                
                $settings = [];
                if (strpos($rest, 'nullable(') !== false) $settings[] = 'null';
                else $settings[] = 'not null';
                if (strpos($rest, 'unique(') !== false) $settings[] = 'unique';
                
                $settingStr = !empty($settings) ? ' [' . implode(', ', $settings) . ']' : '';
                $dbml .= "  {$name} {$dbmlType}{$settingStr}\n";
                
                // Build reference table mapping
                $base = str_replace('_id', '', $name);
                $refTable = Str::plural($base);
                
                if (in_array($base, ['parent'])) $refTable = $tableName;
                elseif (in_array($base, ['admin', 'customer', 'reply_to_user', 'reviewed_by', 'created_by', 'removed_by', 'assigned_to', 'forwarded_to', 'forwarded_by', 'responded_by', 'sender'])) {
                    $refTable = 'users';
                } elseif ($base === 'order_item') {
                    if ($tableName === 'ratings') {
                        $refTable = 'product_order_items'; // rough assumption
                    } else {
                        $refTable = 'product_order_items';
                    }
                } elseif ($base === 'service_order') {
                    $refTable = 'service_orders';
                }
                
                if (preg_match('/constrained\(\s*\'([^\']+)\'\s*\)/', $rest, $cMatch)) {
                    $refsArray[] = "Ref: {$tableName}.{$name} > {$cMatch[1]}.id";
                } elseif (preg_match('/constrained\(\)/', $rest)) {
                    $refsArray[] = "Ref: {$tableName}.{$name} > {$refTable}.id";
                } elseif (strpos($type, 'Id') !== false && $type !== 'uuid') {
                    $refsArray[] = "Ref: {$tableName}.{$name} > {$refTable}.id";
                }
            }
        }
        
        // explicit foreign keys: $table->foreign('user_id')->references('id')->on('users');
        preg_match_all('/\$table->foreign\(\s*\'([^\']+)\'\s*\)->references\(\s*\'([^\']+)\'\s*\)->on\(\s*\'([^\']+)\'\s*\)/', $body, $fkMatches, PREG_SET_ORDER);
        foreach ($fkMatches as $fk) {
            $refsArray[] = "Ref: {$tableName}.{$fk[1]} > {$fk[3]}.{$fk[2]}";
        }
        
        if ($hasRememberToken) {
            $dbml .= "  remember_token varchar [null]\n";
        }
        if ($hasTimestamps) {
            $dbml .= "  created_at timestamp [null]\n";
            $dbml .= "  updated_at timestamp [null]\n";
        }
        if ($hasSoftDeletes) {
            $dbml .= "  deleted_at timestamp [null]\n";
        }
        
        $dbml .= "}\n\n";
    }
}

$uniqueRefs = array_unique($refsArray);
$refs = implode("\n", $uniqueRefs) . "\n";

file_put_contents('schema_parsed.dbml', $dbml . $refs);
echo "DBML generated to schema_parsed.dbml\n";
