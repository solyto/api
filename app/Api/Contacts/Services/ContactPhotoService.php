<?php

namespace App\Api\Contacts\Services;

use App\Api\Contacts\Models\Contact;
use Illuminate\Support\Facades\Storage;

class ContactPhotoService
{
    public function load(string $userId, string $fileName): string | false
    {
        if (!Storage::disk('private_user_data')->exists($userId . '/' . $fileName)) {
            return false;
        }

        return Storage::disk('private_user_data')->get($userId . '/' . $fileName);
    }

    public function save(string $userId, Contact $contact, $file): string | false
    {
        if (!$this->createDir($userId)) {
            return false;
        }

        $extension = $file->getClientOriginalExtension();
        $store = Storage::disk('private_user_data')->putFileAs($userId . '/contacts', $file, $contact->id . $extension);

        return $store ?
            $userId . '/contacts' . $contact->id . $extension :
            false;
    }

    public function remove(string $userId, Contact $contact): bool
    {
        $directory = $userId . '/contacts';
        $pattern   = $directory . '/' . $contact->id . '.*';

        $files        = Storage::disk('private_user_data')->files($directory);
        $matchedFiles = array_filter($files, fn ($file) => fnmatch($pattern, $file));

        foreach ($matchedFiles as $file) {
            Storage::disk('private_user_data')->delete($file);
        }

        return true;
    }

    private function createDir(string $userId): bool
    {
        if (!Storage::disk('private_user_data')->exists($userId)) {
            if (!Storage::disk('private_user_data')->makeDirectory($userId)) {
                return false;
            }
        }

        return true;
    }
}
