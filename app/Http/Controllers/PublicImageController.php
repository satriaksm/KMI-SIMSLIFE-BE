<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PublicImageController extends Controller
{
    public function byPath($path)
    {
        $decodedPath = urldecode($path);
        $disk = config('filesystems.default', 'public');
        if (!Storage::disk($disk)->exists($decodedPath)) {
            abort(404);
        }
        $mime = Storage::disk($disk)->mimeType($decodedPath) ?? 'image/jpeg';
        $stream = Storage::disk($disk)->readStream($decodedPath);
        return response()->stream(function () use ($stream) {
            fpassthru($stream);
        }, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=31536000',
        ]);
    }
}
