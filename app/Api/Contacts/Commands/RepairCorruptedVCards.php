<?php

namespace App\Api\Contacts\Commands;

use App\Dav\DTOs\ContactDTO;
use App\Dav\Helpers\VCardHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairCorruptedVCards extends Command
{
    protected $signature = 'app:contacts:repair-vcards {--apply : Write repairs (default is a dry run)}';
    protected $description = 'Find cards whose carddata fails to parse as valid vCard (causing 500s on addressbook-multiget) and repair them in place';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $total = DB::connection('pgsql')->table('cards')->count();
        $this->info(($apply ? 'Repairing' : '[DRY RUN] Scanning') . " {$total} cards...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $broken = 0;
        $repaired = 0;
        $unrepairable = [];

        DB::connection('pgsql')->table('cards')->orderBy('id')->chunkById(50, function ($cards) use (&$broken, &$repaired, &$unrepairable, $apply, $bar) {
            foreach ($cards as $card) {
                $vCard = is_resource($card->carddata) ? stream_get_contents($card->carddata) : $card->carddata;

                if (VCardHelper::isValid($vCard)) {
                    $bar->advance();
                    continue;
                }

                $broken++;
                $fixed = $this->repair($vCard, $card->addressbookid);

                if ($fixed === null) {
                    $unrepairable[] = ['id' => $card->id, 'addressbookid' => $card->addressbookid, 'uri' => $card->uri];
                    $bar->advance();
                    continue;
                }

                $repaired++;

                if ($apply) {
                    DB::connection('pgsql')->transaction(function () use ($card, $fixed) {
                        DB::connection('pgsql')->table('cards')->where('id', $card->id)->update([
                            'carddata'     => $fixed,
                            'size'         => strlen($fixed),
                            'etag'         => md5($fixed),
                            'lastmodified' => time(),
                        ]);

                        $synctoken = DB::connection('pgsql')->table('addressbooks')->where('id', $card->addressbookid)->value('synctoken');

                        DB::connection('pgsql')->table('addressbookchanges')->insert([
                            'uri'           => $card->uri,
                            'synctoken'     => $synctoken,
                            'addressbookid' => $card->addressbookid,
                            'operation'     => 2,
                        ]);

                        DB::connection('pgsql')->table('addressbooks')->where('id', $card->addressbookid)->increment('synctoken');
                    });
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Scanned {$total}. Broken: {$broken}. Repaired: {$repaired}." . ($apply ? '' : ' Pass --apply to write changes.'));

        if (!empty($unrepairable)) {
            $this->warn('Could not auto-repair ' . count($unrepairable) . ' card(s) — needs manual review:');
            foreach ($unrepairable as $row) {
                $this->line("  card #{$row['id']} (addressbook #{$row['addressbookid']}, uri={$row['uri']})");
            }
        }

        return Command::SUCCESS;
    }

    private function repair(string $vCard, int $addressBookId): ?string
    {
        // Strategy 1: forgiving parse + re-serialize.
        $repaired = VCardHelper::repair($vCard);
        if ($repaired !== null) {
            return $repaired;
        }

        // Strategy 2: best-effort field extraction via our own lenient parser, rebuilt through
        // ContactDTO::toVCard() (which now properly escapes text per RFC 6350). The real vCard
        // UID (not the DB row id) must be preserved so clients don't treat this as a new contact.
        try {
            $parsedFields = ContactDTO::parseVCard($vCard, true);

            $dto = new ContactDTO(
                uid: $parsedFields['uid'] ?? null,
                firstName: $parsedFields['first_name'] ?? '',
                middleName: $parsedFields['middle_name'] ?? null,
                lastName: $parsedFields['last_name'] ?? '',
                fullName: $parsedFields['full_name'] ?? '',
                email: $parsedFields['email'] ?? null,
                phone: $parsedFields['phone'] ?? null,
                organization: $parsedFields['organization'] ?? null,
                street: $parsedFields['street'] ?? null,
                city: $parsedFields['city'] ?? null,
                state: $parsedFields['state'] ?? null,
                postalCode: $parsedFields['postal_code'] ?? null,
                country: $parsedFields['country'] ?? null,
                photo: $parsedFields['photo'] ?? null,
                groups: $parsedFields['groups'] ?? null,
                prefix: $parsedFields['prefix'] ?? null,
                suffix: $parsedFields['suffix'] ?? null,
                note: $parsedFields['note'] ?? null,
                uri: null,
                etag: null,
                addressBookId: $addressBookId,
            );

            $rebuilt = VCardHelper::dropUnreadablePhoto($dto->toVCard());
            if (VCardHelper::isValid($rebuilt)) {
                return $rebuilt;
            }
        } catch (\Throwable) {
            // fall through
        }

        return null;
    }
}
