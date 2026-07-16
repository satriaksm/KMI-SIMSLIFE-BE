<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class ImageOptimizationService
{
    /**
     * Mengoptimasi dan menyimpan gambar ke dalam 3 resolusi (thumb, medium, large).
     *
     * @param UploadedFile $file File yang diupload
     * @param string $directory Direktori tujuan, misalnya 'profile_pictures'
     * @param string $disk Disk tujuan, default 'public'
     * @param bool $isSquare Apakah gambar harus dicrop rasio 1:1 (square) untuk medium dan original
     * @return string Path relatif untuk disimpan ke database (misal: 'profile_pictures/12345.webp')
     */
    public function processAndStore(UploadedFile $file, string $directory, string $disk = 'public', bool $isSquare = false): string
    {
        $manager = new ImageManager(new Driver());
        
        $filenameWithoutExt = Str::random(40);
        $baseFilename = $filenameWithoutExt . '.webp';
        
        $directory = rtrim($directory, '/');
        
        $originalPath = $directory . '/' . $baseFilename;
        $thumbPath = $directory . '/' . $filenameWithoutExt . '_thumb.webp';
        $mediumPath = $directory . '/' . $filenameWithoutExt . '_medium.webp';

        // Baca file gambar
        $image = $manager->read($file->getRealPath());

        // 1. Thumbnail
        $thumbImage = clone $image;
        if ($isSquare) {
            $thumbImage->cover(150, 150);
        } else {
            $thumbImage->scaleDown(width: 300);
        }
        $thumbData = (string) $thumbImage->toWebp(80);
        Storage::disk($disk)->put($thumbPath, $thumbData);

        // 2. Medium
        $mediumImage = clone $image;
        if ($isSquare) {
            $mediumImage->cover(600, 600);
        } else {
            $mediumImage->scaleDown(width: 600);
        }
        $mediumData = (string) $mediumImage->toWebp(80);
        Storage::disk($disk)->put($mediumPath, $mediumData);

        // 3. Large/Original
        $largeImage = clone $image;
        if ($isSquare) {
            $largeImage->cover(1920, 1920);
        } else {
            $largeImage->scaleDown(width: 1920);
        }
        $largeData = (string) $largeImage->toWebp(85);
        Storage::disk($disk)->put($originalPath, $largeData);

        return $originalPath;
    }

    /**
     * Menghapus gambar beserta versi resolusinya dari storage.
     *
     * @param string|null $basePath Path asli yang disimpan di database
     * @param string $disk Disk storage (default: 'public')
     */
    public function deleteImages(?string $basePath, string $disk = 'public'): void
    {
        if (!$basePath) {
            return;
        }

        $thumbPath = preg_replace('/\.([a-zA-Z0-9]+)$/', '_thumb.$1', $basePath);
        $mediumPath = preg_replace('/\.([a-zA-Z0-9]+)$/', '_medium.$1', $basePath);

        Storage::disk($disk)->delete([$basePath, $thumbPath, $mediumPath]);
    }
    /**
     * Resolve path based on size query
     *
     * @param string|null $path Original path
     * @param string $size 'thumb', 'medium', or 'original'
     * @return string|null
     */
    public function resolveSizePath(?string $path, string $size = 'original'): ?string
    {
        if (empty($path) || $size === 'original') {
            return $path;
        }

        if ($size === 'thumb') {
            return preg_replace('/\.([a-zA-Z0-9]+)$/', '_thumb.$1', $path);
        } elseif ($size === 'medium') {
            return preg_replace('/\.([a-zA-Z0-9]+)$/', '_medium.$1', $path);
        }

        return $path;
    }
}