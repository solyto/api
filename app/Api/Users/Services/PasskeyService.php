<?php

namespace App\Api\Users\Services;

use App\Api\Users\Models\Passkey;
use App\Api\Users\Models\User;
use App\Shared\Enums\AuthPlatformEnum;
use Illuminate\Support\Facades\Cache;

class PasskeyService
{
    public function __construct(private readonly AuthService $authService) {}

    public function registrationOptions(User $user): array
    {
        $challenge = random_bytes(32);
        $challengeBase64Url = rtrim(strtr(base64_encode($challenge), '+/', '-_'), '=');
        $userIdBase64Url = rtrim(strtr(base64_encode($user->id), '+/', '-_'), '=');

        $excludeCredentials = $user->passkeys->map(fn ($p) => [
            'type' => 'public-key',
            'id'   => rtrim(strtr(base64_encode(base64_decode($p->credential_id)), '+/', '-_'), '='),
        ])->values()->toArray();

        Cache::put('webauthn_reg_' . $user->id, $challengeBase64Url, 60);

        return [
            'rp' => [
                'name' => config('webauthn.relying_party_name'),
                'id'   => config('webauthn.relying_party_id'),
            ],
            'user' => [
                'id'          => $userIdBase64Url,
                'name'        => $user->email,
                'displayName' => $user->name ?: $user->email,
            ],
            'challenge'              => $challengeBase64Url,
            'pubKeyCredParams'       => [
                ['type' => 'public-key', 'alg' => -7],
                ['type' => 'public-key', 'alg' => -257],
            ],
            'authenticatorSelection' => [
                'residentKey'      => 'preferred',
                'userVerification' => 'preferred',
            ],
            'attestation'        => 'none',
            'timeout'            => 60000,
            'excludeCredentials' => $excludeCredentials,
        ];
    }

    public function register(User $user, array $response, string $name): Passkey
    {
        $storedChallenge = Cache::get('webauthn_reg_' . $user->id);
        if (!$storedChallenge) {
            throw new \RuntimeException('Registration options expired or not found.');
        }

        $rpId = config('webauthn.relying_party_id');
        $origin = config('webauthn.origin');

        $clientDataJson = base64_decode(strtr($response['response']['clientDataJSON'], '-_', '+/'));
        $clientData = json_decode($clientDataJson, true);

        if (($clientData['type'] ?? '') !== 'webauthn.create') {
            throw new \RuntimeException('Invalid client data type.');
        }

        $decodedStored   = base64_decode(strtr($storedChallenge, '-_', '+/'));
        $decodedReceived = base64_decode(strtr($clientData['challenge'], '-_', '+/'));
        if (!hash_equals($decodedStored, $decodedReceived)) {
            throw new \RuntimeException('Challenge mismatch.');
        }

        $expectedOrigin = rtrim($origin, '/');
        $receivedOrigin = rtrim($clientData['origin'] ?? '', '/');
        if ($receivedOrigin !== $expectedOrigin) {
            throw new \RuntimeException('Origin mismatch: ' . $receivedOrigin . ' !== ' . $expectedOrigin);
        }

        $attestationObjectBytes = base64_decode(strtr($response['response']['attestationObject'], '-_', '+/'));
        $authData = $this->extractAuthDataFromAttestationObject($attestationObjectBytes);

        $rpIdHash = substr($authData, 0, 32);
        if (!hash_equals(hash('sha256', $rpId, true), $rpIdHash)) {
            throw new \RuntimeException('RP ID hash mismatch.');
        }

        $flags = ord($authData[32]);
        if (($flags & 0x01) === 0) {
            throw new \RuntimeException('User presence flag not set.');
        }
        if (($flags & 0x40) === 0) {
            throw new \RuntimeException('No attested credential data.');
        }

        $signCount = unpack('N', substr($authData, 33, 4))[1];

        $aaguidBytes = substr($authData, 37, 16);
        $aaguidHex = bin2hex($aaguidBytes);
        $aaguid = sprintf('%s-%s-%s-%s-%s',
            substr($aaguidHex, 0, 8), substr($aaguidHex, 8, 4), substr($aaguidHex, 12, 4),
            substr($aaguidHex, 16, 4), substr($aaguidHex, 20, 12)
        );

        $credIdLen = unpack('n', substr($authData, 53, 2))[1];
        $credentialId = base64_encode(substr($authData, 55, $credIdLen));
        $publicKey = base64_encode(substr($authData, 55 + $credIdLen));

        Cache::forget('webauthn_reg_' . $user->id);

        return Passkey::create([
            'user_id'       => $user->id,
            'credential_id' => $credentialId,
            'public_key'    => $publicKey,
            'sign_count'    => $signCount,
            'transports'    => $response['response']['transports'] ?? null,
            'aaguid'        => $aaguid,
            'name'          => $name,
        ]);
    }

    public function authenticationOptions(string $ip): array
    {
        $challenge = random_bytes(32);
        $challengeBase64Url = rtrim(strtr(base64_encode($challenge), '+/', '-_'), '=');

        Cache::put('webauthn_auth_' . $ip, $challengeBase64Url, 60);

        return [
            'challenge'        => $challengeBase64Url,
            'rpId'             => config('webauthn.relying_party_id'),
            'allowCredentials' => [],
            'userVerification' => 'preferred',
            'timeout'          => 60000,
        ];
    }

    public function authenticate(array $response, string $ip, AuthPlatformEnum $platform = AuthPlatformEnum::WEB): array
    {
        $storedChallenge = Cache::get('webauthn_auth_' . $ip);
        if (!$storedChallenge) {
            throw new \RuntimeException('Authentication options expired or not found.');
        }

        $rpId = config('webauthn.relying_party_id');
        $origin = config('webauthn.origin');

        $clientDataJson = base64_decode(strtr($response['response']['clientDataJSON'], '-_', '+/'));
        $clientData = json_decode($clientDataJson, true);

        if (($clientData['type'] ?? '') !== 'webauthn.get') {
            throw new \RuntimeException('Invalid client data type.');
        }

        $decodedStored   = base64_decode(strtr($storedChallenge, '-_', '+/'));
        $decodedReceived = base64_decode(strtr($clientData['challenge'], '-_', '+/'));
        if (!hash_equals($decodedStored, $decodedReceived)) {
            throw new \RuntimeException('Challenge mismatch.');
        }

        $expectedOrigin = rtrim($origin, '/');
        $receivedOrigin = rtrim($clientData['origin'] ?? '', '/');
        if ($receivedOrigin !== $expectedOrigin) {
            throw new \RuntimeException('Origin mismatch.');
        }

        $rawId = $response['rawId'] ?? $response['id'];
        $credentialId = base64_encode(base64_decode(strtr($rawId, '-_', '+/')));

        $passkey = Passkey::where('credential_id', $credentialId)->first();
        if (!$passkey) {
            throw new \RuntimeException('Passkey not found.');
        }

        $authDataBytes = base64_decode(strtr($response['response']['authenticatorData'], '-_', '+/'));

        if (!hash_equals(hash('sha256', $rpId, true), substr($authDataBytes, 0, 32))) {
            throw new \RuntimeException('RP ID hash mismatch.');
        }

        if ((ord($authDataBytes[32]) & 0x01) === 0) {
            throw new \RuntimeException('User presence flag not set.');
        }

        $signCount = unpack('N', substr($authDataBytes, 33, 4))[1];

        $clientDataHash = hash('sha256', $clientDataJson, true);
        $signature = base64_decode(strtr($response['response']['signature'], '-_', '+/'));

        if (!$this->verifySignature($authDataBytes . $clientDataHash, $signature, base64_decode($passkey->public_key))) {
            throw new \RuntimeException('Signature verification failed.');
        }

        if ($passkey->sign_count > 0 && $signCount <= $passkey->sign_count) {
            throw new \RuntimeException('Sign count indicates a possibly cloned authenticator.');
        }

        $passkey->update(['sign_count' => $signCount, 'last_used_at' => now()]);
        Cache::forget('webauthn_auth_' . $ip);

        $user = $passkey->user->load(['profile', 'settings']);
        $tokenData = $this->authService->createToken($user, $platform);

        return ['user' => $user, 'token_data' => $tokenData];
    }

    private function extractAuthDataFromAttestationObject(string $attestationObjectBytes): string
    {
        $offset = 0;
        $byte = ord($attestationObjectBytes[$offset++]);
        $majorType = ($byte >> 5) & 0x07;
        $additionalInfo = $byte & 0x1f;

        if ($majorType !== 5) {
            throw new \RuntimeException('Invalid attestation object: expected CBOR map');
        }

        $mapSize = $this->cborDecodeLength($attestationObjectBytes, $offset, $additionalInfo);

        for ($i = 0; $i < $mapSize; $i++) {
            $keyByte = ord($attestationObjectBytes[$offset++]);
            $keyMajor = ($keyByte >> 5) & 0x07;
            $keyAdditional = $keyByte & 0x1f;

            if ($keyMajor !== 3) break;

            $keyLen = $this->cborDecodeLength($attestationObjectBytes, $offset, $keyAdditional);
            $key = substr($attestationObjectBytes, $offset, $keyLen);
            $offset += $keyLen;

            $valByte = ord($attestationObjectBytes[$offset]);

            if ($key === 'authData') {
                $offset++;
                $valMajor = ($valByte >> 5) & 0x07;
                $valAdditional = $valByte & 0x1f;

                if ($valMajor !== 2) {
                    throw new \RuntimeException('authData is not a byte string');
                }

                $authDataLen = $this->cborDecodeLength($attestationObjectBytes, $offset, $valAdditional);
                return substr($attestationObjectBytes, $offset, $authDataLen);
            } else {
                $offset = $this->cborSkipValue($attestationObjectBytes, $offset);
            }
        }

        throw new \RuntimeException('authData not found in attestation object');
    }

    private function verifySignature(string $data, string $signature, string $publicKeyBytes): bool
    {
        $coseKey = $this->decodeCborCoseKey($publicKeyBytes);
        $kty = $coseKey[1] ?? null;

        if ($kty === 2) {
            $x = $coseKey[-2] ?? null;
            $y = $coseKey[-3] ?? null;
            if ($x === null || $y === null) {
                throw new \RuntimeException('Invalid EC public key.');
            }
            $pKey = openssl_pkey_get_public($this->buildEcPublicKeyDer("\x04" . $x . $y));
            if ($pKey === false) throw new \RuntimeException('Failed to load EC public key.');
            return openssl_verify($data, $signature, $pKey, OPENSSL_ALGO_SHA256) === 1;
        }

        if ($kty === 3) {
            $n = $coseKey[-1] ?? null;
            $e = $coseKey[-2] ?? null;
            if ($n === null || $e === null) {
                throw new \RuntimeException('Invalid RSA public key.');
            }
            $pKey = openssl_pkey_get_public($this->buildRsaPublicKeyDer($n, $e));
            if ($pKey === false) throw new \RuntimeException('Failed to load RSA public key.');
            return openssl_verify($data, $signature, $pKey, OPENSSL_ALGO_SHA256) === 1;
        }

        throw new \RuntimeException('Unsupported key type: ' . $kty);
    }

    private function decodeCborCoseKey(string $bytes): array
    {
        $offset = 0;
        $byte = ord($bytes[$offset++]);
        $majorType = ($byte >> 5) & 0x07;
        $additionalInfo = $byte & 0x1f;

        if ($majorType !== 5) {
            throw new \RuntimeException('COSE key is not a CBOR map.');
        }

        $mapSize = $this->cborDecodeLength($bytes, $offset, $additionalInfo);
        $result = [];
        for ($i = 0; $i < $mapSize; $i++) {
            $key = $this->cborReadValue($bytes, $offset);
            $result[$key] = $this->cborReadValue($bytes, $offset);
        }

        return $result;
    }

    private function cborReadValue(string $data, int &$offset): mixed
    {
        $byte = ord($data[$offset++]);
        $majorType = ($byte >> 5) & 0x07;
        $additionalInfo = $byte & 0x1f;

        switch ($majorType) {
            case 0:
                if ($additionalInfo < 24) return $additionalInfo;
                if ($additionalInfo === 24) return ord($data[$offset++]);
                if ($additionalInfo === 25) { $v = unpack('n', substr($data, $offset, 2))[1]; $offset += 2; return $v; }
                if ($additionalInfo === 26) { $v = unpack('N', substr($data, $offset, 4))[1]; $offset += 4; return $v; }
                break;
            case 1:
                if ($additionalInfo < 24) return -1 - $additionalInfo;
                if ($additionalInfo === 24) { $v = ord($data[$offset++]); return -1 - $v; }
                if ($additionalInfo === 25) { $v = unpack('n', substr($data, $offset, 2))[1]; $offset += 2; return -1 - $v; }
                break;
            case 2:
                $len = $this->cborDecodeLength($data, $offset, $additionalInfo);
                $bytes = substr($data, $offset, $len);
                $offset += $len;
                return $bytes;
            case 3:
                $len = $this->cborDecodeLength($data, $offset, $additionalInfo);
                $str = substr($data, $offset, $len);
                $offset += $len;
                return $str;
            case 5:
                $count = $this->cborDecodeLength($data, $offset, $additionalInfo);
                $map = [];
                for ($i = 0; $i < $count; $i++) {
                    $k = $this->cborReadValue($data, $offset);
                    $map[$k] = $this->cborReadValue($data, $offset);
                }
                return $map;
            case 7:
                if ($additionalInfo === 20) return false;
                if ($additionalInfo === 21) return true;
                if ($additionalInfo === 22) return null;
                break;
        }

        throw new \RuntimeException('Unsupported CBOR type: major=' . $majorType . ', additional=' . $additionalInfo);
    }

    private function cborDecodeLength(string $data, int &$offset, int $additionalInfo): int
    {
        if ($additionalInfo < 24) return $additionalInfo;
        if ($additionalInfo === 24) return ord($data[$offset++]);
        if ($additionalInfo === 25) { $len = unpack('n', substr($data, $offset, 2))[1]; $offset += 2; return $len; }
        if ($additionalInfo === 26) { $len = unpack('N', substr($data, $offset, 4))[1]; $offset += 4; return $len; }
        throw new \RuntimeException('Unsupported CBOR length encoding: ' . $additionalInfo);
    }

    private function cborSkipValue(string $data, int $offset): int
    {
        $byte = ord($data[$offset++]);
        $majorType = ($byte >> 5) & 0x07;
        $additionalInfo = $byte & 0x1f;

        switch ($majorType) {
            case 0: case 1:
                if ($additionalInfo >= 24 && $additionalInfo <= 27) $offset += 2 ** ($additionalInfo - 24);
                break;
            case 2: case 3:
                $len = $this->cborDecodeLength($data, $offset, $additionalInfo);
                $offset += $len;
                break;
            case 4:
                $count = $this->cborDecodeLength($data, $offset, $additionalInfo);
                for ($i = 0; $i < $count; $i++) $offset = $this->cborSkipValue($data, $offset);
                break;
            case 5:
                $count = $this->cborDecodeLength($data, $offset, $additionalInfo);
                for ($i = 0; $i < $count * 2; $i++) $offset = $this->cborSkipValue($data, $offset);
                break;
            case 7:
                if ($additionalInfo === 24) $offset++;
                elseif ($additionalInfo === 25) $offset += 2;
                elseif ($additionalInfo === 26) $offset += 4;
                elseif ($additionalInfo === 27) $offset += 8;
                break;
        }

        return $offset;
    }

    private function buildEcPublicKeyDer(string $uncompressedPoint): string
    {
        $oid   = "\x2a\x86\x48\xce\x3d\x03\x01\x07";
        $ecOid = "\x2a\x86\x48\xce\x3d\x02\x01";

        $algorithmIdentifier = "\x30" . $this->derLength(strlen($ecOid) + 2 + strlen($oid) + 2)
            . "\x06" . $this->derLength(strlen($ecOid)) . $ecOid
            . "\x06" . $this->derLength(strlen($oid)) . $oid;

        $bitString = "\x03" . $this->derLength(strlen($uncompressedPoint) + 1) . "\x00" . $uncompressedPoint;

        $subjectPublicKeyInfo = "\x30" . $this->derLength(strlen($algorithmIdentifier) + strlen($bitString))
            . $algorithmIdentifier . $bitString;

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private function buildRsaPublicKeyDer(string $n, string $e): string
    {
        if (ord($n[0]) & 0x80) $n = "\x00" . $n;
        if (ord($e[0]) & 0x80) $e = "\x00" . $e;

        $modulus  = "\x02" . $this->derLength(strlen($n)) . $n;
        $exponent = "\x02" . $this->derLength(strlen($e)) . $e;
        $rsaKey   = "\x30" . $this->derLength(strlen($modulus) + strlen($exponent)) . $modulus . $exponent;

        $oid                 = "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01";
        $algorithmIdentifier = "\x30" . $this->derLength(strlen($oid) + 2 + 2)
            . "\x06" . $this->derLength(strlen($oid)) . $oid
            . "\x05\x00";

        $bitString            = "\x03" . $this->derLength(strlen($rsaKey) + 1) . "\x00" . $rsaKey;
        $subjectPublicKeyInfo = "\x30" . $this->derLength(strlen($algorithmIdentifier) + strlen($bitString))
            . $algorithmIdentifier . $bitString;

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private function derLength(int $length): string
    {
        if ($length < 128) return chr($length);
        if ($length < 256) return "\x81" . chr($length);
        return "\x82" . chr($length >> 8) . chr($length & 0xff);
    }
}
