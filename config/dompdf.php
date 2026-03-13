<?php

return [

    'show_warnings' => false,

    /*
     * Fix for shared hosting (e.g. Hostinger) where the Laravel public
     * directory may be symlinked or relocated to public_html/backend.
     *
     * public_path() resolves to laravel_app/public which does NOT exist on
     * the server → realpath() returns false → "Cannot resolve public path".
     *
     * Strategy: use APP_PUBLIC_PATH env var if set, otherwise try
     * public_path(), and fall back to base_path() which always exists.
     * The PDF template uses only inline CSS (no external file references)
     * so the exact base path value does not affect PDF output.
     */
    'public_path' => env('APP_PUBLIC_PATH')
        ?: (realpath(base_path('public')) ?: base_path()),

    'convert_entities' => true,

    'options' => [
        'font_dir' => storage_path('fonts'),
        'font_cache' => storage_path('fonts'),
        'temp_dir' => sys_get_temp_dir(),
        'chroot' => realpath(base_path()),
        'allowed_protocols' => [
            'data://' => ['rules' => []],
            'file://' => ['rules' => []],
            'http://' => ['rules' => []],
            'https://' => ['rules' => []],
        ],
        'artifactPathValidation' => null,
        'log_output_file' => null,
        'enable_font_subsetting' => false,
        'pdf_backend' => 'CPDF',
        'default_media_type' => 'screen',
        'default_paper_size' => 'a4',
        'default_paper_orientation' => 'portrait',
        'default_font' => 'serif',
        'dpi' => 96,
        'enable_php' => false,
        'enable_javascript' => true,
        'enable_remote' => false,
        'allowed_remote_hosts' => null,
        'font_height_ratio' => 1.1,
        'enable_html5_parser' => true,
    ],

];
