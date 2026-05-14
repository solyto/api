<?php

namespace App\Api\Export\Services;

use App\Api\Notes\Models\Note;
use App\Api\Notes\Models\NoteCategory;
use App\Api\Users\Models\User;

class NoteExportService
{
    public function export(User $user, string $tmpPath): void
    {
        $notes = Note::forUser($user->id)->with(['category', 'tags'])->get();
        $allCategories = NoteCategory::forUser($user->id)->get()->keyBy('id');

        mkdir($tmpPath, 0755, true);

        $categoryPaths = [];
        $usedNames = [];

        foreach ($notes as $note) {
            $categoryDir = $note->category_id
                ? $this->resolveCategoryPath($note->category_id, $allCategories, $categoryPaths)
                : null;

            if ($categoryDir) {
                $fullDir = $tmpPath.'/'.$categoryDir;
                if (! is_dir($fullDir)) {
                    mkdir($fullDir, 0755, true);
                }
            }

            $baseName = $this->sanitizeFilename($note->title);
            $filename = $this->uniqueFilename($usedNames, $baseName, $categoryDir).'.md';
            $path = $categoryDir
                ? $tmpPath.'/'.$categoryDir.'/'.$filename
                : $tmpPath.'/'.$filename;

            $content = $note->content;
            if ($note->tags->isNotEmpty()) {
                $content = 'Tags: '.$note->tags->pluck('name')->implode(', ')."\n\n".$content;
            }

            file_put_contents($path, $content);
        }
    }

    private function resolveCategoryPath(int $categoryId, $allCategories, array &$cache): string
    {
        if (isset($cache[$categoryId])) {
            return $cache[$categoryId];
        }

        $segments = [];
        $id = $categoryId;

        while ($id && $allCategories->has($id)) {
            $cat = $allCategories[$id];
            array_unshift($segments, $this->sanitizeFilename($cat->title));
            $id = $cat->parent_id;
        }

        return $cache[$categoryId] = implode('/', $segments);
    }

    private function sanitizeFilename(string $name): string
    {
        $name = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $name);

        return trim($name, '. ');
    }

    private function uniqueFilename(array &$usedNames, string $baseName, ?string $categoryDir): string
    {
        $key = ($categoryDir ?? '__root__').'/'.$baseName;
        if (! isset($usedNames[$key])) {
            $usedNames[$key] = 0;

            return $baseName;
        }

        $usedNames[$key]++;

        return $baseName.' ('.$usedNames[$key].')';
    }
}
