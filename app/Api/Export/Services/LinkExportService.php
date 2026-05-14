<?php

namespace App\Api\Export\Services;

use App\Api\Libraries\Models\LibraryLink;
use App\Api\Libraries\Models\LibraryLinkCategory;
use App\Api\Users\Models\User;

class LinkExportService
{
    public function export(User $user, string $path): void
    {
        $links = LibraryLink::forUser($user->id)->with(['tags', 'category'])->get();
        $categories = LibraryLinkCategory::forUser($user->id)->get();

        $html = '<!DOCTYPE NETSCAPE-Bookmark-file-1>'."\n"
            .'<META HTTP-EQUIV="Content-Type" CONTENT="text/html; charset=UTF-8">'."\n"
            .'<TITLE>Bookmarks</TITLE>'."\n"
            .'<H1>Bookmarks</H1>'."\n"
            .'<DL><p>'."\n";

        $grouped = $links->groupBy('category_id');

        foreach ($categories as $category) {
            $categoryLinks = $grouped->get($category->id, collect());
            if ($categoryLinks->isEmpty()) {
                continue;
            }

            $html .= '    <DT><H3>'.htmlspecialchars($category->title).'</H3>'."\n"
                .'    <DL><p>'."\n";

            foreach ($categoryLinks as $link) {
                $tags = $link->tags->pluck('name')->implode(',');
                $html .= '        <DT><A HREF="'.htmlspecialchars($link->url).'"'
                    .($tags ? ' TAGS="'.htmlspecialchars($tags).'"' : '')
                    .($link->is_favorite ? ' ICON="★"' : '')
                    .'>'.htmlspecialchars($link->title).'</A>'."\n";
            }

            $html .= '    </DL><p>'."\n";
        }

        $uncategorized = $grouped->get(null, collect());
        foreach ($uncategorized as $link) {
            $tags = $link->tags->pluck('name')->implode(',');
            $html .= '    <DT><A HREF="'.htmlspecialchars($link->url).'"'
                .($tags ? ' TAGS="'.htmlspecialchars($tags).'"' : '')
                .($link->is_favorite ? ' ICON="★"' : '')
                .'>'.htmlspecialchars($link->title).'</A>'."\n";
        }

        $html .= '</DL><p>'."\n";

        file_put_contents($path, $html);
    }
}
