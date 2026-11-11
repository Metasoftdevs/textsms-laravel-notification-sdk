<?php

declare(strict_types=1);

use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\PhoneNumberNormalizer;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\PhoneNumbers\ChainedNormalizer;

it('returns the first normalization match without trying later regions', function (): void {
    $first = Mockery::mock(PhoneNumberNormalizer::class);
    $later = Mockery::mock(PhoneNumberNormalizer::class);
    $first->shouldReceive('normalize')->once()->with('synthetic raw')->andReturn('254700000000');
    $later->shouldNotReceive('normalize');

    expect((new ChainedNormalizer([$first, $later]))->normalize('synthetic raw'))->toBe('254700000000');
});

it('falls through a nonmatching region to a later match', function (): void {
    $first = Mockery::mock(PhoneNumberNormalizer::class);
    $later = Mockery::mock(PhoneNumberNormalizer::class);
    $first->shouldReceive('normalize')->once()->with('synthetic raw')->andReturnNull();
    $later->shouldReceive('normalize')->once()->with('synthetic raw')->andReturn('256700000000');

    expect((new ChainedNormalizer([$first, $later]))->normalize('synthetic raw'))->toBe('256700000000');
});

it('returns null when every region declines the input including null', function (?string $raw): void {
    $first = Mockery::mock(PhoneNumberNormalizer::class);
    $later = Mockery::mock(PhoneNumberNormalizer::class);
    $first->shouldReceive('normalize')->once()->with($raw)->andReturnNull();
    $later->shouldReceive('normalize')->once()->with($raw)->andReturnNull();

    expect((new ChainedNormalizer([$first, $later]))->normalize($raw))->toBeNull();
})->with(['invalid', null]);

it('validates with any matching region without renormalizing or visiting later regions', function (): void {
    $first = Mockery::mock(PhoneNumberNormalizer::class);
    $match = Mockery::mock(PhoneNumberNormalizer::class);
    $later = Mockery::mock(PhoneNumberNormalizer::class);
    $first->shouldReceive('isValid')->once()->with('256700000000')->andReturnFalse();
    $match->shouldReceive('isValid')->once()->with('256700000000')->andReturnTrue();
    $later->shouldNotReceive('isValid');
    foreach ([$first, $match, $later] as $normalizer) {
        $normalizer->shouldNotReceive('normalize');
    }

    expect((new ChainedNormalizer([$first, $match, $later]))->isValid('256700000000'))->toBeTrue();
});

it('declines normalization and validation when no regions are configured', function (): void {
    $chain = new ChainedNormalizer([]);
    expect($chain->normalize('synthetic'))->toBeNull()
        ->and($chain->normalize(null))->toBeNull()
        ->and($chain->isValid('254700000000'))->toBeFalse()
        ->and($chain->isValid(null))->toBeFalse();
});

it('rejects validation when all configured regions reject', function (): void {
    $normalizer = Mockery::mock(PhoneNumberNormalizer::class);
    $normalizer->shouldReceive('isValid')->once()->with(null)->andReturnFalse();
    expect((new ChainedNormalizer([$normalizer]))->isValid(null))->toBeFalse();
});
