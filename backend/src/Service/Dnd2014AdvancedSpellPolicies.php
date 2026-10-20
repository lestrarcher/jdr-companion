<?php
declare(strict_types=1);
namespace App\Service;
use App\Dto\{SpellcastingPolicy,SpellChoiceParameters};
use App\Entity\{CharacterClass,SpellDefinition};
use App\Enum\Ability;

/** Explicit preset builder, never matched to a persisted name/slug. No writes. */
final class Dnd2014AdvancedSpellPolicies
{
    /** Additional Circle of the Land cantrip. Added explicitly to a composed policy. */
    public static function landCircleCantrip(CharacterClass $druid): array
    {
        $op=array_fill(0,20,0); $op[1]=1;
        return self::rule('land-circle-cantrip',$op,array_fill(0,20,0),0,[],[],'free',array_fill(0,20,0),$druid,null);
    }

    /** Explicit configuration recipe, not inferred from the subclass name. No persistence. */
    public static function sorcererSubstitution(string $feature,int $grantId,array $classIds,int $version=1): array
    {
        $schools=match ($feature) {
            'psionic-spells'=>['divination','enchantment'],
            'clockwork-magic'=>['abjuration','transmutation'],
            default=>throw new \InvalidArgumentException('Particularité non prise en charge.'),
        };
        if ($grantId<1 || $version<1 || count($classIds)!==3 || count(array_unique($classIds))!==3) throw new \InvalidArgumentException('Trois listes explicites distinctes sont requises : ensorceleur, occultiste, magicien.');
        foreach ($classIds as $id) if (!is_int($id) || $id<1) throw new \InvalidArgumentException('Identifiant de liste invalide.');
        return ['grantId'=>$grantId,'version'=>$version,'budgetKey'=>$feature,'schools'=>$schools,'opportunities'=>array_fill(0,20,1),'classIds'=>$classIds,'provenance'=>'Tasha 2020, règles 2014 : '.$feature.'. Listes choisies explicitement : ensorceleur, occultiste, magicien. Un remplacement partagé par niveau de la classe source, même niveau de sort.'];
    }
    /** Explicit ASI cantrip option, not a generic Tasha package for every class. */
    public static function ordinary(CharacterClass $class,SpellcastingPolicy $policy,bool $asiCantripReplacement=false): array
    {
        $tasha=$asiCantripReplacement;
        $max=array_column($policy->levels,'maxSpellLevel');
        $minor=array_fill(0,20,0); if ($tasha) foreach ([4,8,12,16,19] as $n) $minor[$n-1]=1;
        $rules=[self::rule('cantrips',self::gains(array_column($policy->levels,'cantrips')),array_fill(0,20,0),0,[],[],'cantrips',$minor,$class,$class)];
        $v=$rules[0]['parameters']->values; $v['replacementMinimumSpellLevel']=0; $v['optionalReplacement']=$tasha; $v['replacementBudget']='cantrip-versatility';
        $rules[0]['parameters']=new SpellChoiceParameters($v);
        if ($policy->learningStyle==='known') $rules[]=self::rule('ordinary',self::gains(array_column($policy->levels,'known')),$max,1,[],[],'known',array_column($policy->levels,'replacements'),$class,$class);
        if ($policy->learningStyle==='spellbook') {
            $r=self::rule('book',array_column($policy->levels,'bookAdditions'),$max,1,[],[],'spellbook',array_fill(0,20,0),$class,$class);
            $v=$r['parameters']->values; $v['result']='spellbook'; $r['parameters']=new SpellChoiceParameters($v); $rules[]=$r;
        }
        $version=new SpellcastingPolicy($policy->key.($tasha?'-tasha':'-advanced'),$policy->version,$policy->ability,$policy->learningStyle,$policy->levels,$policy->provenance.($tasha?' ; option Tasha explicite de remplacement aux niveaux d’ASI.':''),$policy->limitations);
        return ['policy'=>$version,'rules'=>$rules];
    }

    public static function artificer(CharacterClass $class,int $version=1): array
    {
        $max=[1,1,1,1,2,2,2,2,3,3,3,3,4,4,4,4,5,5,5,5]; $levels=[];
        for($n=1;$n<=20;++$n) $levels[]=['maxSpellLevel'=>$max[$n-1],'cantrips'=>2+($n>=10?1:0)+($n>=14?1:0),'known'=>0,'bookAdditions'=>0,'replacements'=>0,'preparationBase'=>intdiv($n,2)];
        $p=self::ordinary($class,new SpellcastingPolicy('artificer-2014',$version,Ability::Intelligence,'prepared',$levels,'Artificier, Tasha 2020, règles 2014. Préparation finale : minimum 1 après INT.'));
        $v=$p['rules'][0]['parameters']->values; $v['replacements']=array_merge([0],array_fill(0,19,1)); $p['rules'][0]['parameters']=new SpellChoiceParameters($v);
        return $p;
    }

    public static function warlock(CharacterClass $class,bool $tasha=false): array
    {
        $p=self::ordinary($class,Dnd2014SpellPolicies::get('warlock-2014'),$tasha);
        $old=$p['policy'];
        $p['policy']=new SpellcastingPolicy($old->key,$old->version,$old->ability,$old->learningStyle,$old->levels,$old->provenance,['Pact Magic ordinaire et choix d’Arcanums séparés ; aucun lancement, pacte choisi ou invocation résolu automatiquement.']);
        $replacement=array_fill(0,20,0); if ($tasha) foreach ([4,8,12,16,19] as $n) $replacement[$n-1]=1;
        $v=$p['rules'][0]['parameters']->values; $v['replacementBudget']='eldritch-versatility'; $p['rules'][0]['parameters']=new SpellChoiceParameters($v);
        foreach ([11=>6,13=>7,15=>8,17=>9] as $unlock=>$spellLevel) {
            $op=array_fill(0,20,0); $op[$unlock-1]=1;
            $r=self::rule('arcanum-'.$spellLevel,$op,array_fill(0,20,$spellLevel),$spellLevel,[],[],'free',$replacement,$class,$class);
            $v=$r['parameters']->values; $v['result']='cast'; $v['replacementMinimumSpellLevel']=$spellLevel; $v['replacementBudget']='eldritch-versatility'; $v['optionalReplacement']=$tasha;
            $r['parameters']=new SpellChoiceParameters($v); $p['rules'][]=$r;
        }
        return $p;
    }

    public static function bard(CharacterClass $bard,int $version=1): array
    {
        $known=[4,5,6,7,8,9,10,11,12,14,15,15,16,18,19,19,20,22,22,22];
        $max=[1,1,2,2,3,3,4,4,5,5,6,6,7,7,8,8,9,9,9,9];
        $cantrips=[2,2,2,3,3,3,3,3,3,4,4,4,4,4,4,4,4,4,4,4];
        $replacement=array_merge([0],array_fill(0,19,1));
        $secrets=array_fill(0,20,0); foreach ([10,14,18] as $level) $secrets[$level-1]=2;
        $ordinary=self::gains($known); foreach ($secrets as $i=>$n) $ordinary[$i]-=$n;
        return ['policy'=>self::policy('bard-2014-advanced',$version,Ability::Charisma,$known,$cantrips,$max,$replacement), 'rules'=>[
            self::rule('ordinary',$ordinary,$max,1,[],[],'known',$replacement,$bard,$bard),
            self::rule('cantrips',self::gains($cantrips),array_fill(0,20,0),0,[],[],'cantrips',array_fill(0,20,0),$bard,$bard),
            self::rule('magical-secrets',$secrets,$max,0,[],[],'known',$replacement,null,$bard),
        ]];
    }
    public static function lore(CharacterClass $bard): array
    {
        $base=self::bard($bard); $opportunities=array_fill(0,20,0); $opportunities[5]=2;
        return [self::rule('additional-secrets',$opportunities,array_column($base['policy']->levels,'maxSpellLevel'),0,[],[],'free',array_merge([0],array_fill(0,19,1)),null,$bard)];
    }
    public static function thirdCaster(CharacterClass $wizard,bool $trickster,?SpellDefinition $mageHand=null,int $version=1): array
    {
        if ($trickster && ($mageHand===null || $mageHand->getLevel()!==0)) throw new \InvalidArgumentException('Référence explicite du sort mineur imposé requise.');
        $known=[0,0,3,4,4,4,5,6,6,7,8,8,9,10,10,11,11,11,12,13];
        $max=[0,0,1,1,1,1,2,2,2,2,2,2,3,3,3,3,3,3,4,4];
        $cantrips=array_merge([0,0],array_fill(0,7,$trickster?3:2),array_fill(0,11,$trickster?4:3));
        $replacement=array_merge([0,0,0],array_fill(0,17,1));
        $open=array_fill(0,20,0); foreach ([3,8,14,20] as $level) $open[$level-1]=1;
        $restricted=self::gains($known); foreach ($open as $i=>$n) $restricted[$i]-=$n;
        $schools=$trickster?['enchantment','illusion']:['abjuration','evocation'];
        $minor=self::gains($cantrips); if ($trickster) --$minor[2];
        $rules=[self::rule('restricted',$restricted,$max,1,$schools,$schools,'known',$replacement,$wizard,null),
            self::rule('unrestricted',$open,$max,1,[],[],'known',$replacement,$wizard,null),
            self::rule('cantrips',$minor,array_fill(0,20,0),0,[],[],'cantrips',array_fill(0,20,0),$wizard,null)];
        if ($trickster) { $fixed=array_fill(0,20,0); $fixed[2]=1; $rule=self::rule('required-cantrip',$fixed,array_fill(0,20,0),0,[],[],'cantrips',array_fill(0,20,0),$wizard,null); $rule['requiredSpell']=$mageHand; $rules[]=$rule; }
        return ['policy'=>self::policy($trickster?'arcane-trickster-2014-advanced':'eldritch-knight-2014-advanced',$version,Ability::Intelligence,$known,$cantrips,$max,$replacement),'rules'=>$rules];
    }
    private static function gains(array $totals): array
    { $out=[]; $previous=0; foreach ($totals as $n) { $out[]=$n-$previous; $previous=$n; } return $out; }
    private static function policy(string $key,int $version,Ability $ability,array $known,array $cantrips,array $max,array $replacement): SpellcastingPolicy
    {
        $levels=[]; foreach ($known as $i=>$n) $levels[]=['maxSpellLevel'=>$max[$i],'cantrips'=>$cantrips[$i],'known'=>$n,'bookAdditions'=>0,'replacements'=>$replacement[$i],'preparationBase'=>null];
        return new SpellcastingPolicy($key,$version,$ability,'known',$levels,'PHB 2014 + errata ; règles de choix explicites, sans options de remplacement Tasha.');
    }
    private static function rule(string $key,array $opportunities,array $max,int $minimum,array $schools,array $replacementSchools,string $quota,array $replacements,?CharacterClass $list,?CharacterClass $replacementList): array
    {
        return ['key'=>$key,'listClass'=>$list,'replacementListClass'=>$replacementList,'parameters'=>new SpellChoiceParameters([
            'opportunities'=>$opportunities,'maxSpellLevels'=>$max,'minimumSpellLevel'=>$minimum,'replacementMinimumSpellLevel'=>1,'schools'=>$schools,'replacementSchools'=>$replacementSchools,
            'quota'=>$quota,'result'=>$quota==='spellbook'?'spellbook':'known','replacementBudget'=>$quota==='cantrips'?'minor':'ordinary','replacements'=>$replacements,'allClassLists'=>$list===null])];
    }
}
