<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Invoice files are stored encrypted with the application key, so a copy of the storage
 * folder doesn't reveal them.
 */
class EncryptedFiles
{
    public static function store(UploadedFile $file, string $directory): string
    {
        $path = trim($directory, '/').'/'.Str::uuid()->toString().'.enc';

        Storage::disk('local')->put($path, Crypt::encryptString((string) $file->get()));

        return $path;
    }

    /**
     * The original file contents (files stored before encryption are returned as they are).
     */
    public static function get(string $path): string
    {
        $contents = (string) Storage::disk('local')->get($path);

        return str_ends_with($path, '.enc') ? Crypt::decryptString($contents) : $contents;
    }
}
