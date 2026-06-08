<?php

declare(strict_types=1);

use MetaSoftDevs\TextSmsLaravelNotificationSdk\PhoneNumbers\KenyanE164Normalizer;

/**
 * Dataset-driven coverage for the Kenyan E.164 normaliser. Mirrors the
 * QPIMS-side `tests/Unit/Sms/PhoneNumberTest.php` exactly so the
 * regression set carries over verbatim.
 */
beforeEach(function (): void {
    $this->normalizer = new KenyanE164Normalizer;
});

it('normalises kenyan phone numbers to E.164 254XXXXXXXXX', function (?string $input, ?string $expected): void {
    expect($this->normalizer->normalize($input))->toBe($expected);
})->with([
    'safaricom 0712 form' => ['0712345678', '254712345678'],
    'airtel 0112 form' => ['0112345678', '254112345678'],
    '254 prefixed' => ['254712345678', '254712345678'],
    '+254 prefixed' => ['+254712345678', '254712345678'],
    'spaces and dashes' => ['+254 712-345 678', '254712345678'],
    'parentheses' => ['(0712) 345 678', '254712345678'],
    '9-digit without leading zero' => ['712345678', '254712345678'],
    'airtel 9-digit' => ['112345678', '254112345678'],

    'null input' => [null, null],
    'empty string' => ['', null],
    'all letters' => ['abcdefghij', null],
    'too short' => ['07123', null],
    'too long' => ['254712345678999', null],
    'invalid prefix (not 7 or 1)' => ['254312345678', null],
    'landline 0202' => ['0202345678', null],
]);

it('strict validator only accepts 254 + (7|1) + 8 digits', function (?string $candidate, bool $expected): void {
    expect($this->normalizer->isValid($candidate))->toBe($expected);
})->with([
    'valid safaricom' => ['254712345678', true],
    'valid airtel' => ['254112345678', true],
    'leading zero form' => ['0712345678', false],
    'plus prefix' => ['+254712345678', false],
    'too short' => ['25471234567', false],
    'too long' => ['2547123456789', false],
    'wrong country' => ['255712345678', false],
    'null' => [null, false],
]);
