<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        $this->move('user_data', 'private_user_data');
    }

    public function down(): void
    {
        $this->move('private_user_data', 'user_data');
    }

    private function move(string $from, string $to): void
    {
        $source = Storage::disk($from);
        $target = Storage::disk($to);

        DB::table('clipboards')->where('type', 'image')->whereNotNull('file_path')->pluck('file_path')
            ->each(function (string $path) use ($source, $target) {
                if (!$source->exists($path)) {
                    return;
                }

                $stream = $source->readStream($path);

                if (!$stream) {
                    return;
                }

                $written = $target->writeStream($path, $stream);
                fclose($stream);

                if ($written) {
                    $source->delete($path);
                }
            });
    }
};
