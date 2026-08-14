<?php

use App\Shared\Helpers\DockerSecretHelper;

covers(DockerSecretHelper::class);

beforeEach(function () {
    $this->previous = [
        'APP_DEBUG' => getenv('APP_DEBUG'),
        'SECRET' => getenv('SECRET'),
        'SECRET_FILE' => getenv('SECRET_FILE'),
    ];
});

afterEach(function () {
    foreach ($this->previous as $key => $value) {
        if ($value === false) {
            putenv($key);
        } else {
            putenv("$key=$value");
        }
    }
});

describe('DockerSecretHelper::get', function () {
    it('returns the plain env value when APP_DEBUG is true', function () {
        putenv('APP_DEBUG=true');
        putenv('SECRET=plain-value');

        expect(DockerSecretHelper::get('SECRET'))->toBe('plain-value');
    });

    it('reads the value from the _FILE env path when not in debug mode', function () {
        putenv('APP_DEBUG=false');
        $file = tempnam(sys_get_temp_dir(), 'secret');
        file_put_contents($file, "  file-secret-value\n");
        putenv('SECRET_FILE='.$file);

        try {
            expect(DockerSecretHelper::get('SECRET'))->toBe('file-secret-value');
        } finally {
            unlink($file);
        }
    });

    it('returns null when not in debug mode and no _FILE env is set', function () {
        putenv('APP_DEBUG=false');
        putenv('SECRET_FILE');

        expect(DockerSecretHelper::get('SECRET'))->toBeNull();
    });

    it('returns null when the file path env is empty', function () {
        putenv('APP_DEBUG=false');
        putenv('SECRET_FILE=');

        expect(DockerSecretHelper::get('SECRET'))->toBeNull();
    });
});
