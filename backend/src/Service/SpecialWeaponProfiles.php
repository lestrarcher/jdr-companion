<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\MagicItem;

/** SRD 5.1 (2014) special weapons. Physical base is chosen per owned instance. */
final class SpecialWeaponProfiles
{
    private const FIXED = [
        'oathbow'=>['longbow',0], 'scimitar-of-speed'=>['scimitar',2],
        'dagger-of-venom'=>['dagger',1], 'javelin-of-lightning'=>['javelin',0],
        'dwarven-thrower'=>['warhammer',3], 'hammer-of-thunderbolts'=>['maul',1],
        'mace-of-disruption'=>['mace',0], 'mace-of-smiting'=>['mace',0],
        'mace-of-terror'=>['mace',0], 'trident-of-fish-command'=>['trident',0],
        'sun-blade'=>['longsword',2],
    ];
    private const VARIABLE = [
        'vicious-weapon'=>'all', 'frost-brand'=>'swords',
        'defender'=>'swords', 'berserker-axe'=>'axes', 'luck-blade'=>'swords',
        'dragon-slayer'=>'swords', 'giant-slayer'=>'swords-axes',
        'holy-avenger'=>'swords', 'nine-lives-stealer'=>'swords',
        'sword-of-sharpness'=>'slashing-swords', 'flame-tongue'=>'swords',
        'dancing-sword'=>'swords', 'sword-of-wounding'=>'swords',
        'sword-of-life-stealing'=>'swords', 'vorpal-sword'=>'slashing-swords',
    ];
    private const SWORDS = ['greatsword','longsword','rapier','scimitar','shortsword'];
    private const AXES = ['battleaxe','greataxe','handaxe'];
    private const LABELS = [
        'battleaxe'=>'Hache d’armes','greataxe'=>'Hache à deux mains','handaxe'=>'Hachette',
        'greatsword'=>'Épée à deux mains','longsword'=>'Épée longue','rapier'=>'Rapière',
        'scimitar'=>'Cimeterre','shortsword'=>'Épée courte',
    ];
    private static function slug(MagicItem $item): ?string
    {
        if ($item->getEdition() !== '2014' || $item->getOrigin() !== \App\Enum\ReferenceOrigin::Official) return null;
        $key = $item->getImportKey();
        $prefix = 'srd-5.1:magic-item:';
        return $key !== null && str_starts_with($key,$prefix) ? substr($key,strlen($prefix)) : null;
    }
    public static function isVariable(MagicItem $item): bool { return isset(self::VARIABLE[self::slug($item) ?? '']); }
    public static function isFixed(MagicItem $item): bool { return isset(self::FIXED[self::slug($item) ?? '']); }
    public static function choices(MagicItem $item): array
    {
        $kind = self::VARIABLE[self::slug($item) ?? ''] ?? null;
        if ($kind === null) return [];
        $all = self::baseProfiles();
        $keys = match ($kind) {
            'swords'=>self::SWORDS,
            'slashing-swords'=>['greatsword','longsword','scimitar'],
            'axes'=>self::AXES,
            'swords-axes'=>[...self::SWORDS,...self::AXES],
            default=>array_keys($all),
        };
        $result=[];
        foreach ($keys as $key) if (isset($all[$key])) $result[]=['key'=>$key,'label'=>self::LABELS[$key] ?? ucfirst(str_replace('-',' ',$key))];
        return $result;
    }
    public static function profile(MagicItem $item, ?string $baseKey): ?array
    {
        $slug=self::slug($item);
        $fixed=self::FIXED[$slug ?? ''] ?? null;
        if ($fixed !== null) { $baseKey=$fixed[0]; $bonus=$fixed[1]; }
        else {
            if (!self::isVariable($item)) return null;
            $allowed=array_column(self::choices($item),'key');
            if ($baseKey === null || !in_array($baseKey,$allowed,true)) throw new \InvalidArgumentException('Choisissez une arme de base compatible.');
            $bonus=match ($slug) { 'defender','holy-avenger','vorpal-sword'=>3, 'nine-lives-stealer'=>2, 'dragon-slayer','giant-slayer','berserker-axe','luck-blade','dancing-sword'=>1, default=>0 };
        }
        $profile=self::baseProfiles()[$baseKey] ?? null;
        if ($profile === null) throw new \LogicException('Profil de base absent : '.$baseKey);
        $profile['attackBonus']=$bonus;
        $profile['damageBonus']=$bonus;
        if ($slug === 'sun-blade') { unset($profile['versatile']); $profile['properties']=array_values(array_unique([...$profile['properties'],'finesse'])); $profile['damageType']='slashing'; $profile['notes']='Dégâts radiants (et non tranchants) ; les effets spéciaux restent décrits sur l’objet.'; }
        if ($slug === 'defender') $profile['notes']='Le bonus +3 peut être transféré en CA : le calcul affiché suppose qu’aucun point n’a été transféré.';
        if ($slug === 'dwarven-thrower') { $profile['properties']=array_values(array_unique([...$profile['properties'],'thrown'])); $profile['range']=[6,18]; }
        return WeaponProfileSchema::validate($profile);
    }
    public static function baseProfiles(): array
    {
        static $profiles=null;
        if ($profiles === null) {
            $raw=json_decode(file_get_contents(__DIR__.'/../../data/weapon-profiles-2014.json'),true,512,JSON_THROW_ON_ERROR);
            $profiles=[];
            foreach ($raw as $key=>$value) $profiles[substr($key,strlen('phb2014-weapon-'))]=$value;
        }
        return $profiles;
    }
    public static function baseLabel(string $key): string { return self::LABELS[$key] ?? ucfirst(str_replace('-',' ',$key)); }
}
