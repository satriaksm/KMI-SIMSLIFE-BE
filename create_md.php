<?php
$dbml = file_get_contents("c:\\laragon\\www\\KMI-SIMSLIFE-BE\\schema_parsed.dbml");
$md = "# Database Schema (DBML)\n\nHere is the parsed DBML based on your Laravel migrations:\n\n```dbml\n" . $dbml . "\n```";
file_put_contents("C:\\Users\\Setz\\.gemini\\antigravity-ide\\brain\\1d4cc380-0035-42df-8c94-d37cd722187b\\database_schema.md", $md);
echo "Markdown Artifact Generated Successfully!";
