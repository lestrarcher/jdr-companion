<?php

declare(strict_types=1);

namespace App\Dto;

/** A reference access path, not an acquisition, preparation or permission to cast. */
final readonly class ResolvedSpellAccess
{
    /**
     * @param array{id:int, slug:string, name:string, level:int, school:string, edition:string, origin:string} $spell
     * @param array{type:string, id:int, origin:string, classId:int, className:string, classLevel:int, subclassId:?int, subclassName:?string, unlockLevel:?int} $source
     * @param array{id:int, name:string, characterLevel:int}|null $castingClass A level of zero is explicit, not an invented class acquisition.
     * @param array{id:int, name:string, cost:int, resolvedForCharacter:bool, maximum:?int, current:null}|null $resource
     */
    public function __construct(
        public string $key,
        public array $spell,
        public string $mode,
        public array $source,
        public ?array $castingClass,
        public ?string $slotUsage = null,
        public ?string $restrictions = null,
        public ?array $resource = null,
        public ?string $castingAbility = null,
        public string $spellLevelEligibility = 'not-evaluated',
        public ?bool $canCast = null,
        public string $quotaImpact = 'unspecified',
        public ?array $substitution = null,
    ) {}
}
