<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CharacterClass;
use App\Entity\CharacterClassLevelRule;
use App\Entity\CharacterRace;
use App\Entity\CharacterSubclass;
use App\Entity\Feat;
use App\Entity\RaceAbilityModifier;
use App\Enum\Ability;
use App\Enum\LevelAdvancementChoice;
use App\Enum\SpellcastingProgressionType;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\TrackableResourceDefinition;
use App\Entity\TrackableResourceRule;
use App\Enum\ResourceMaximumType;
use App\Enum\ResourceRechargeType;

final class DndReferenceInitializer
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function initialize(): void
    {
        $this->initializeRaces();
        $classes = $this->initializeClasses();

        $this->initializeLevelAdvancementRules([
            'cleric' => $classes['cleric'],
            'artificer' => $classes['artificer'],
            'barbarian' => $classes['barbarian'],
            'wizard' => $classes['wizard'],
            'fighter' => $classes['fighter'],
            'paladin' => $classes['paladin'],
            'rogue' => $classes['rogue'],
        ]);
        $this->initializeTrackableResources($classes);

        $this->initializeSubclasses($classes);
        $this->initializeFeats();

        $this->entityManager->flush();
    }

    /**
     * @return array<string, CharacterRace>
     */
    private function initializeRaces(): array
    {
        $human = $this->race('human', 'Humain');
        $variantHuman = $this->race('variant-human', 'Humain variant', $human)->setFeatChoiceCount(1);
        $standardHuman = $this->race('standard-human', 'Humain standard', $human);

        $elf = $this->race('elf', 'Elfe');
        $highElf = $this->race('high-elf', 'Haut-elfe', $elf);

        $gnome = $this->race('gnome', 'Gnome');
        $forestGnome = $this->race('forest-gnome', 'Gnome des forêts', $gnome);

        $kobold = $this->race('kobold', 'Kobold');

        foreach (Ability::cases() as $ability) {
            $this->fixedModifier($standardHuman, $ability, 1);
        }

        $this->choiceModifier($variantHuman, 'ability-choice-1', 1);
        $this->choiceModifier($variantHuman, 'ability-choice-2', 1);

        $this->fixedModifier($elf, Ability::Dexterity, 2);
        $this->fixedModifier($highElf, Ability::Intelligence, 1);

        $this->fixedModifier($gnome, Ability::Intelligence, 2);
        $this->fixedModifier($forestGnome, Ability::Dexterity, 1);

        /*
         * Les bonus du Kobold restent volontairement
         * vides : plusieurs versions 2014 existent.
         * On les renseignera selon la fiche de Krixx.
         */

        return [
            'human' => $human,
            'standard-human' => $standardHuman,
            'variant-human' => $variantHuman,
            'elf' => $elf,
            'high-elf' => $highElf,
            'gnome' => $gnome,
            'forest-gnome' => $forestGnome,
            'kobold' => $kobold,
        ];
    }

    /**
     * @return array<string, CharacterClass>
     */
    private function initializeClasses(): array
    {
        return [
            'cleric' => $this->characterClass(
                'cleric',
                'Clerc',
                8,
                1,
                SpellcastingProgressionType::Full,
            ),
            'artificer' => $this->characterClass(
                'artificer',
                'Artificier',
                8,
                3,
                SpellcastingProgressionType::Artificer,
            ),
            'barbarian' => $this->characterClass(
                'barbarian',
                'Barbare',
                12,
                3,
                SpellcastingProgressionType::None,
            ),
            'wizard' => $this->characterClass(
                'wizard',
                'Magicien',
                6,
                2,
                SpellcastingProgressionType::Full,
            ),
            'fighter' => $this->characterClass(
                'fighter',
                'Guerrier',
                10,
                3,
                SpellcastingProgressionType::None,
            ),
            'paladin' => $this->characterClass(
                'paladin',
                'Paladin',
                10,
                3,
                SpellcastingProgressionType::Half,
            ),
            'rogue' => $this->characterClass(
                'rogue',
                'Roublard',
                8,
                3,
                SpellcastingProgressionType::None,
            ),
        ];
    }

    /**
     * @param array<string, CharacterClass> $classes
     */
    private function initializeSubclasses(array $classes): void
    {
        $this->subclass($classes['cleric'], 'light-domain', 'Domaine de la Lumière');
        $this->subclass($classes['artificer'], 'artillerist', 'Artilleur');
        $this->subclass($classes['barbarian'], 'wild-magic', 'Magie sauvage');
        $this->subclass($classes['wizard'], 'divination', 'Divination');

        $this->subclass(
            $classes['fighter'],
            'eldritch-knight',
            'Chevalier occulte',
            SpellcastingProgressionType::Third,
        );

        $this->subclass($classes['paladin'], 'vengeance', 'Serment de Vengeance');
    }

    private function initializeFeats(): void
    {
        $resilient = $this->feat('resilient', 'Résilient');
        $resilient
            ->setRequiresAbilityChoice(true)
            ->setChosenAbilityIncrease(1);

        $this->feat('war-caster', 'Mage de guerre');
    }

    private function race(
        string $slug,
        string $name,
        ?CharacterRace $parent = null,
    ): CharacterRace {
        $repository = $this->entityManager->getRepository(CharacterRace::class);
        $race = $repository->findOneBy(['slug' => $slug]);

        if (!$race instanceof CharacterRace) {
            $race = new CharacterRace($slug, $name);
            $this->entityManager->persist($race);
        } else {
            $race->setName($name);
        }

        $race->setParentRace($parent);

        return $race;
    }

    private function characterClass(
        string $slug,
        string $name,
        int $hitDie,
        int $subclassLevel,
        SpellcastingProgressionType $spellcasting,
    ): CharacterClass {
        $repository = $this->entityManager->getRepository(CharacterClass::class);
        $class = $repository->findOneBy(['slug' => $slug]);

        if (!$class instanceof CharacterClass) {
            $class = new CharacterClass(
                $slug,
                $name,
                $hitDie,
                $subclassLevel,
                $spellcasting,
            );

            $this->entityManager->persist($class);

            return $class;
        }

        $class
            ->setName($name)
            ->setHitDie($hitDie)
            ->setSubclassSelectionLevel($subclassLevel)
            ->setSpellcastingProgression($spellcasting);

        return $class;
    }

    private function subclass(
        CharacterClass $class,
        string $slug,
        string $name,
        ?SpellcastingProgressionType $spellcasting = null,
    ): CharacterSubclass {
        $repository = $this->entityManager->getRepository(CharacterSubclass::class);

        $subclass = $repository->findOneBy([
            'characterClass' => $class,
            'slug' => $slug,
        ]);

        if (!$subclass instanceof CharacterSubclass) {
            $subclass = new CharacterSubclass($class, $slug, $name);
            $this->entityManager->persist($subclass);
        } else {
            $subclass->setName($name);
        }

        $subclass->setSpellcastingProgression($spellcasting);

        return $subclass;
    }

    private function feat(string $slug, string $name): Feat
    {
        $repository = $this->entityManager->getRepository(Feat::class);
        $feat = $repository->findOneBy(['slug' => $slug]);

        if (!$feat instanceof Feat) {
            $feat = new Feat($slug, $name);
            $this->entityManager->persist($feat);
        } else {
            $feat->setName($name);
        }

        return $feat;
    }

    private function fixedModifier(
        CharacterRace $race,
        Ability $ability,
        int $value,
    ): void {
        $repository = $this->entityManager->getRepository(RaceAbilityModifier::class);

        $existing = $repository->findOneBy([
            'race' => $race,
            'ability' => $ability,
            'choiceKey' => null,
        ]);

        if ($existing instanceof RaceAbilityModifier) {
            return;
        }

        $modifier = new RaceAbilityModifier($race, $value, $ability);
        $race->addAbilityModifier($modifier);
        $this->entityManager->persist($modifier);
    }

    private function choiceModifier(
        CharacterRace $race,
        string $choiceKey,
        int $value,
    ): void {
        $repository = $this->entityManager->getRepository(RaceAbilityModifier::class);

        $existing = $repository->findOneBy([
            'race' => $race,
            'choiceKey' => $choiceKey,
        ]);

        if ($existing instanceof RaceAbilityModifier) {
            return;
        }

        $modifier = new RaceAbilityModifier($race, $value, null, $choiceKey);
        $race->addAbilityModifier($modifier);
        $this->entityManager->persist($modifier);
    }

    /**
     * @param array<string, CharacterClass> $classes
     */
    private function initializeLevelAdvancementRules(array $classes): void
    {
        $levelsByClass = [
            'cleric' => [4, 8, 12, 16, 19],
            'artificer' => [4, 8, 12, 16, 19],
            'barbarian' => [4, 8, 12, 16, 19],
            'wizard' => [4, 8, 12, 16, 19],
            'paladin' => [4, 8, 12, 16, 19],
            'fighter' => [4, 6, 8, 12, 14, 16, 19],
            'rogue' => [4, 8, 10, 12, 16, 19],
        ];

        $repository = $this->entityManager->getRepository(CharacterClassLevelRule::class);

        foreach ($levelsByClass as $classSlug => $levels) {
            $characterClass = $classes[$classSlug] ?? null;

            if ($characterClass === null) {
                throw new \LogicException(sprintf(
                    'La classe "%s" doit être initialisée avant ses règles de progression.',
                    $classSlug,
                ));
            }

            foreach ($levels as $level) {
                $rule = $repository->findOneBy([
                    'characterClass' => $characterClass,
                    'level' => $level,
                ]);

                if ($rule !== null) {
                    $rule->setAdvancementChoice(
                        LevelAdvancementChoice::AbilityScoreImprovementOrFeat,
                    );

                    continue;
                }

                $this->entityManager->persist(
                    new CharacterClassLevelRule(
                        characterClass: $characterClass,
                        level: $level,
                        advancementChoice: LevelAdvancementChoice::AbilityScoreImprovementOrFeat,
                    ),
                );
            }
        }
    }

    /**
 * @param array<string, CharacterClass> $classes
 */
private function initializeTrackableResources(array $classes): void
{
    $rage = $this->resource(
        slug: 'rage',
        name: 'Rage',
        rechargeType: ResourceRechargeType::LongRest,
        maximumType: ResourceMaximumType::Fixed,
        baseMaximum: 2,
    );

    foreach ([1 => 2, 3 => 3, 6 => 4, 12 => 5, 17 => 6] as $level => $maximum) {
        $this->classResourceRule(
            resource: $rage,
            characterClass: $classes['barbarian'],
            unlockLevel: $level,
            maximumOverride: $maximum,
        );
    }
}

private function resource(
    string $slug,
    string $name,
    ResourceRechargeType $rechargeType,
    ResourceMaximumType $maximumType,
    int $baseMaximum,
): TrackableResourceDefinition {
    $repository = $this->entityManager->getRepository(
        TrackableResourceDefinition::class,
    );

    $resource = $repository->findOneBy(['slug' => $slug]);

    if (!$resource instanceof TrackableResourceDefinition) {
        $resource = new TrackableResourceDefinition(
            slug: $slug,
            name: $name,
            rechargeType: $rechargeType,
            maximumType: $maximumType,
            baseMaximum: $baseMaximum,
        );

        $this->entityManager->persist($resource);

        return $resource;
    }

    $resource
        ->setName($name)
        ->setRechargeType($rechargeType)
        ->setMaximumType($maximumType)
        ->setBaseMaximum($baseMaximum);

    return $resource;
}

private function classResourceRule(
    TrackableResourceDefinition $resource,
    CharacterClass $characterClass,
    int $unlockLevel,
    ?int $maximumOverride = null,
): TrackableResourceRule {
    $repository = $this->entityManager->getRepository(
        TrackableResourceRule::class,
    );

    $rule = $repository->findOneBy([
        'resourceDefinition' => $resource,
        'characterClass' => $characterClass,
        'unlockLevel' => $unlockLevel,
    ]);

    if ($rule instanceof TrackableResourceRule) {
        $rule->setMaximumOverride($maximumOverride);

        return $rule;
    }

    $rule = TrackableResourceRule::forClass(
        resourceDefinition: $resource,
        characterClass: $characterClass,
        unlockLevel: $unlockLevel,
        maximumOverride: $maximumOverride,
    );

    $this->entityManager->persist($rule);

    return $rule;
}
}
