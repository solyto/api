<?php

use App\Api\Users\Models\User;
use App\Dav\DTOs\ContactDTO;
use App\Dav\DTOs\EventDTO;
use App\Dav\Services\DavService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function davFixture(string $name): string
{
    return file_get_contents(base_path('tests/Fixtures/dav/'.$name));
}

function storeEventFixture(User $user, string $name): EventDTO
{
    davRequest($user, 'PUT', 'calendars/'.$user->email.'/my-calendar/'.$name, davFixture($name), ['Content-Type' => 'text/calendar; charset=utf-8']);
    $calendar = app(DavService::class)->calendars()->list($user)[0];

    return app(DavService::class)->calendars()->events()->get($calendar, $name);
}

function storeContactFixture(User $user, string $name): ContactDTO
{
    davRequest($user, 'PUT', 'addressbooks/'.$user->email.'/contacts/'.$name, davFixture($name), ['Content-Type' => 'text/vcard; charset=utf-8']);
    $addressBook = app(DavService::class)->addressBooks()->list($user)[0];

    return app(DavService::class)->addressBooks()->contacts()->get($addressBook, $name);
}

dataset('event fixtures', ['apple-event.ics', 'google-recurring.ics', 'outlook-windows-timezone.ics', 'thunderbird-all-day.ics']);
dataset('contact fixtures', ['apple-contact.vcf', 'google-contact.vcf']);

describe('Client fixtures over DAV', function () {
    it('stores a client event and serves it back byte for byte', function (string $name) {
        $user = davUser();
        $path = 'calendars/'.$user->email.'/my-calendar/'.$name;

        $put = davRequest($user, 'PUT', $path, davFixture($name), ['Content-Type' => 'text/calendar; charset=utf-8']);

        expect($put->getStatus())->toBe(201)
            ->and($put->getHeader('ETag'))->not->toBeNull()
            ->and(davRequest($user, 'GET', $path)->getBodyAsString())->toBe(davFixture($name));
    })->with('event fixtures');

    it('stores a client vCard 3.0 and serves it back byte for byte', function (string $name) {
        $user = davUser();
        $path = 'addressbooks/'.$user->email.'/contacts/'.$name;

        $put = davRequest($user, 'PUT', $path, davFixture($name), ['Content-Type' => 'text/vcard; charset=utf-8']);

        expect($put->getStatus())->toBe(201)
            ->and($put->getHeader('ETag'))->not->toBeNull()
            ->and(davRequest($user, 'GET', $path)->getBodyAsString())->toBe(davFixture($name));
    })->with('contact fixtures');

    it('stores a client vCard 4.0 and serves it back byte for byte when 4.0 is requested', function () {
        $user = davUser();
        $path = 'addressbooks/'.$user->email.'/contacts/davx5-contact-v4.vcf';

        $put = davRequest($user, 'PUT', $path, davFixture('davx5-contact-v4.vcf'), ['Content-Type' => 'text/vcard; charset=utf-8']);
        $get = davRequest($user, 'GET', $path, null, ['Accept' => 'text/vcard; version=4.0']);

        expect($put->getStatus())->toBe(201)
            ->and($get->getBodyAsString())->toBe(davFixture('davx5-contact-v4.vcf'));
    });

    it('serves a vCard 4.0 as 3.0 to a client that does not ask for 4.0', function () {
        $user = davUser();
        $path = 'addressbooks/'.$user->email.'/contacts/davx5-contact-v4.vcf';
        davRequest($user, 'PUT', $path, davFixture('davx5-contact-v4.vcf'), ['Content-Type' => 'text/vcard; charset=utf-8']);

        expect(davRequest($user, 'GET', $path)->getBodyAsString())->toContain('VERSION:3.0');
    });

    it('returns client events unchanged in calendar-multiget', function (string $name) {
        $user = davUser();
        $href = '/calendars/'.$user->email.'/my-calendar/'.$name;
        davRequest($user, 'PUT', $href, davFixture($name), ['Content-Type' => 'text/calendar; charset=utf-8']);

        $response = davRequest($user, 'REPORT', 'calendars/'.$user->email.'/my-calendar/', '<?xml version="1.0"?>'
            .'<cal:calendar-multiget xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">'
            ."<d:prop><cal:calendar-data/></d:prop><d:href>{$href}</d:href></cal:calendar-multiget>", ['Depth' => '1']);

        // XML parsing normalises the CRLF line endings inside calendar-data.
        expect(davXpath($response)->evaluate('string(//cal:calendar-data)'))->toBe(str_replace("\r\n", "\n", davFixture($name)));
    })->with('event fixtures');
});

describe('Reading client events into the app', function () {
    it('reads the time and zone of an Apple event', function () {
        $event = storeEventFixture(davUser(), 'apple-event.ics');

        expect($event->title)->toBe('Coffee with Anna')
            ->and($event->timezone)->toBe('Europe/Berlin')
            ->and($event->startDate->format('Y-m-d H:i T'))->toBe('2030-10-15 10:00 CEST')
            ->and($event->endDate->format('Y-m-d H:i'))->toBe('2030-10-15 11:00')
            ->and($event->isAllDay)->toBeFalse();
    });

    it('reads an escaped Apple location as plain text', function () {
        $event = storeEventFixture(davUser(), 'apple-event.ics');

        expect($event->location)->toBe('Alexanderplatz 1, 10178 Berlin, Germany');
    });

    it('does not take the text of a reminder as the description', function () {
        $event = storeEventFixture(davUser(), 'apple-event.ics');

        expect($event->description)->toBeNull();
    });

    it('reads the series, not a moved occurrence, when reading a recurring event as a whole', function () {
        $event = storeEventFixture(davUser(), 'google-recurring.ics');

        expect($event->title)->toBe('Weekly standup')
            ->and($event->startDate->format('Y-m-d H:i'))->toBe('2030-10-07 09:00')
            ->and($event->recurrenceRule)->toBe('FREQ=WEEKLY;BYDAY=MO;UNTIL=20301111T140000Z');
    });

    it('reads a Thunderbird all-day event with Unicode in the title', function () {
        $event = storeEventFixture(davUser(), 'thunderbird-all-day.ics');

        expect($event->title)->toBe('Urlaub in Österreich 🏔️')
            ->and($event->isAllDay)->toBeTrue()
            ->and($event->startDate->format('Y-m-d'))->toBe('2030-10-20');
    });

    it('reads an Outlook event with a Windows time zone', function () {
        $event = storeEventFixture(davUser(), 'outlook-windows-timezone.ics');

        expect($event->startDate->format('Y-m-d H:i'))->toBe('2030-10-16 14:00')
            ->and($event->startDate->getOffset())->toBe(7200);
    });

    it('reads an Outlook title and location that carry parameters', function () {
        $event = storeEventFixture(davUser(), 'outlook-windows-timezone.ics');

        expect($event->title)->toBe('Quartalsplanung Q4')
            ->and($event->location)->toBe('Konferenzraum Süd');
    });

    it('reads a folded, escaped Outlook description in full', function () {
        $event = storeEventFixture(davUser(), 'outlook-windows-timezone.ics');

        expect($event->description)->toBe("Agenda:\n1. Budget\n2. Hiring\n\nDocuments are in \\\\fileserver\\share\\q4. Please read them before the meeting and bring your questions, ideas and concerns.");
    });

    it('expands a Google recurring event with an exception and a moved occurrence', function () {
        $user = davUser();
        storeEventFixture($user, 'google-recurring.ics');
        $calendar = app(DavService::class)->calendars()->list($user)[0];

        $events = app(DavService::class)->calendars()->events()->listExpanded(
            $calendar,
            new \DateTime('2030-10-01 00:00:00', new \DateTimeZone('UTC')),
            new \DateTime('2030-10-31 23:59:59', new \DateTimeZone('UTC')),
        );

        expect(collect($events)->map(fn (EventDTO $e) => [$e->title, $e->startDate->format('Y-m-d H:i')])->all())->toBe([
            ['Weekly standup', '2030-10-07 09:00'],
            ['Weekly standup (moved)', '2030-10-22 10:00'],
            ['Weekly standup', '2030-10-28 09:00'],
        ]);
    });

    it('keeps the local time of a recurring event across a DST change', function () {
        $user = davUser();
        storeEventFixture($user, 'google-recurring.ics');
        $calendar = app(DavService::class)->calendars()->list($user)[0];

        $events = app(DavService::class)->calendars()->events()->listExpanded(
            $calendar,
            new \DateTime('2030-10-27 00:00:00', new \DateTimeZone('UTC')),
            new \DateTime('2030-11-12 00:00:00', new \DateTimeZone('UTC')),
        );

        expect(collect($events)->map(fn (EventDTO $e) => $e->startDate->format('Y-m-d H:i P'))->all())->toBe([
            '2030-10-28 09:00 -04:00',
            '2030-11-04 09:00 -05:00',
            '2030-11-11 09:00 -05:00',
        ]);
    });
});

describe('Reading client contacts into the app', function () {
    it('reads the names, email, phone and note of an Apple contact', function () {
        $contact = storeContactFixture(davUser(), 'apple-contact.vcf');

        expect($contact->fullName)->toBe('Dr. Jürgen Müller')
            ->and($contact->lastName)->toBe('Müller')
            ->and($contact->firstName)->toBe('Jürgen')
            ->and($contact->prefix)->toBe('Dr.')
            ->and(json_decode($contact->email, true)[0]['value'])->toBe('juergen@example.de')
            ->and(json_decode($contact->phone, true)[0]['value'])->toBe('+49 170 1234567')
            ->and($contact->note)->toBe("Met at the trade fair, booth 12.\nFollow up in November.");
    });

    it('reads the photo of an Apple contact', function () {
        $contact = storeContactFixture(davUser(), 'apple-contact.vcf');

        expect($contact->photo)->toStartWith('PHOTO;ENCODING=b;TYPE=JPEG:');
    });

    it('reads the grouped address of an Apple contact', function () {
        $contact = storeContactFixture(davUser(), 'apple-contact.vcf');

        expect($contact->street)->toBe('Hauptstraße 5')
            ->and($contact->city)->toBe('München')
            ->and($contact->postalCode)->toBe('80331')
            ->and($contact->country)->toBe('Deutschland');
    });

    it('reads both emails, the address and the groups of a Google contact', function () {
        $contact = storeContactFixture(davUser(), 'google-contact.vcf');

        expect(collect(json_decode($contact->email, true))->pluck('value')->all())->toBe(['sam@example.com', 'sam.oneil@work.example.com'])
            ->and($contact->street)->toBe('1 Main St')
            ->and($contact->city)->toBe('Springfield')
            ->and($contact->organization)->toBe('Initech')
            ->and(json_decode($contact->groups, true))->toBe(['myContacts', 'Friends']);
    });

    it('reads the names and email of a DAVx5 vCard 4.0', function () {
        $contact = storeContactFixture(davUser(), 'davx5-contact-v4.vcf');

        expect($contact->fullName)->toBe('Ana María García')
            ->and($contact->firstName)->toBe('Ana María')
            ->and(json_decode($contact->email, true)[0]['value'])->toBe('ana@example.es');
    });

    it('reads every phone number of a DAVx5 vCard 4.0', function () {
        $contact = storeContactFixture(davUser(), 'davx5-contact-v4.vcf');

        expect(collect(json_decode($contact->phone ?? '[]', true))->pluck('value')->all())->toBe(['tel:+34-600-123-456', '+34 911 234 567']);
    });

    it('reads a folded note of a DAVx5 vCard 4.0 in full', function () {
        $contact = storeContactFixture(davUser(), 'davx5-contact-v4.vcf');

        expect($contact->note)->toBe('Prefers WhatsApp over phone calls. Speaks Spanish, English and some German. Ask about the marathon in April.');
    });
});
