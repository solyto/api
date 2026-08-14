<?php

namespace App\Dav\Services;

use Sabre\DAV\MkCol;
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
        $uri = 'principals/'.$email;

        if ($this->backend->getPrincipalByPath($uri)) {
            throw new \RuntimeException('Principal already exists');
        }

        $mkCol = new MkCol(
            ['{DAV:}principal', '{DAV:}collection'],
            ['{http://sabredav.org/ns}email-address' => $email]
        );

        $this->backend->createPrincipal($uri, $mkCol);
        $mkCol->commit();

        return $uri; // This is all you really need
    }
}
