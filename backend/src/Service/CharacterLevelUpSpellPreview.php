<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\{Character, CharacterClass, CharacterClassLevel, CharacterSubclass, SpellcastingPolicyDefinition};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;

/** Read-only projection. Call only after the existing level-up GET authorization. */
final readonly class CharacterLevelUpSpellPreview
{
    public function __construct(
        private EntityManagerInterface $em,
        private CharacterLevelUpOptionsService $levelOptions,
        private CharacterSpellAcquisitions $acquisitions,
        private CharacterSpellAccessResolver $access,
    ) {}

    public function preview(Character $character, array $selection): array
    {
        if (!isset($selection['classId'])) return ['status'=>'selection-required', 'policies'=>[]];
        $classId = filter_var($selection['classId'], FILTER_VALIDATE_INT);
        $options = $this->levelOptions->getOptions($character);
        $option = array_column($options['classes'], null, 'id')[$classId ?: 0] ?? null;
        if (!$option || !$option['eligible']) throw new \DomainException('Classe indisponible pour la montée de niveau.');
        $class = $this->em->find(CharacterClass::class, $classId);
        $subclass = $character->getSubclassFor($class);
        if (isset($selection['subclassId'])) {
            $id = filter_var($selection['subclassId'], FILTER_VALIDATE_INT);
            if (!$id || ($subclass !== null && $subclass->getId() !== $id)
                || ($subclass === null && !in_array($id, array_column($option['subclasses'], 'id'), true))) {
                throw new \DomainException('Sous-classe indisponible pour la montée de niveau.');
            }
            $subclass = $this->em->find(CharacterSubclass::class, $id);
        }
        if ($option['subclassRequired'] && $subclass === null) return ['status'=>'subclass-required', 'policies'=>[]];

        $levels = $character->getClassLevels();
        $wasDirty = $levels instanceof PersistentCollection ? $levels->isDirty() : false;
        $level = new CharacterClassLevel($character, $class, $character->getNextLevelPosition(), $subclass);
        $character->addClassLevel($level);
        try {
            $owner = $character->getCampaign()->getOwner();
            $status = $this->acquisitions->levelUpStatus($character, $class, $owner);
            if ($status['status'] !== 'policy-configured') return ['status'=>$status['status'], 'classId'=>$classId, 'classLevel'=>$option['nextLevel'], 'policies'=>[]];
            $policy = $this->em->find(SpellcastingPolicyDefinition::class, $status['policyIds'][0]);
            $data = $this->acquisitions->options($character, $policy->getId(), $owner);
            $n = $data['classLevel'];
            $activeIds = array_map('intval', array_column($data['acquisitions'], 'spell_id'));
            $automaticIds = array_column(array_filter($data['automatic'], static fn($g)=>$g['bucket'] !== null), 'spellId');
            $candidates = static fn(array $spells): array => array_values(array_filter($spells, static fn($s)=>!in_array((int)$s['id'], $activeIds, true) && !in_array((int)$s['id'], $automaticIds, true)));
            $choices = [];
            if ($policy->getFormatVersion() === 2) {
                $advanced = new AdvancedSpellChoices($this->em, $this->access);
                $rules = $advanced->rules($policy);
                foreach ($data['choices'] as $choice) {
                    $p = $rules[$choice['ruleId']]->parameters()->values;
                    $replacements = [];
                    foreach ($data['acquisitions'] as $row) {
                        if ((int)$row['rule_id'] !== $choice['ruleId'] || (int)$row['spell_level'] < $p['replacementMinimumSpellLevel']) continue;
                        $replacements[] = [
                            'acquisitionId' => (int) $row['id'],
                            'spellId' => (int) $row['spell_id'],
                            'spellName' => $row['spell_name'] ?? null,
                            'requiresQualification' => $advanced->requiresQualification($row, $rules[$choice['ruleId']], $rules),
                        ];
                    }
                    $choices[] = $choice + ['replacement'=>['maximum'=>$p['replacements'][$n-1], 'budgetKey'=>$p['replacementBudget'], 'confirmationRequired'=>$p['optionalReplacement'], 'acquisitions'=>$replacements]];
                }
            } else {
                foreach (['cantrips', 'known', 'spellbook'] as $bucket) {
                    if ($bucket === 'known' && $policy->policy()->learningStyle !== 'known') continue;
                    if ($bucket === 'spellbook' && $policy->policy()->learningStyle !== 'spellbook') continue;
                    $eligible = array_values(array_filter($data['available'], static fn($s)=>($s['level'] === 0) === ($bucket === 'cantrips')));
                    $choices[] = ['ruleId'=>null, 'key'=>$bucket, 'quota'=>$bucket, 'remaining'=>max(0,$policy->policy()->additionsAtLevel($n,$bucket)), 'available'=>$eligible, 'replacementAvailable'=>$bucket === 'known' ? $eligible : [],
                        'replacement'=>['maximum'=>$bucket === 'known' ? $data['limits']['replacements'] : 0, 'budgetKey'=>$bucket, 'confirmationRequired'=>false,
                            'acquisitions'=>array_values(array_map(static fn($r)=>['acquisitionId' => (int) $r['id'],
'spellId' => (int) $r['spell_id'],
'spellName' => $r['spell_name'],
'requiresQualification' => false], array_filter($data['acquisitions'], static fn($r)=>$bucket === 'known' && $r['quota_bucket'] === 'known' && (int)$r['spell_level'] > 0)))]];
                }
            }
            foreach ($choices as &$choice) {
                $choice['available'] = $candidates($choice['available']);
                $choice['replacementAvailable'] = $choice['replacement']['maximum'] > 0 ? $candidates($choice['replacementAvailable']) : [];
                $choice['policyId'] = $policy->getId();
                $choice['classId'] = $classId;
                $choice['classLevel'] = $n;
            }
            unset($choice);
            return ['status'=>$data['status'], 'policies'=>[[
                'policyId'=>$policy->getId(), 'version'=>$policy->policy()->version, 'classId'=>$classId, 'className'=>$class->getName(), 'sourceName'=>$policy->getSource()->getName(),
                'classLevel'=>$n, 'classLevelId'=>null, 'position'=>$level->getPosition(), 'learningStyle'=>$data['learningStyle'],
                'choices'=>$choices, 'limits'=>$data['limits'], 'used'=>$data['used'], 'remaining'=>$data['remaining'], 'blocked'=>$data['blocked'],
                'acquisitions'=>$data['acquisitions'], 'automatic'=>$data['automatic'], 'preparation'=>$data['preparation'],
            ]]];
        } finally {
            $character->removeClassLevel($level);
            if ($levels instanceof PersistentCollection) $levels->setDirty($wasDirty);
        }
    }
}
