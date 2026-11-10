<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;
use Doctrine\DBAL\Connection;

/** Checks active, persisted invocation choices (not character level alone). */
final readonly class PactInvocationAccess
{
    public function __construct(private Connection $db) {}

    public function hasAncientSecrets(Character $character): bool
    {
        return (bool) $this->db->fetchOne(
            "SELECT 1 FROM character_feature_choice c
             JOIN feature_choice_option o ON o.key = c.option_key
             JOIN character_feature_definition f ON f.id = o.feature_id
             WHERE c.character_id = ? AND c.active = TRUE
               AND o.group_key = 'eldritch-invocations'
               AND (f.slug IN ('eldritch-invocation-book-of-ancient-secrets', 'book-of-ancient-secrets')
                    OR lower(f.name) IN ('livre des secrets anciens', 'book of ancient secrets'))
             LIMIT 1",
            [$character->getId()],
        );
    }
}
