<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\Character;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** Persist spell-grant ability choices per character, never on shared feature definitions. */
final readonly class CharacterFeatureSpellAbilityChoice
{
    public function __construct(private Connection $db, private CharacterFeatureSpellCastingResolver $resolver) {}

    public function list(Character $character): array
    {
        $rows=$this->db->fetchAllAssociative("SELECT g.id AS grant_id, d.name AS feature_name, s.name AS spell_name, g.casting_ability, a.ability AS chosen_ability
            FROM character_feature_spell c
            JOIN feature_spell_grant g ON g.id=c.grant_id
            JOIN character_feature_definition d ON d.id=g.feature_id
            JOIN spell_definition s ON s.id=c.spell_id
            LEFT JOIN character_spell_grant_ability_choice a ON a.character_id=c.character_id AND a.grant_id=g.id
            WHERE c.character_id=? AND c.active=true AND g.enabled=true AND g.casting_ability='CHOICE'
            ORDER BY g.id", [$character->getId()]);
        $out=[];
        foreach ($rows as $row) {
            $resolved=$this->resolver->resolve($character, (int)$row['grant_id']);
            if (!in_array($resolved['provenance']['type'] ?? null, ['race', 'character'], true)) continue;
            $out[]=['grantId'=>(int)$row['grant_id'],'featureName'=>$row['feature_name'],'spellName'=>$row['spell_name'],
                'chosenAbility'=>$row['chosen_ability'],'allowedAbilities'=>['intelligence','wisdom','charisma']];
        }
        return $out;
    }

    public function choose(Character $character, int $grantId, mixed $ability): array
    {
        if (!is_string($ability) || !in_array($ability, ['intelligence','wisdom','charisma'], true))
            throw new BadRequestHttpException('Choisissez Intelligence, Sagesse ou Charisme.');
        return $this->db->transactional(function () use ($character,$grantId,$ability) {
            $this->db->fetchOne('SELECT id FROM character WHERE id=? FOR UPDATE', [$character->getId()]);
            $resolution=$this->resolver->resolve($character,$grantId);
            if (!in_array($resolution['provenance']['type'] ?? null, ['race', 'character'], true) || !in_array($resolution['reason'], ['ability_choice_missing', null], true))
                throw new BadRequestHttpException('Ce sort ne propose pas de choix de caractéristique pour cette capacité.');
            $this->db->executeStatement('INSERT INTO character_spell_grant_ability_choice (character_id,grant_id,ability) VALUES (?,?,?) ON CONFLICT (character_id,grant_id) DO UPDATE SET ability=EXCLUDED.ability', [$character->getId(),$grantId,$ability]);
            return $this->list($character);
        });
    }
}
