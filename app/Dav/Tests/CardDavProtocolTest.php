<?php

use App\Dav\Services\DavService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function addressBookPath(\App\Api\Users\Models\User $user, string $addressBook = 'contacts', string $object = ''): string
{
    return 'addressbooks/'.$user->email.'/'.$addressBook.'/'.$object;
}

function putCard(\App\Api\Users\Models\User $user, string $object, string $vcard, array $headers = [], string $addressBook = 'contacts'): \Sabre\HTTP\Response
{
    return davRequest($user, 'PUT', addressBookPath($user, $addressBook, $object), $vcard, ['Content-Type' => 'text/vcard; charset=utf-8'] + $headers);
}

function addressBookReportHrefs(\Sabre\HTTP\Response $response): array
{
    return collect(iterator_to_array(davXpath($response)->query('//d:response/d:href')))->map->textContent->sort()->values()->all();
}

describe('CardDAV address book collections', function () {
    it('creates an address book with extended MKCOL that the app then lists', function () {
        $user = davUser();

        $response = davRequest($user, 'MKCOL', addressBookPath($user, 'work'), '<?xml version="1.0" encoding="utf-8"?>'
            .'<d:mkcol xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav"><d:set><d:prop>'
            .'<d:resourcetype><d:collection/><card:addressbook/></d:resourcetype><d:displayname>Work</d:displayname>'
            .'</d:prop></d:set></d:mkcol>');

        expect($response->getStatus())->toBe(201)
            ->and(app(DavService::class)->addressBooks()->getByName($user, 'Work'))->not->toBeNull();
    });

    it('updates the display name and description with PROPPATCH', function () {
        $user = davUser();

        $patch = davRequest($user, 'PROPPATCH', addressBookPath($user), '<?xml version="1.0" encoding="utf-8"?>'
            .'<d:propertyupdate xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav"><d:set><d:prop>'
            .'<d:displayname>Friends</d:displayname><card:addressbook-description>People I know</card:addressbook-description>'
            .'</d:prop></d:set></d:propertyupdate>');
        $xpath = davXpath(davRequest($user, 'PROPFIND', addressBookPath($user), davPropfindBody([
            'd:displayname', 'card:addressbook-description',
        ]), ['Depth' => '0']));

        expect(davXpath($patch)->evaluate('string(//d:status)'))->toContain('200')
            ->and($xpath->evaluate('string(//d:displayname)'))->toBe('Friends')
            ->and($xpath->evaluate('string(//card:addressbook-description)'))->toBe('People I know');
    });

    it('changes the ctag when a card is written', function () {
        $user = davUser();
        $ctag = fn () => davXpath(davRequest($user, 'PROPFIND', addressBookPath($user), davPropfindBody(['cs:getctag']), ['Depth' => '0']))
            ->evaluate('string(//cs:getctag)');

        $before = $ctag();
        putCard($user, 'a.vcf', davVcard('a'));

        expect($ctag())->not->toBe($before);
    });

    it('deletes an address book with its cards', function () {
        $user = davUser();
        davRequest($user, 'MKCOL', addressBookPath($user, 'old'), '<?xml version="1.0" encoding="utf-8"?>'
            .'<d:mkcol xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav"><d:set><d:prop>'
            .'<d:resourcetype><d:collection/><card:addressbook/></d:resourcetype></d:prop></d:set></d:mkcol>');
        putCard($user, 'a.vcf', davVcard('a'), [], 'old');

        expect(davRequest($user, 'DELETE', addressBookPath($user, 'old'))->getStatus())->toBe(204)
            ->and(davRequest($user, 'GET', addressBookPath($user, 'old', 'a.vcf'))->getStatus())->toBe(404);
    });
});

describe('CardDAV cards', function () {
    it('stores a card with PUT and returns it unchanged with GET', function () {
        $user = davUser();
        $vcard = davVcard('round-trip', 'Jane Doe', ['EMAIL;TYPE=INTERNET:jane@example.org']);

        $put = putCard($user, 'jane.vcf', $vcard);
        $get = davRequest($user, 'GET', addressBookPath($user, 'contacts', 'jane.vcf'));

        expect($put->getStatus())->toBe(201)
            ->and($get->getStatus())->toBe(200)
            ->and($get->getBodyAsString())->toBe($vcard)
            ->and($get->getHeader('Content-Type'))->toStartWith('text/vcard')
            ->and($get->getHeader('ETag'))->toBe($put->getHeader('ETag'));
    });

    it('converts a vCard 3.0 to 4.0 when the client asks for it', function () {
        $user = davUser();
        putCard($user, 'jane.vcf', davVcard('v3'));

        $get = davRequest($user, 'GET', addressBookPath($user, 'contacts', 'jane.vcf'), null, ['Accept' => 'text/vcard; version=4.0']);

        expect($get->getBodyAsString())->toContain('VERSION:4.0');
    });

    it('stores a photo sent over DAV as it is', function () {
        $user = davUser();
        $photo = 'PHOTO;ENCODING=b;TYPE=JPEG:'.base64_encode('fake-image-bytes');

        putCard($user, 'photo.vcf', davVcard('photo', 'Jane Doe', [$photo]));

        expect(davRequest($user, 'GET', addressBookPath($user, 'contacts', 'photo.vcf'))->getBodyAsString())->toContain($photo);
    });

    it('updates only when If-Match carries the current ETag', function () {
        $user = davUser();
        $etag = putCard($user, 'a.vcf', davVcard('a'))->getHeader('ETag');

        expect(putCard($user, 'a.vcf', davVcard('a', 'Stale'), ['If-Match' => '"stale"'])->getStatus())->toBe(412)
            ->and(putCard($user, 'a.vcf', davVcard('a', 'Fresh'), ['If-Match' => $etag])->getStatus())->toBe(204)
            ->and(davRequest($user, 'GET', addressBookPath($user, 'contacts', 'a.vcf'))->getBodyAsString())->toContain('FN:Fresh');
    });

    it('refuses to overwrite an existing card when If-None-Match is *', function () {
        $user = davUser();
        putCard($user, 'a.vcf', davVcard('a'));

        expect(putCard($user, 'a.vcf', davVcard('a'), ['If-None-Match' => '*'])->getStatus())->toBe(412);
    });

    it('rejects data that is not a vCard', function (string $body) {
        $user = davUser();

        expect(putCard($user, 'bad.vcf', $body)->getStatus())->toBe(415);
    })->with([
        'garbage' => 'not a card',
        'an iCalendar' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//EN\r\nEND:VCALENDAR\r\n",
    ]);

    it('repairs a card without a UID and leaves out the ETag', function () {
        $user = davUser();
        $vcard = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:No Uid\r\nN:Uid;No;;;\r\nEND:VCARD\r\n";

        $put = putCard($user, 'no-uid.vcf', $vcard);

        expect($put->getStatus())->toBe(201)
            ->and($put->getHeader('ETag'))->toBeNull()
            ->and(davRequest($user, 'GET', addressBookPath($user, 'contacts', 'no-uid.vcf'))->getBodyAsString())->toContain('UID:');
    });

    it('deletes a card', function () {
        $user = davUser();
        putCard($user, 'a.vcf', davVcard('a'));
        $path = addressBookPath($user, 'contacts', 'a.vcf');

        expect(davRequest($user, 'DELETE', $path)->getStatus())->toBe(204)
            ->and(davRequest($user, 'GET', $path)->getStatus())->toBe(404);
    });
});

describe('CardDAV reports', function () {
    it('filters addressbook-query by a text match', function () {
        $user = davUser();
        putCard($user, 'jane.vcf', davVcard('jane', 'Jane Doe'));
        putCard($user, 'john.vcf', davVcard('john', 'John Smith'));

        $response = davRequest($user, 'REPORT', addressBookPath($user), '<?xml version="1.0" encoding="utf-8"?>'
            .'<card:addressbook-query xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">'
            .'<d:prop><d:getetag/></d:prop><card:filter><card:prop-filter name="FN">'
            .'<card:text-match collation="i;unicode-casemap" match-type="contains">smith</card:text-match>'
            .'</card:prop-filter></card:filter></card:addressbook-query>', ['Depth' => '1']);

        expect($response->getStatus())->toBe(207)
            ->and(addressBookReportHrefs($response))->toBe(['/'.addressBookPath($user, 'contacts', 'john.vcf')]);
    });

    it('returns the requested cards from addressbook-multiget', function () {
        $user = davUser();
        putCard($user, 'jane.vcf', davVcard('jane', 'Jane Doe'));
        $href = '/'.addressBookPath($user, 'contacts', 'jane.vcf');

        $response = davRequest($user, 'REPORT', addressBookPath($user), '<?xml version="1.0" encoding="utf-8"?>'
            .'<card:addressbook-multiget xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">'
            ."<d:prop><d:getetag/><card:address-data/></d:prop><d:href>{$href}</d:href>"
            .'</card:addressbook-multiget>', ['Depth' => '1']);

        expect(davXpath($response)->evaluate("string(//d:response[d:href='{$href}']//card:address-data)"))->toContain('FN:Jane Doe');
    });

    it('syncs changes and deletions with sync-collection', function () {
        $user = davUser();
        putCard($user, 'kept.vcf', davVcard('kept'));
        putCard($user, 'removed.vcf', davVcard('removed'));
        $body = fn (string $token) => '<?xml version="1.0" encoding="utf-8"?><d:sync-collection xmlns:d="DAV:">'
            ."<d:sync-token>{$token}</d:sync-token><d:sync-level>1</d:sync-level><d:prop><d:getetag/></d:prop></d:sync-collection>";

        $initial = davXpath(davRequest($user, 'REPORT', addressBookPath($user), $body('')));
        $token = $initial->evaluate('string(/d:multistatus/d:sync-token)');

        putCard($user, 'added.vcf', davVcard('added'));
        davRequest($user, 'DELETE', addressBookPath($user, 'contacts', 'removed.vcf'));

        $delta = davXpath(davRequest($user, 'REPORT', addressBookPath($user), $body($token)));
        $base = '/'.addressBookPath($user);

        expect($initial->evaluate('count(//d:response)'))->toBe(2.0)
            ->and($delta->evaluate("count(//d:response[d:href='{$base}added.vcf']/d:propstat)"))->toBe(1.0)
            ->and($delta->evaluate("string(//d:response[d:href='{$base}removed.vcf']/d:status)"))->toContain('404')
            ->and($delta->evaluate("count(//d:response[d:href='{$base}kept.vcf'])"))->toBe(0.0);
    });
});
