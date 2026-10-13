<?php

namespace App\Shared\Helpers;

class CsvHelper
{
    public static function write(string $path, array $header, iterable $rows): void
    {
        $handle = fopen($path, 'w');
        fputcsv($handle, $header);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);
    }
}
