<?php
class Piece
{
    public const TYPES = ['opera', 'ballet', 'concert'];
    public function __construct(private PDO $pdo) {}
    public function all(): array
    {
        return $this->pdo->query("SELECT id,title,default_basses,type,notes,status,created_at,updated_at FROM pieces ORDER BY status DESC,title ASC")->fetchAll();
    }
    public function findById(int $id): ?array
    {
        $s = $this->pdo->prepare("SELECT id,title,default_basses,type,notes,status,created_at,updated_at FROM pieces WHERE id=? LIMIT 1");
        $s->execute([$id]);
        $r = $s->fetch();
        return $r ?: null;
    }
    public function create(string $title, int $basses, string $type, ?string $notes, int $status): int
    {
        $s = $this->pdo->prepare("INSERT INTO pieces (title,default_basses,type,notes,status) VALUES (?,?,?,?,?)");
        $s->execute([$title, $basses, $type, $notes !== '' ? $notes : null, $status]);
        return (int)$this->pdo->lastInsertId();
    }
    public function update(int $id, string $title, int $basses, string $type, ?string $notes, int $status): void
    {
        $s = $this->pdo->prepare("UPDATE pieces SET title=?,default_basses=?,type=?,notes=?,status=? WHERE id=?");
        $s->execute([$title, $basses, $type, $notes !== '' ? $notes : null, $status, $id]);
    }
}
