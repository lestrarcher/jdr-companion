<?php
declare(strict_types=1);
namespace App\Dto;

use App\Enum\SpellSchool;

/** Closed vocabulary. No reference identifiers or executable expressions in JSON. */
final readonly class SpellChoiceParameters
{
    public array $values;
    public function __construct(array $values)
    {
        $values+=['optionalReplacement'=>false]; $this->values=$values;
        $keys = ['opportunities','maxSpellLevels','minimumSpellLevel','replacementMinimumSpellLevel','schools','replacementSchools','quota','result','replacementBudget','replacements','allClassLists','optionalReplacement'];
        if (array_diff($keys,array_keys($values)) || array_diff(array_keys($values),$keys)) throw new \InvalidArgumentException('Paramètres de choix incomplets ou inconnus.');
        foreach (['opportunities'=>100,'maxSpellLevels'=>9,'replacements'=>100] as $key=>$max) {
            if (!is_array($values[$key]) || !array_is_list($values[$key]) || count($values[$key])!==20) throw new \InvalidArgumentException('Table de vingt niveaux requise.');
            foreach ($values[$key] as $n) if (!is_int($n) || $n<0 || $n>$max) throw new \InvalidArgumentException('Valeur de niveau invalide.');
        }
        foreach (['schools','replacementSchools'] as $key) {
            if (!is_array($values[$key]) || !array_is_list($values[$key]) || count(array_unique($values[$key],SORT_REGULAR))!==count($values[$key])) throw new \InvalidArgumentException('Écoles invalides.');
            foreach ($values[$key] as $school) if (!is_string($school) || SpellSchool::tryFrom($school)===null) throw new \InvalidArgumentException('École inconnue.');
        }
        if (!is_int($values['minimumSpellLevel']) || $values['minimumSpellLevel']<0 || $values['minimumSpellLevel']>9
            || !is_int($values['replacementMinimumSpellLevel']) || $values['replacementMinimumSpellLevel']<0 || $values['replacementMinimumSpellLevel']>9
            || !in_array($values['quota'],['known','cantrips','spellbook','free'],true)
            || !in_array($values['result'],['known','spellbook','cast'],true) || !is_bool($values['allClassLists']) || !is_bool($values['optionalReplacement'])
            || ($values['result']==='cast' && $values['quota']!=='free')
            || !is_string($values['replacementBudget']) || !preg_match('/^[a-z][a-z0-9-]{0,63}$/D',$values['replacementBudget'])
            || (($values['quota']==='spellbook') !== ($values['result']==='spellbook'))) throw new \InvalidArgumentException('Sémantique de choix invalide.');
        if ($values['quota']==='spellbook' && array_sum($values['replacements'])!==0) throw new \InvalidArgumentException('Les inscriptions de grimoire ne sont pas remplaçables.');
        if ($values['quota']==='cantrips' && ($values['minimumSpellLevel']!==0 || array_sum($values['maxSpellLevels'])!==0)) throw new \InvalidArgumentException('Le budget de sorts mineurs ne peut sélectionner des sorts de niveau.');
        if ($values['result']==='spellbook' && $values['minimumSpellLevel']===0) throw new \InvalidArgumentException('Les sorts mineurs ne sont pas inscrits au grimoire.');
    }

    public function at(string $field,int $level): int { return $this->values[$field][$level-1]; }
}
