<?php
declare(strict_types=1);
namespace App\Service;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
final readonly class BeastCatalogue
{
    public function __construct(private Connection $db) {}
    public function listForCharacter(int $characterId): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT b.id, b.slug, b.name, b.challenge_rating AS "challengeRating", b.source_url AS "sourceUrl", CASE WHEN b.image_filename IS NOT NULL THEN \'/api/public/beasts/images/\' || b.image_filename ELSE NULL END AS "imageUrl", b.stat_block AS "statBlock", (s.beast_id IS NOT NULL) AS seen FROM beast b LEFT JOIN character_seen_beast s ON s.beast_id = b.id AND s.character_id = :character ORDER BY b.slug', ['character' => $characterId]);
        $habitats = $this->db->fetchAllAssociative('SELECT beast_id, habitat FROM beast_habitat ORDER BY habitat');
        $byBeast = [];
        foreach ($habitats as $habitat) $byBeast[(int) $habitat['beast_id']][] = $habitat['habitat'];
        return array_map(static function (array $row) use ($byBeast): array {
            $row['id'] = (int) $row['id'];
            $row['seen'] = filter_var($row['seen'], FILTER_VALIDATE_BOOLEAN);
            $row['habitats'] = $byBeast[$row['id']] ?? [];
            $row['statBlock'] = $row['statBlock'] === null ? null : json_decode((string) $row['statBlock'], true);
            return $row;
        }, $rows);
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
