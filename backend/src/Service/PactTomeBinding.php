<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\{Character, CharacterMagicItem, MagicItem};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Runs only inside the character choice transaction, after choice validation. */
final readonly class PactTomeBinding
{
    public function __construct(private EntityManagerInterface $em, private PactTomeLibrary $tomes) {}

    public function view(Character $character): ?array
    {
        if ($character->getId() === null) return null;
        $row = $this->em->getConnection()->fetchAssociative('SELECT id,pact_choice_key,character_id FROM character_magic_item WHERE pact_owner_id=?',[$character->getId()]);
        return $row ? ['ownedItemId'=>(int)$row['id'],'choiceKey'=>$row['pact_choice_key'],
            'holderCharacterId'=>$row['character_id'] === null ? null : (int)$row['character_id']] : null;
    }

    public function ensure(Character $character, array $view, ?int $existingBookId = null): void
    {
        if (!$this->em->getConnection()->isTransactionActive()) throw new \LogicException('Transaction requise.');
        $choiceKey = null;
        foreach ($view['grants'] as $grant) foreach ($grant['selected'] as $selection) {
            foreach ($grant['options'] as $option) if ($selection['valid'] && $selection['optionKey'] === $option['key']
                && $option['featureKey'] === 'pact-of-the-tome') $choiceKey = $selection['key'];
        }
        if ($choiceKey === null) {
            if ($existingBookId !== null) throw new \DomainException('Pacte du grimoire acquis requis.');
            return;
        }
        $linked = $this->em->getRepository(CharacterMagicItem::class)->findOneBy(['pactOwner'=>$character]);
        if ($linked !== null) {
            if ($existingBookId !== null && $existingBookId !== $linked->getId()) throw new \DomainException('Un Livre des ombres est déjà lié ; son remplacement est hors périmètre.');
            return; // Preserve the original acquisition and the physical book, even after transfer.
        }
        if ($existingBookId !== null) {
            $book = $this->em->find(CharacterMagicItem::class,$existingBookId,LockMode::PESSIMISTIC_WRITE);
            if ($book === null || $book->getCharacter() !== $character || $book->getCampaign() !== $character->getCampaign()
                || !$this->tomes->isPactTome($book) || $this->em->getConnection()->fetchOne('SELECT 1 FROM spellbook_entry WHERE owned_item_id=?',[$existingBookId])) {
                throw new \DomainException('Livre des ombres existant requis dans cet inventaire ; aucun grimoire de magicien ne sera converti.');
            }
        } else {
            foreach ($character->getMagicItems() as $owned) if ($this->tomes->isPactTome($owned)) {
                throw new \DomainException('Un Livre des ombres existe déjà : rattachement MJ explicite requis.');
            }
            $definition = new MagicItem($character->getCampaign(),'Livre des ombres',null);
            $definition->classify('spellbook',false,false);
            $this->em->persist($definition);
            $book = new CharacterMagicItem($character,$definition);
            $book->setDisplayName('Livre des ombres de '.$character->getName());
            $character->addMagicItem($book);
            $this->em->persist($book);
        }
        $book->bindPact($character,$choiceKey);
        $this->em->flush();
    }
}
