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
