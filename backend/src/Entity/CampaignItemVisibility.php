<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'campaign_item_visibility')]
#[ORM\UniqueConstraint(name: 'uniq_campaign_item_visibility', columns: ['campaign_id', 'magic_item_id'])]
#[ORM\Index(name: 'idx_item_visibility_campaign', columns: ['campaign_id'])]
#[ORM\Index(name: 'idx_item_visibility_definition', columns: ['magic_item_id'])]
class CampaignItemVisibility
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Campaign $campaign;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MagicItem $magicItem;

    #[ORM\Column(length: 10)]
    private string $decision;

    public function __construct(Campaign $campaign, MagicItem $magicItem, string $decision)
    {
        $this->campaign = $campaign;
        $this->magicItem = $magicItem;
        $this->setDecision($decision);
    }

    public function getDecision(): string { return $this->decision; }

    public function setDecision(string $decision): void
    {
        if (!in_array($decision, ['hidden', 'published'], true)) {
            throw new \InvalidArgumentException('Décision de visibilité invalide.');
        }
        $this->decision = $decision;
    }
}
