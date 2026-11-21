<?php
declare(strict_types=1);
namespace App\Service;
use Doctrine\DBAL\Connection;
use App\Repository\BeastRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
final readonly class BeastCatalogue
{
    public function __construct(private Connection $db, private BeastRepository $beasts, private BeastSerializer $serializer) {}
    public function listForCharacter(int $characterId): array
    {
        $seen = array_flip(array_map('intval', $this->db->fetchFirstColumn('SELECT beast_id FROM character_seen_beast WHERE character_id = ?', [$characterId])));
        return array_map(fn ($beast) => $this->serializer->catalogue($beast) + ['seen' => isset($seen[$beast->getId()])], $this->beasts->catalogue());
    }
    public function setSeen(int $characterId, int $beastId, bool $seen): void
    {
        if (!$this->db->fetchOne('SELECT 1 FROM beast WHERE id = :id', ['id' => $beastId])) throw new NotFoundHttpException('Bête inconnue.');
        if ($seen) $this->db->executeStatement('INSERT INTO character_seen_beast (character_id, beast_id) VALUES (:character, :beast) ON CONFLICT DO NOTHING', ['character' => $characterId, 'beast' => $beastId]);
        else $this->db->executeStatement('DELETE FROM character_seen_beast WHERE character_id = :character AND beast_id = :beast', ['character' => $characterId, 'beast' => $beastId]);
    }
    public function setSeenBulk(int $characterId, array $ids, bool $seen): int
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) return 0;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $valid = $this->db->fetchFirstColumn('SELECT id FROM beast WHERE id IN ('.$placeholders.')', $ids);
        if (count($valid) !== count($ids)) throw new NotFoundHttpException('Une ou plusieurs bêtes sont inconnues.');
        $this->db->transactional(function () use ($characterId, $ids, $seen): void {
            foreach ($ids as $id) $this->setSeen($characterId, $id, $seen);
        });
        return count($ids);
    }
}
