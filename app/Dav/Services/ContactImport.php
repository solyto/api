<?php

namespace App\Dav\Services;

use App\Api\Contacts\Services\ContactService;
use App\Api\Users\Models\User;
use App\Dav\DTOs\AddressBookDTO;
use App\Dav\DTOs\AddressBookImportDTO;
use App\Dav\Exceptions\ImportConflictException;
use App\Dav\Exceptions\ImportException;
use App\Dav\Exceptions\ImportFileException;
use App\Dav\Helpers\VCardHelper;
use App\Shared\Services\UserCacheService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Document;
use Sabre\VObject\Node;
use Sabre\VObject\Property;
use Sabre\VObject\Reader;

class ContactImport
{
    private const string CACHE_KEY_ADDRESS_BOOKS = 'address_books';

    public const int MAX_FILE_CONTACTS = 10000;
    private const int MAX_NAME_LENGTH = 255;

    private const string LOCK_KEY = 'contact-import-lock-';
    private const int LOCK_TTL = 10;
    // A running import that has saved no progress for this long has lost its worker; both imports save after every
    // contact, so only a job still waiting in the queue can be mistaken for a dead one.
    private const int STALE_AFTER = 300;
    private const string FILE_DISK = 'local';
    private const string FILE_DIRECTORY = 'contact-imports';

    private AddressBooks $addressBooks;
    private VCardHelper $helper;

    public function __construct(AddressBooks $addressBooks, private readonly UserCacheService $cache)
    {
        $this->addressBooks = $addressBooks;
        $this->helper = new VCardHelper();
    }

    public function start(User $user, string $url, string $username, #[\SensitiveParameter] string $secret): void
    {
        $dto = new AddressBookImportDTO();
        $dto->jobId = uniqid();
        $dto->userEmail = $user->email;
        $dto->stage = 'started';
        $dto->url = rtrim($url, '/') . '/';
        $dto->username = $username;
        // The state sits in the cache for the queued import; never in plaintext.
        $dto->secret = Crypt::encryptString($secret);

        $this->claim($user->id, $dto);

        try {
            $this->getAvailableAddressBooks($user, $dto);
        } catch (\Throwable $e) {
            $dto->stage = 'failed';
            $this->saveState($user->id, $dto);

            throw $e;
        }
    }

    /**
     * @throws ImportException
     * @throws ImportConflictException
     */
    public function selectAddressBooks(User $user, AddressBookImportDTO $dto, array $addressBooks): void
    {
        $lock = Cache::lock(self::LOCK_KEY . $user->id, self::LOCK_TTL);

        if (!$lock->get()) {
            throw new ImportConflictException('A contacts import is already running.');
        }

        try {
            $this->select($user, $dto, $addressBooks);
        } finally {
            $lock->release();
        }
    }

    // Runs under the import lock and re-reads the state, so a second or replayed select can't import again.
    /**
     * @throws ImportException
     * @throws ImportConflictException
     */
    private function select(User $user, AddressBookImportDTO $dto, array $addressBooks): void
    {
        $state = $this->getState($user->id);

        if ($dto->source !== 'carddav' || $state?->source !== 'carddav' || $state->jobId !== $dto->jobId) {
            throw new ImportException('Import state not found');
        }

        $dto = $state;

        if (in_array($dto->stage, ['started', 'address-books', 'contacts'], true)) {
            throw new ImportConflictException('A contacts import is already running.');
        }

        if ($dto->stage !== 'select' || $dto->addressBooks === null) {
            throw new ImportException('Import state not found');
        }

        $dto->selectedAddressBooks = [];

        foreach ($dto->addressBooks as $addressBook) {
            foreach ($addressBooks as $ab) {
                if ($ab === $addressBook['display_name']) {
                    $existingAddressBook = $this->addressBooks->getByName($user, $addressBook['display_name']);

                    if ($existingAddressBook) {
                        $i = 1;

                        while ($existingAddressBook) {
                            $tempName = $addressBook['name'] . ' (' . $i . ')';
                            $tempDisplayName = $addressBook['display_name'] . ' (' . $i . ')';
                            $existingAddressBook = $this->addressBooks->getByName($user, $tempDisplayName);
                            $i++;
                        }

                        $addressBook['name'] = $tempName;
                        $addressBook['display_name'] = $tempDisplayName;
                    }

                    $dto->selectedAddressBooks[] = $addressBook;
                }
            }
        }

        $dto->addressBooksCount = count($dto->selectedAddressBooks);
        $dto->stage = 'address-books';
        $this->saveState($user->id, $dto);
    }

    public function importAddressBooks(User $user, AddressBookImportDTO $dto): void
    {
        $dto->stage = 'address-books';
        $this->saveState($user->id, $dto);

        foreach ($dto->selectedAddressBooks as $addressBook) {
            $dto->currentAddressBook = $addressBook['display_name'];
            $this->saveState($user->id, $dto);

            try {
                unset($addressBook['url']);
                $this->addressBooks->create($user, AddressBookDTO::fromRequest($addressBook));
            } catch (\Exception $e) {
                Log::channel('dav')->warning('Contact import skipped an address book', [
                    'user_id'      => $user->id,
                    'address_book' => $addressBook['display_name'],
                    'error'        => $e->getMessage(),
                ]);
                continue;
            }

            $dto->addressBooksDone++;
            $this->saveState($user->id, $dto);
        }

        $dto->stage = 'contacts';
        $this->saveState($user->id, $dto);
    }

    public function importContacts(User $user, AddressBookImportDTO $dto): void
    {
        $dto->stage = 'contacts';
        $this->saveState($user->id, $dto);

        $doneTotal = 0;
        $skippedTotal = 0;

        foreach ($dto->selectedAddressBooks as $addressBook) {
            $a = $this->addressBooks->getByName($user, $addressBook['display_name']);

            if (!$a) {
                continue;
            }

            $dto->currentAddressBook = $addressBook['display_name'];
            $dto->contactsCount = 0;
            $dto->contactsDone = 0;
            $dto->contactsSkipped = 0;
            $this->saveState($user->id, $dto);

            $contacts = $this->getContacts($dto, $addressBook['url']);
            $dto->contactsCount = count($contacts);
            $this->saveState($user->id, $dto);
            $uids = $this->storedUids($a);

            foreach ($contacts as $index => $contact) {
                if ($this->storeCard($user, $a, $contact, $uids)) {
                    $dto->contactsDone++;
                    $doneTotal++;
                } else {
                    $dto->contactsSkipped++;
                    $skippedTotal++;
                }

                $this->saveState($user->id, $dto);

                unset($contacts[$index]);

                if (($dto->contactsDone + $dto->contactsSkipped) % 50 === 0) {
                    gc_collect_cycles();
                }
            }
        }

        $dto->contactsCount = $doneTotal + $skippedTotal;
        $dto->contactsDone = $doneTotal;
        $dto->contactsSkipped = $skippedTotal;
        $dto->stage = 'finished';
        $this->saveState($user->id, $dto);
    }

    /**
     * @throws ImportFileException
     * @throws ImportConflictException
     */
    public function startFile(User $user, UploadedFile $file, ?AddressBookDTO $target, ?string $name, ?string $color): AddressBookImportDTO
    {
        $content = (string) $file->get();
        $count = iterator_count($this->splitFile($content));

        $dto = new AddressBookImportDTO();
        $dto->jobId = (string) Str::uuid();
        $dto->userEmail = $user->email;
        $dto->source = 'file';
        $dto->stage = 'started';
        $dto->contactsCount = $count;

        if ($target) {
            $dto->targetAddressBookId = $target->id;
            $dto->currentAddressBook = $target->displayName;
        } else {
            $addressBookName = $this->uniqueName($user, $name ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'Imported');
            $dto->selectedAddressBooks = [['display_name' => $addressBookName, 'color' => $color]];
            $dto->currentAddressBook = $addressBookName;
            $dto->addressBooksCount = 1;
        }

        Storage::disk(self::FILE_DISK)->put($this->filePath($dto->jobId), $content);

        try {
            $this->claim($user->id, $dto);
        } catch (ImportConflictException $e) {
            $this->deleteFile($dto->jobId);

            throw $e;
        }

        return $dto;
    }

    // A job that waited in the queue past STALE_AFTER may find a newer import's state; the job id keeps it off.
    /**
     * @throws ImportFileException
     */
    public function importFile(string $userId, string $jobId): void
    {
        $user = User::find($userId);
        $dto = $this->getState($userId);

        if (!$user || !$dto || $dto->jobId !== $jobId || $dto->source !== 'file' || !in_array($dto->stage, ['started', 'contacts'], true)) {
            $this->deleteFile($jobId);

            return;
        }

        try {
            $content = Storage::disk(self::FILE_DISK)->get($this->filePath($jobId));
            $cards = $this->splitFile($content ?? throw new ImportFileException('This file could not be read as a vCard file.'));

            if ($dto->targetAddressBookId === null) {
                $dto->targetAddressBookId = $this->addressBooks->create($user, AddressBookDTO::fromRequest($dto->selectedAddressBooks[0]))->id;
                $dto->addressBooksDone = 1;
            }

            $addressBook = $this->addressBooks->get($user, (string) $dto->targetAddressBookId)
                ?? throw new ImportFileException('The address book to import into is not available.');

            $dto->stage = 'contacts';
            $this->saveState($userId, $dto);
            $uids = $this->storedUids($addressBook);

            foreach ($cards as $card) {
                if ($this->storeCard($user, $addressBook, $card, $uids)) {
                    $dto->contactsDone++;
                } else {
                    $dto->contactsSkipped++;
                }

                $this->saveState($userId, $dto);
            }

            $dto->stage = 'finished';
            $this->saveState($userId, $dto);
        } finally {
            $this->deleteFile($jobId);
            $this->clearCache($user);
        }
    }

    public function fail(string $userId, string $jobId): void
    {
        $this->deleteFile($jobId);
        $dto = $this->getState($userId);

        if (!$dto || $dto->jobId !== $jobId) {
            return;
        }

        $dto->stage = 'failed';
        $this->saveState($userId, $dto);

        if ($user = User::find($userId)) {
            $this->clearCache($user);
        }
    }

    public function clearCache(User $user): void
    {
        $this->cache->forget([self::CACHE_KEY_ADDRESS_BOOKS, $user->id]);

        foreach ($this->addressBooks->list($user) as $addressBook) {
            $this->cache->forgetTagged(ContactService::contactCacheTags($user->id), ContactService::contactCacheKey($user->id, $addressBook->id));
        }
    }

    public function getAvailableAddressBooks(User $user, AddressBookImportDTO $dto): void
    {
        $homeSet = $this->discoverAddressBookHome($dto);
        $books = $this->getAddressBooks($dto, $homeSet);

        if (!$books) {
            throw $this->unreachable($dto);
        }

        $dto->stage = 'select';
        $dto->addressBooks = $books;
        $dto->addressBooksCount = count($books);
        $this->saveState($user->id, $dto);
    }

    // An unreachable server or wrong credentials is user input, not an app error: log it, don't report it.
    private function unreachable(AddressBookImportDTO $dto): ImportException
    {
        Log::channel('dav')->warning('Contact import could not reach the server', [
            'host' => parse_url($dto->url, PHP_URL_HOST),
        ]);

        return new ImportException('Could not grab address books');
    }

    private function discoverAddressBookHome(AddressBookImportDTO $dto): string
    {
        $response = $this->getBasicRequest($dto)->send(
            'PROPFIND',
            $dto->url . 'principals/users/' . $dto->username . '/',
            ['body' => $this->helper->getAddressBookHomeDiscoveryXml()]
        );

        if (!$response->successful()) {
            throw $this->unreachable($dto);
        }


        $homes = $this->helper->parseAddressBookHomeFromXml($response->body(), $dto->url);
        return $homes[0]['url'] ?? throw $this->unreachable($dto);
    }

    private function getAddressBooks(AddressBookImportDTO $dto, string $homeSet): array
    {
        $response = $this->getBasicRequest($dto)->send(
            'PROPFIND',
            $homeSet,
            ['body' => VCardHelper::getAddressBooksXml()]
        );

        if (!$response->successful()) {
            return [];
        }

        return $this->helper->parseAddressBooksFromXml($response->body(), $homeSet);
    }

    private function getContacts(AddressBookImportDTO $dto, string $addressBookUrl): array
    {
        $response = $this->getBasicRequest($dto)->send(
            'REPORT',
            $addressBookUrl,
            ['body' => VCardHelper::getContactsXml()]
        );

        if (!$response->successful()) {
            return [];
        }

        return $this->helper->parseContactsFromXml($response->body());
    }

    // The cards of a .vcf file as they are written; each is read on its own, so one broken card can't take the
    // others with it. A BEGIN:VCARD right after an AGENT line is a vCard 2.1 agent card inside its owner; any other
    // BEGIN:VCARD inside a card means that card never ended. It is closed there, like one cut off at the end of
    // the file, and read on its own: imported if it parses, otherwise skipped and counted.
    // The file is walked line by line and one card is built at a time; splitting it into an array of lines took
    // about 60 times its size.
    /**
     * @return \Generator<int, string>
     *
     * @throws ImportFileException
     */
    private function splitFile(string $content): \Generator
    {
        $count = 0;
        $card = '';
        $depth = 0;
        $previous = '';
        $length = strlen($content);
        $offset = str_starts_with($content, "\xEF\xBB\xBF") ? 3 : 0;

        while ($offset <= $length) {
            $end = $offset + strcspn($content, "\r\n", $offset);
            $line = substr($content, $offset, $end - $offset);
            $offset = $end + (substr($content, $end, 2) === "\r\n" ? 2 : 1);
            $marker = strtoupper(trim($line));

            if ($marker === 'BEGIN:VCARD') {
                if ($depth > 0 && !preg_match('/^AGENT[;:]/i', $previous)) {
                    yield $this->countCard($count, $card);
                    $card = '';
                    $depth = 0;
                }

                $depth++;
            }

            if ($depth === 0) {
                continue;
            }

            $card .= $line . "\r\n";
            $previous = $line;

            if ($marker === 'END:VCARD' && --$depth === 0) {
                yield $this->countCard($count, $card);
                $card = '';
            }
        }

        if ($card !== '') {
            yield $this->countCard($count, $card);
        }

        if ($count === 0) {
            throw new ImportFileException('This file contains no contacts.');
        }
    }

    /**
     * @throws ImportFileException
     */
    private function countCard(int &$count, string $card): string
    {
        if (++$count > self::MAX_FILE_CONTACTS) {
            throw new ImportFileException(sprintf('This file contains more than %d contacts.', self::MAX_FILE_CONTACTS));
        }

        return $card;
    }

    // Checked like a CardDAV client's PUT (Sabre\CardDAV\Plugin::validateVCard), so the import stores nothing a
    // sync client could not have written: a card that doesn't parse breaks addressbook-multiget for every client.
    // A card that needs no change is stored as written: sabre's serializer escapes commas in URI values (a vCard 4
    // PHOTO data URI, URL) and does not unescape them in URL on read, so every round trip would add a backslash.
    /**
     * @param array<string, true> $uids
     */
    private function storeCard(User $user, AddressBookDTO $addressBook, string $data, array &$uids): bool
    {
        $readable = VCardHelper::isValid($data) ? $data : VCardHelper::repair($data);
        $vCard = $readable === null ? null : $this->readCard($readable);

        if (!$vCard) {
            $this->logSkippedContact($user, null, 'Unreadable vCard');

            return false;
        }

        $changed = $readable !== $data;

        try {
            // CardDAV servers must not store vCard 2.1; sabre reads a card without a known VERSION as 2.1 too.
            if (in_array($vCard->getDocumentType(), [Document::VCARD21, Document::UNKNOWN], true)) {
                $vCard->VERSION = '2.1';
                $vCard = $vCard->convert(Document::VCARD30);
                $this->dropVCard21Parameters($vCard);
                $changed = true;
            }

            foreach ($vCard->validate(Node::PROFILE_CARDDAV | Node::REPAIR) as $message) {
                if ($message['level'] >= 3) {
                    $this->logSkippedContact($user, (string) $vCard->UID, $message['message']);

                    return false;
                }

                $changed = true;
            }

            // Two cards with one UID in an address book confuse sync clients; the copy gets a UID of its own.
            if (isset($uids[(string) $vCard->UID])) {
                $vCard->UID = (string) Str::uuid();
                $changed = true;
            }

            $uid = (string) $vCard->UID;
            $card = $changed ? $vCard->serialize() : preg_replace('/\r\n|\r|\n/', "\r\n", trim($data)) . "\r\n";

            if (!$this->addressBooks->contacts()->import($addressBook, $card)) {
                return false;
            }
        } catch (\Exception $e) {
            $this->logSkippedContact($user, (string) $vCard->UID, $e->getMessage());

            return false;
        } finally {
            $vCard->destroy();
        }

        $uids[$uid] = true;

        return true;
    }

    // The converter keeps CHARSET (the values are UTF-8 once read) and ENCODING=BASE64, which vCard 3.0 spells "b".
    private function dropVCard21Parameters(VCard $vCard): void
    {
        foreach ($vCard->children() as $property) {
            if (!$property instanceof Property) {
                continue;
            }

            unset($property['CHARSET']);

            if (strtoupper((string) $property['ENCODING']) === 'BASE64') {
                $property['ENCODING'] = 'b';
            }
        }
    }

    private function readCard(string $data): ?VCard
    {
        $vCard = Reader::read($data);

        return $vCard instanceof VCard ? $vCard : null;
    }

    /** @return array<string, true> */
    private function storedUids(AddressBookDTO $addressBook): array
    {
        $uids = [];

        foreach ($this->addressBooks->contacts()->listStored($addressBook) as $contact) {
            if (preg_match('/^UID(?:;[^:\r\n]*)?:(.*?)\r?$/mi', (string) $contact->storedData, $match)) {
                $uids[$match[1]] = true;
            }
        }

        return $uids;
    }

    private function logSkippedContact(User $user, ?string $uid, string $error): void
    {
        Log::channel('dav')->warning('Contact import skipped a contact', [
            'user_id' => $user->id,
            'contact' => $uid,
            'error'   => $error,
        ]);
    }

    // The URI of a new address book is the slug of its name and must be unique too: "contacts.vcf" would
    // otherwise collide with the default address book "Contacts".
    private function uniqueName(User $user, string $name): string
    {
        $addressBooks = $this->addressBooks->list($user);
        $taken = fn (string $candidate) => array_filter(
            $addressBooks,
            fn (AddressBookDTO $addressBook) => $addressBook->displayName === $candidate || $addressBook->uri === Str::slug($candidate),
        );

        $name = mb_substr($name, 0, self::MAX_NAME_LENGTH);
        $candidate = $name;
        $i = 1;

        while ($taken($candidate)) {
            $suffix = ' (' . $i++ . ')';
            $candidate = mb_substr($name, 0, self::MAX_NAME_LENGTH - mb_strlen($suffix)) . $suffix;
        }

        return $candidate;
    }

    private function filePath(string $jobId): string
    {
        return self::FILE_DIRECTORY . '/' . $jobId . '.vcf';
    }

    private function deleteFile(string $jobId): void
    {
        Storage::disk(self::FILE_DISK)->delete($this->filePath($jobId));
    }

    private function isRunning(?AddressBookImportDTO $dto): bool
    {
        $stages = $dto?->source === 'file' ? ['started', 'contacts'] : ['address-books', 'contacts'];

        // A state saved before updatedAt existed is from before a deploy; its worker is gone.
        return in_array($dto?->stage, $stages, true)
            && $dto->updatedAt !== null && $dto->updatedAt > now()->getTimestamp() - self::STALE_AFTER;
    }

    // The lock keeps two requests from both passing the running check before either saves.
    /**
     * @throws ImportConflictException
     */
    private function claim(string $userId, AddressBookImportDTO $dto): void
    {
        $lock = Cache::lock(self::LOCK_KEY . $userId, self::LOCK_TTL);

        if (!$lock->get()) {
            throw new ImportConflictException('A contacts import is already running.');
        }

        try {
            if ($this->isRunning($this->getState($userId))) {
                throw new ImportConflictException('A contacts import is already running.');
            }

            $this->saveState($userId, $dto);
        } finally {
            $lock->release();
        }
    }

    private function getBasicRequest(AddressBookImportDTO $dto): PendingRequest
    {
        return Http::withBasicAuth($dto->username, Crypt::decryptString($dto->secret))
                   ->withHeaders([
                       'Content-Type' => 'application/xml',
                       'Depth' => '1',
                   ]);
    }

    private function saveState(string $userId, AddressBookImportDTO $dto): void
    {
        $dto->updatedAt = now()->getTimestamp();
        Cache::set('contact-import-' . $userId, $dto, now()->addMinutes(60));
    }

    // A CardDAV import started before the secret was encrypted holds it in plaintext. The secret is dropped and an
    // import that still needs it is marked failed, so it can be started again.
    public function getState(string $userId): ?AddressBookImportDTO
    {
        $dto = Cache::get('contact-import-' . $userId);

        if ($dto?->secret === null) {
            return $dto;
        }

        try {
            Crypt::decryptString($dto->secret);
        } catch (DecryptException) {
            $dto->secret = null;

            if (!in_array($dto->stage, ['finished', 'failed'], true)) {
                $dto->stage = 'failed';
            }

            $this->saveState($userId, $dto);
        }

        return $dto;
    }
}
