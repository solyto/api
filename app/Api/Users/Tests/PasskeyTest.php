<?php

use App\Api\Users\Models\Passkey;
use App\Api\Users\Services\PasskeyService;
use App\Shared\Enums\AuthPlatformEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/**
 * Encode an integer as a single-byte CBOR value (only small values used here).
 */
function cborInt(int $value): string
{
    if ($value >= 0 && $value < 24) {
        return chr($value);
    }
    throw new \RuntimeException('cborInt only supports small values in these fixtures');
}

/**
 * Encode a negative integer as CBOR (major type 1).
 */
function cborNegInt(int $value): string
{
    return chr(0x20 + (-1 - $value));
}

/**
 * Encode a byte string as CBOR (major type 2).
 */
function cborBytes(string $bytes): string
{
    $len = strlen($bytes);
    if ($len < 24) {
        $header = chr(0x40 + $len);
    } elseif ($len < 256) {
        $header = "\x58".chr($len);
    } else {
        $header = "\x59".pack('n', $len);
    }

    return $header.$bytes;
}

/**
 * Encode a text string as CBOR (major type 3).
 */
function cborText(string $text): string
{
    $len = strlen($text);

    return chr(0x60 + $len).$text;
}

/**
 * Encode a map with the given key/value pairs (small maps only).
 */
function cborMap(array $pairs): string
{
    $encoded = chr(0xa0 + count($pairs));
    foreach ($pairs as $key => $value) {
        $encoded .= $key.$value;
    }

    return $encoded;
}

/**
 * Base64url encode/decode helpers.
 */
function b64uEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function b64uDecode(string $data): string
{
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * Build the WebAuthn clientDataJSON payload.
 */
function clientData(string $type, string $challenge, string $origin): array
{
    return [
        'type' => $type,
        'challenge' => $challenge,
        'origin' => $origin,
        'crossOrigin' => false,
    ];
}

/**
 * Build the authData block used during registration: rpIdHash + UP/AT flags
 * + signCount + aaguid + credential id + COSE public key.
 */
function registrationAuthData(string $rpId, string $credentialId, string $publicKeyCose): string
{
    return hash('sha256', $rpId, true)
        ."\x41"                                        // UP | AT
        .pack('N', 0)                                 // sign count
        .random_bytes(16)                             // aaguid
        .pack('n', strlen($credentialId))             // credential id length
        .$credentialId
        .$publicKeyCose;
}

/**
 * Build a minimal CBOR attestation object containing only the authData key.
 */
function attestationObject(string $authData): string
{
    return cborMap([
        cborText('authData') => cborBytes($authData),
    ]);
}

/**
 * Build a COSE EC2 (P-256) public key from raw X and Y coordinates.
 */
function coseEc2PublicKey(string $x, string $y): string
{
    return cborMap([
        cborInt(1)  => cborInt(2),     // kty: EC2
        cborInt(3)  => cborNegInt(-7), // alg: ES256 (-7)
        cborNegInt(-1) => cborInt(1),  // crv: P-256
        cborNegInt(-2) => cborBytes($x),
        cborNegInt(-3) => cborBytes($y),
    ]);
}

describe('PasskeyService registration', function () {
    it('generates registration options with a cached challenge', function () {
        $user = makeUser();

        $options = app(PasskeyService::class)->registrationOptions($user);

        expect($options['rp']['id'])->toBe(config('webauthn.relying_party_id'));
        expect($options['challenge'])->toBeString();
        expect($options['pubKeyCredParams'])->toBe([
            ['type' => 'public-key', 'alg' => -7],
            ['type' => 'public-key', 'alg' => -257],
        ]);
        expect(Cache::has('webauthn_reg_'.$user->id))->toBeTrue();
    });

    it('registers a passkey with a valid attestation', function () {
        $user = makeUser();
        $rpId = config('webauthn.relying_party_id');
        $origin = config('webauthn.origin');
        $service = app(PasskeyService::class);

        $options = $service->registrationOptions($user);
        $challenge = $options['challenge'];

        // Build the clientData + attestation.
        $clientDataJson = json_encode(clientData('webauthn.create', $challenge, $origin));
        $credentialId = random_bytes(16);
        $publicKeyCose = coseEc2PublicKey(random_bytes(32), random_bytes(32));
        $authData = registrationAuthData($rpId, $credentialId, $publicKeyCose);
        $attestationObject = attestationObject($authData);

        $passkey = $service->register($user, [
            'response' => [
                'clientDataJSON' => b64uEncode($clientDataJson),
                'attestationObject' => b64uEncode($attestationObject),
            ],
        ], 'My Passkey');

        expect($passkey->user_id)->toBe($user->id);
        expect($passkey->name)->toBe('My Passkey');
        expect($passkey->credential_id)->toBe(base64_encode($credentialId));
        expect($passkey->public_key)->toBe(base64_encode($publicKeyCose));
        expect(Cache::has('webauthn_reg_'.$user->id))->toBeFalse();
    });

    it('rejects a mismatched challenge', function () {
        $user = makeUser();
        $service = app(PasskeyService::class);

        $service->registrationOptions($user);

        $clientDataJson = json_encode(clientData('webauthn.create', 'wrong-challenge', config('webauthn.origin')));

        expect(fn () => $service->register($user, [
            'response' => [
                'clientDataJSON' => b64uEncode($clientDataJson),
                'attestationObject' => b64uEncode(attestationObject(registrationAuthData(config('webauthn.relying_party_id'), random_bytes(16), 'x'))),
            ],
        ], 'X'))->toThrow(\RuntimeException::class, 'Challenge mismatch');
    });

    it('rejects an expired registration', function () {
        $user = makeUser();

        expect(fn () => app(PasskeyService::class)->register($user, [
            'response' => [
                'clientDataJSON' => b64uEncode(json_encode(clientData('webauthn.create', 'x', 'x'))),
                'attestationObject' => 'e30',
            ],
        ], 'X'))->toThrow(\RuntimeException::class, 'Registration options expired or not found.');
    });
});

describe('PasskeyService authentication', function () {
    it('generates authentication options', function () {
        $options = app(PasskeyService::class)->authenticationOptions('127.0.0.1');

        expect($options['challenge'])->toBeString();
        expect($options['rpId'])->toBe(config('webauthn.relying_party_id'));
        expect(Cache::has('webauthn_auth_127.0.0.1'))->toBeTrue();
    });

    it('authenticates with a valid signature and issues a token', function () {
        $user = makeUser();
        $rpId = config('webauthn.relying_party_id');
        $origin = config('webauthn.origin');
        $service = app(PasskeyService::class);

        // Create an EC keypair and store a passkey with its public key.
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        $details = openssl_pkey_get_details($key);
        $x = $details['ec']['x'];
        $y = $details['ec']['y'];
        $publicKeyCose = coseEc2PublicKey($x, $y);
        $credentialId = random_bytes(16);

        $passkey = Passkey::create([
            'user_id' => $user->id,
            'credential_id' => base64_encode($credentialId),
            'public_key' => base64_encode($publicKeyCose),
            'sign_count' => 0,
            'name' => 'Test Passkey',
        ]);

        $options = $service->authenticationOptions('10.0.0.1');
        $challenge = $options['challenge'];

        $clientDataJson = json_encode(clientData('webauthn.get', $challenge, $origin));
        $clientDataHash = hash('sha256', $clientDataJson, true);

        $authData = hash('sha256', $rpId, true)
            ."\x01"                                  // UP only (assertion)
            .pack('N', 42);                          // sign count

        openssl_sign($authData.$clientDataHash, $signature, $key, OPENSSL_ALGO_SHA256);

        $result = $service->authenticate([
            'id' => b64uEncode($credentialId),
            'rawId' => b64uEncode($credentialId),
            'response' => [
                'clientDataJSON' => b64uEncode($clientDataJson),
                'authenticatorData' => b64uEncode($authData),
                'signature' => b64uEncode($signature),
            ],
        ], '10.0.0.1', AuthPlatformEnum::WEB);

        expect($result['user']->id)->toBe($user->id);
        expect($result['token_data']['token'])->toBeString();

        expect($passkey->fresh()->sign_count)->toBe(42);
        expect($passkey->fresh()->last_used_at)->not->toBeNull();
        expect(Cache::has('webauthn_auth_10.0.0.1'))->toBeFalse();
    });

    it('rejects an unknown credential', function () {
        $service = app(PasskeyService::class);
        $options = $service->authenticationOptions('10.0.0.2');

        $clientDataJson = json_encode(clientData('webauthn.get', $options['challenge'], config('webauthn.origin')));

        expect(fn () => $service->authenticate([
            'id' => b64uEncode(random_bytes(16)),
            'rawId' => b64uEncode(random_bytes(16)),
            'response' => [
                'clientDataJSON' => b64uEncode($clientDataJson),
                'authenticatorData' => b64uEncode(hash('sha256', config('webauthn.relying_party_id'), true)."\x01".pack('N', 1)),
                'signature' => b64uEncode(random_bytes(64)),
            ],
        ], '10.0.0.2'))->toThrow(\RuntimeException::class, 'Passkey not found.');
    });

    it('rejects an expired authentication session', function () {
        $service = app(PasskeyService::class);

        expect(fn () => $service->authenticate([
            'id' => 'x',
            'response' => [
                'clientDataJSON' => 'e30',
                'authenticatorData' => 'e30',
                'signature' => 'e30',
            ],
        ], '10.0.0.3'))->toThrow(\RuntimeException::class, 'Authentication options expired or not found.');
    });
});

describe('Passkey endpoints', function () {
    it('returns registration options for the authenticated user', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/passkeys/register-options')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['challenge', 'rp']]);
    });

    it('registers a passkey through the API', function () {
        $user = makeUser();
        $service = app(PasskeyService::class);
        $options = $service->registrationOptions($user);

        $clientDataJson = json_encode(clientData('webauthn.create', $options['challenge'], config('webauthn.origin')));
        $credentialId = random_bytes(16);
        $authData = registrationAuthData(config('webauthn.relying_party_id'), $credentialId, coseEc2PublicKey(random_bytes(32), random_bytes(32)));

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/passkeys/register', [
                'name' => 'API Passkey',
                'response' => [
                    'clientDataJSON' => b64uEncode($clientDataJson),
                    'attestationObject' => b64uEncode(attestationObject($authData)),
                ],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'API Passkey');

        expect(Passkey::where('user_id', $user->id)->count())->toBe(1);
    });

    it('lists, renames and deletes passkeys', function () {
        $user = makeUser();
        $passkey = Passkey::create([
            'user_id' => $user->id,
            'credential_id' => base64_encode(random_bytes(16)),
            'public_key' => base64_encode('pk'),
            'sign_count' => 0,
            'name' => 'Laptop',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/passkeys')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Laptop');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/auth/passkeys/'.$passkey->id, ['name' => 'New Laptop'])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'New Laptop');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/auth/passkeys/'.$passkey->id)
            ->assertStatus(200);

        expect(Passkey::count())->toBe(0);
    });

    it('forbids modifying another users passkey', function () {
        $user = makeUser();
        $other = makeUser();
        $passkey = Passkey::create([
            'user_id' => $other->id,
            'credential_id' => base64_encode(random_bytes(16)),
            'public_key' => base64_encode('pk'),
            'sign_count' => 0,
            'name' => 'Other',
        ]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/auth/passkeys/'.$passkey->id, ['name' => 'Hijack'])
            ->assertStatus(403);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/auth/passkeys/'.$passkey->id)
            ->assertStatus(403);
    });
});
