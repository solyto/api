<?php

namespace App\Dav\Services;

use Sabre\DAV\MkCol;
use Sabre\DAV\PropPatch;
use Sabre\DAVACL\PrincipalBackend\PDO as SabreBackend;

class Principals
{
    private readonly SabreBackend $backend;

    public function __construct(\PDO $pdo)
    {
        $this->backend = new SabreBackend($pdo);
    }

    public function list(): array
    {
        return $this->backend->getPrincipalsByPrefix('principals');
    }

    public function get(string $uri): ?array
    {
        return $this->backend->getPrincipalByPath($uri) ?: null;
    }

    public function create(string $email): string
    {
        $uri = 'principals/' . $email;

        if ($this->backend->getPrincipalByPath($uri)) {
            throw new \RuntimeException('Principal already exists');
        }

        $this->backend->createPrincipal(
            $uri,
            new MkCol(['{DAV:}principal', '{DAV:}collection'], [])
        );

        return $uri; // This is all you really need
    }
}
