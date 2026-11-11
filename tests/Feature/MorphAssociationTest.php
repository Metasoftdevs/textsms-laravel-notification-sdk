<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\PhoneNumberNormalizer;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsSender;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Gateways\LogOnlySmsGateway;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Testing\FakeSmsGateway;
use Psr\Log\LoggerInterface;

class SyntheticMorphOwner extends Model
{
    protected $table = 'synthetic_morph_owners';

    protected $guarded = [];

    public $timestamps = false;
}

class SyntheticStringMorphOwner extends SyntheticMorphOwner
{
    protected $primaryKey = 'reference';

    protected $keyType = 'string';

    public $incrementing = false;
}

beforeEach(function (): void {
    $this->previousMorphMap = Relation::morphMap();
    $this->previousRequiredMorphMap = Relation::requiresMorphMap();
    Relation::enforceMorphMap(['synthetic-owner' => SyntheticMorphOwner::class, 'synthetic-string-owner' => SyntheticStringMorphOwner::class], false);
    Schema::create('synthetic_morph_owners', function (Blueprint $table): void {
        $table->id();
        $table->string('label');
        $table->string('reference')->nullable()->unique();
    });
    Http::preventStrayRequests();
});

afterEach(function (): void {
    Relation::morphMap($this->previousMorphMap, false);
    Relation::requireMorphMap($this->previousRequiredMorphMap);
});

it('uses the native morph alias and owner key across all senders', function (string $kind, bool $bulk, ?string $reference): void {
    $owner = $reference === null
        ? SyntheticMorphOwner::create(['label' => 'Synthetic owner'])
        : SyntheticStringMorphOwner::create(['label' => 'Synthetic owner', 'reference' => $reference]);
    $sender = match ($kind) {
        'textsms' => app(SmsSender::class),
        'fake' => new FakeSmsGateway,
        'log' => new LogOnlySmsGateway(app(PhoneNumberNormalizer::class), app(LoggerInterface::class)),
    };
    Http::fake(['sms.textsms.co.ke/*' => Http::response(['responses' => [[
        'clientsmsid' => 'morph-case', 'respose-code' => 200, 'messageid' => 'synthetic-morph-id',
    ]]])]);
    $message = new SmsMessage('Synthetic association', clientReference: 'morph-case');
    $dispatch = $bulk
        ? $sender->sendBulk([['recipient' => '0700000000', 'message' => $message, 'notifiable' => $owner]])->firstOrFail()
        : $sender->send('0700000000', $message, $owner);

    expect($dispatch->fresh()->notifiable_type)->toBe($reference === null ? 'synthetic-owner' : 'synthetic-string-owner')
        ->and((string) $dispatch->fresh()->notifiable_id)->toBe((string) $owner->getKey())
        ->and(SmsDispatch::whereMorphedTo('notifiable', $owner)->count())->toBe(1)
        ->and($dispatch->fresh()->notifiable?->is($owner))->toBeTrue();
    if ($kind !== 'textsms') {
        Http::assertNothingSent();
    }
})->with(['textsms', 'fake', 'log'])->with(['single' => [false], 'bulk' => [true]])
    ->with(['integer' => [null], 'UUID' => ['d7d107ed-1010-4000-8000-000000000001'], 'ULID' => ['01J00000000000000000000001']]);
