<?php
$dbml = file_get_contents('schema_parsed.dbml');
$lines = explode("\n", $dbml);

$out = "erDiagram\n";

$inTable = false;
foreach ($lines as $line) {
    $line = trim($line);
    if (empty($line)) continue;
    
    if (preg_match('/^Table\s+([a-zA-Z0-9_]+)\s*\{/', $line, $matches)) {
        $out .= "    " . $matches[1] . " {\n";
        $inTable = true;
    } elseif ($inTable && $line === '}') {
        $out .= "    }\n";
        $inTable = false;
    } elseif ($inTable) {
        if (preg_match('/^([a-zA-Z0-9_]+)\s+([a-zA-Z0-9_]+)/', $line, $matches)) {
            $out .= "        " . $matches[2] . " " . $matches[1] . "\n";
        }
    } elseif (preg_match('/^Ref:\s*([a-zA-Z0-9_]+)\.([a-zA-Z0-9_]+)\s*>\s*([a-zA-Z0-9_]+)\.([a-zA-Z0-9_]+)/', $line, $matches)) {
        $out .= "    " . $matches[3] . " ||--o{ " . $matches[1] . " : \"" . $matches[4] . "-" . $matches[2] . "\"\n";
    }
}
file_put_contents('erd_full.md', $out);

