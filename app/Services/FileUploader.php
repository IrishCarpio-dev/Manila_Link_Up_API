<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class FileUploader
{
    /**
     * Upload a file and return the public URL.
     *
     * @param UploadedFile $file The file from $request->file()
     * @param string $folder The subfolder (e.g., 'profiles', 'covers')
     * @return string The full public URL
     */
    public static function upload(UploadedFile $file, string $folder): string
    {
        $path = $file->store($folder, 'public');
        return ltrim(Storage::url($path), '/');
    }
}