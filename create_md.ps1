$dbml = Get-Content -Raw "c:\laragon\www\KMI-SIMSLIFE-BE\schema_parsed.dbml"
$output = "# Database Schema (DBML)`n`nHere is the parsed DBML based on your Laravel migrations:`n`n```dbml`n$dbml`n```"
Set-Content -Path "C:\Users\Setz\.gemini\antigravity-ide\brain\1d4cc380-0035-42df-8c94-d37cd722187b\database_schema.md" -Value $output
