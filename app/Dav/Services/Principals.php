<?php

namespace App\Dav\Services;

use Sabre\DAV\MkCol;
use Sabre\DAVACL\PrincipalBackend\PDO as SabreBackend;

class Principals
{
    private readonly SabreBackend $backend;

    public function __construct(private readonly \PDO $pdo)
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

    public function delete(string $email): bool
    {
        $uris = [
            'principals/'.$email,
            'principals/'.$email.'/calendar-proxy-read',
            'principals/'.$email.'/calendar-proxy-write',
        ];

        $select = $this->pdo->prepare(
            'SELECT id FROM '.$this->backend->tableName.' WHERE uri IN (?, ?, ?)'
        );
        $select->execute($uris);
        $ids = $select->fetchAll(\PDO::FETCH_COLUMN);

        if ($ids === []) {
            return false;
        }

        $marks = implode(',', array_fill(0, count($ids), '?'));
        // Both directions: the user's principal can be the group (a delegate
        // was added to a proxy principal) or the member (the user was added
        // to another principal's group).
        $members = $this->pdo->prepare(
            'DELETE FROM '.$this->backend->groupMembersTableName.' WHERE principal_id IN ('.$marks.') OR member_id IN ('.$marks.')'
        );
        $members->execute([...$ids, ...$ids]);

        $principals = $this->pdo->prepare(
            'DELETE FROM '.$this->backend->tableName.' WHERE uri IN (?, ?, ?)'
        );
        $principals->execute($uris);

        return true;
    }
}
