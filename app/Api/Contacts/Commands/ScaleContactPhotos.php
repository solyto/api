<?php

namespace App\Api\Contacts\Commands;

use App\Api\Contacts\Services\ContactService;
use App\Dav\Services\VCardPhotoProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ScaleContactPhotos extends Command
{
    protected $signature = 'app:contacts:scale-photos';
    protected $description = 'Scale down oversized contact photos stored in vCards';

    public function handle(VCardPhotoProcessor $processor): int
    {
        $total = DB::connection('pgsql')
            ->table('cards')
            ->where('size', '>', ContactService::PHOTO_MAX_BYTES)
            ->count();

        if ($total === 0) {
            $this->info('No oversized cards found.');
            return Command::SUCCESS;
        }

        $this->info("Processing {$total} cards...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $scaled  = 0;
        $skipped = 0;

        DB::connection('pgsql')
            ->table('cards')
            ->where('size', '>', ContactService::PHOTO_MAX_BYTES)
            ->orderBy('id')
            ->chunkById(50, function ($cards) use ($processor, $bar, &$scaled, &$skipped) {
                foreach ($cards as $card) {
                    $vCard = is_resource($card->carddata)
                        ? stream_get_contents($card->carddata)
                        : $card->carddata;

                    $processed = $processor->process($vCard);

                    if ($processed === $vCard) {
                        $skipped++;
                        $bar->advance();
                        continue;
                    }

                    DB::connection('pgsql')->table('cards')->where('id', $card->id)->update([
                        'carddata'     => $processed,
                        'size'         => strlen($processed),
                        'etag'         => md5($processed),
                        'lastmodified' => time(),
                    ]);

                    $scaled++;
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine();
        $this->info("Done. Scaled: {$scaled}, skipped: {$skipped}.");

        return Command::SUCCESS;
    }


}
