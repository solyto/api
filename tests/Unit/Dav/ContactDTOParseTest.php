<?php

use App\Dav\DTOs\ContactDTO;

covers(ContactDTO::class);

describe('ContactDTO::parseVCard', function () {
    it('reads properties that carry parameters', function () {
        $parsed = ContactDTO::parseVCard(implode("\r\n", [
            'BEGIN:VCARD', 'VERSION:3.0', 'FN;CHARSET=UTF-8:Jürgen Müller', 'N;CHARSET=UTF-8:Müller;Jürgen;;Dr.;',
            'ORG;CHARSET=UTF-8:Acme\, Inc.', 'NOTE;LANGUAGE=de:Kennt sich aus.', 'CATEGORIES;CHARSET=UTF-8:Kunden,Messe', 'UID:p1', 'END:VCARD',
        ])."\r\n");

        expect($parsed['full_name'])->toBe('Jürgen Müller')
            ->and($parsed['last_name'])->toBe('Müller')
            ->and($parsed['first_name'])->toBe('Jürgen')
            ->and($parsed['prefix'])->toBe('Dr.')
            ->and($parsed['organization'])->toBe('Acme, Inc.')
            ->and($parsed['note'])->toBe('Kennt sich aus.')
            ->and(json_decode($parsed['groups'], true))->toBe(['Kunden', 'Messe']);
    });

    it('reads property names in any case', function () {
        $parsed = ContactDTO::parseVCard("BEGIN:VCARD\r\nVERSION:3.0\r\nfn:Jane Doe\r\nn:Doe;Jane;;;\r\ntel;type=CELL:+1 555 0100\r\nemail:jane@example.org\r\nadr;type=HOME:;;1 Main St;Springfield;;62701;USA\r\nEND:VCARD\r\n");

        expect($parsed['full_name'])->toBe('Jane Doe')
            ->and($parsed['first_name'])->toBe('Jane')
            ->and(json_decode($parsed['phone'], true)[0]['value'])->toBe('+1 555 0100')
            ->and(json_decode($parsed['email'], true)[0]['value'])->toBe('jane@example.org')
            ->and($parsed['street'])->toBe('1 Main St');
    });

    it('does not take NICKNAME or NOTE for N', function () {
        $parsed = ContactDTO::parseVCard("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Jane Doe\r\nNICKNAME:JD\r\nNOTE:Hello\r\nN:Doe;Jane;;;\r\nEND:VCARD\r\n");

        expect($parsed['last_name'])->toBe('Doe')
            ->and($parsed['note'])->toBe('Hello');
    });
});

describe('ContactDTO::parseVCard without the photo', function () {
    $folded = fn (string $prefix) => $prefix.implode("\r\n ", str_split(str_repeat('QUJD', 400), 74));

    $cards = [
        'a plain photo' => [fn () => "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Jane Doe\r\nN:Doe;Jane;;;\r\n".$folded('PHOTO;ENCODING=b;TYPE=JPEG:')."\r\nEMAIL;TYPE=WORK:jane@example.org\r\nTEL;TYPE=CELL:+1 555 0100\r\nNOTE:After the photo\r\nUID:p1\r\nEND:VCARD\r\n", true],
        'a grouped photo' => [fn () => "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Jane Doe\r\nitem1.".$folded('PHOTO;ENCODING=b;TYPE=JPEG:')."\r\nitem2.EMAIL:jane@example.org\r\nNOTE:After the photo\r\nEND:VCARD\r\n", true],
        'a lowercase photo with LF endings' => [fn () => str_replace("\r\n", "\n", "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Jane Doe\r\n".$folded('photo;encoding=b:')."\r\nemail:jane@example.org\r\nNOTE:After the photo\r\nEND:VCARD\r\n"), true],
        'a photo URI' => [fn () => "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Jane Doe\r\nPHOTO;VALUE=URI:https://example.org/jane.jpg\r\nTEL:+1 555 0100\r\nEND:VCARD\r\n", true],
        'a photo as the last line' => [fn () => "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Jane Doe\r\nEMAIL:jane@example.org\r\n".$folded('PHOTO;ENCODING=b:'), true],
        'no photo' => [fn () => "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Jane Doe\r\nN:Doe;Jane;;;\r\nNOTE:Mentions PHOTO: in text\r\n  and a folded PHOTO:line\r\nEND:VCARD\r\n", false],
    ];

    foreach ($cards as $case => [$card, $hasPhoto]) {
        it("parses every other field the same for {$case}", function () use ($card, $hasPhoto) {
            $withPhoto = ContactDTO::parseVCard($card(), true);
            $withoutPhoto = ContactDTO::parseVCard($card(), false);

            expect($withoutPhoto['photo'])->toBeNull()
                ->and($withoutPhoto['has_photo'])->toBe($hasPhoto)
                ->and($withPhoto['has_photo'])->toBe($hasPhoto)
                ->and($withoutPhoto)->toBe(array_merge($withPhoto, ['photo' => null]));

            if ($hasPhoto) {
                expect($withPhoto['photo'])->not->toBeEmpty();
            }
        });
    }

    it('keeps the fields after a folded photo', function () use ($folded) {
        $parsed = ContactDTO::parseVCard("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Jane Doe\r\n".$folded('PHOTO;ENCODING=b:')."\r\nEMAIL;TYPE=WORK:jane@example.org\r\nTEL;TYPE=CELL:+1 555 0100\r\nNOTE:After the photo\r\nEND:VCARD\r\n", false);

        expect(json_decode($parsed['email'], true))->toBe([['type' => 'work', 'value' => 'jane@example.org']])
            ->and(json_decode($parsed['phone'], true))->toBe([['type' => 'cell', 'value' => '+1 555 0100']])
            ->and($parsed['note'])->toBe('After the photo');
    });

    it('strips a photo at the maximum size', function () {
        $card = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Jane Doe\r\nPHOTO;ENCODING=b:".implode("\r\n ", str_split(base64_encode(random_bytes(1024 * 1024)), 74))."\r\nNOTE:After the photo\r\nEND:VCARD\r\n";

        $parsed = ContactDTO::parseVCard($card, false);

        expect($parsed['has_photo'])->toBeTrue()
            ->and($parsed['note'])->toBe('After the photo');
    });
});
