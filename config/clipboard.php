<?php

$ole = ['application/vnd.ms-office', 'application/x-ole-storage', 'application/CDFV2'];

return [

    'max_file_kb' => 10240,

    'max_files_per_day' => 10,

    /*
     * Allowed extensions => MIME types finfo may report for them. The first
     * type is the one the file is served with. OOXML files are zip archives
     * and older libmagic versions report them as such.
     */
    'file_types' => [
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
        'md' => ['text/markdown', 'text/plain', 'text/x-markdown'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'doc' => ['application/msword', ...$ole],
        'xls' => ['application/vnd.ms-excel', ...$ole],
        'ppt' => ['application/vnd.ms-powerpoint', ...$ole],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
    ],

];
