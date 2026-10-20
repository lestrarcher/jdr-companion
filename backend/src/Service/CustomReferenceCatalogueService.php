<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\{CharacterClass, CharacterSubclass, CharacterRace, RaceAbilityModifier, Feat, ProgressionDefinition, User};
use App\Enum\{ReferenceOrigin, SpellcastingProgressionType};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, ConflictHttpException, NotFoundHttpException};

/** Personal MJ catalogue. Intentionally no DELETE until usage dependencies are audited. */
final readonly class CustomReferenceCatalogueService
{
    private const TYPES = [
        'classes' => CharacterClass::class,
        'subclasses' => CharacterSubclass::class,
        'races' => CharacterRace::class,
        'feats' => Feat::class,
        'progressions' => ProgressionDefinition::class,
    ];

    public function __construct(private EntityManagerInterface $em) {}

    public function editorList(string $category, User $actor, bool $official): array
    {
        if (!in_array($category,['classes','subclasses','feats','progressions'],true)) throw new NotFoundHttpException();
        if ($official) $this->requireReferenceAdmin($actor);
        $query=$this->em->getRepository(self::TYPES[$category])->createQueryBuilder('r')->where('r.origin = :official')->setParameter('official',ReferenceOrigin::Official);
        if (!$official) $query->orWhere('r.origin = :custom AND r.owner = :actor')->setParameter('custom',ReferenceOrigin::Custom)->setParameter('actor',$actor);
        $rows=[];
        foreach ($query->orderBy('r.name','ASC')->addOrderBy('r.id','ASC')->getQuery()->getResult() as $item) {
            if ($item instanceof CharacterSubclass && !$this->subclassVisible($item,$actor)) continue;
            $rows[]=$this->editorView($item,$actor,$official);
        }
        return [$category=>$rows,'hitDice'=>[6,8,10,12],
            'spellcastingProgressions'=>array_map(static fn($p)=>['value'=>$p->value,'label'=>$p->label()],SpellcastingProgressionType::cases()),
            'abilities'=>array_map(static fn($a)=>['value'=>$a->value,'label'=>$a->label(),'abbreviation'=>$a->abbreviation()],\App\Enum\Ability::cases())];
    }

    private function subclassVisible(CharacterSubclass $item, User $actor): bool
    {
        $parent=$item->getCharacterClass();
        return ReferenceVisibility::allows($parent,$actor) && ($parent->getOrigin()===ReferenceOrigin::Official || ($item->getOrigin()===ReferenceOrigin::Custom && $parent->getOwner()?->getId()===$item->getOwner()?->getId()));
    }

    private function editorView(object $item, User $actor, bool $official): array
    {
        $data=$this->serialize($item);
        if ($item instanceof CharacterSubclass) $data+=['classId'=>$item->getCharacterClass()->getId(),'className'=>$item->getCharacterClass()->getName()];
        return $data+['origin'=>$item->getOrigin()->value,'custom'=>$item->getOrigin()===ReferenceOrigin::Custom,'editable'=>$official?$item->getOrigin()===ReferenceOrigin::Official:$item->getOrigin()===ReferenceOrigin::Custom && $item->getOwner()?->getId()===$actor->getId(),'deletable'=>false];
    }

    public function saveEditor(string $category, ?int $id, array $data, User $actor, bool $official): array
    {
        if (!in_array($category,['classes','subclasses','feats','progressions'],true)) throw new NotFoundHttpException();
        if ($official) $this->requireReferenceAdmin($actor);
        $this->checkFields($category,$data,$id===null);
        return $this->em->wrapInTransaction(function() use ($category,$id,$data,$actor,$official) {
            if ($id===null) {
                $slug=$this->requiredString($data,'slug',80); $name=$this->requiredString($data,'name',120); $this->uniqueSlug($category,$slug);
                $item=match($category) {
                    'classes'=>new CharacterClass($slug,$name,$this->integer($data,'hitDie',6),$this->integer($data,'subclassSelectionLevel',3)),
                    'subclasses'=>new CharacterSubclass($this->visibleClass($this->integer($data,'characterClassId'),$actor),$slug,$name),
                    'feats'=>new Feat($slug,$name),
                    'progressions'=>new ProgressionDefinition($slug,$name),
                };
                if (!$official) $item->markAsPersonalReference($actor);
            } else {
                $item=$this->em->find(self::TYPES[$category],$id,\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                if (!$item || !ReferenceVisibility::allows($item,$actor) || ($official && $item->getOrigin()!==ReferenceOrigin::Official)) throw new NotFoundHttpException('Référence indisponible.');
                if (!$official && $item->getOrigin()!==ReferenceOrigin::Custom) throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Référence officielle en lecture seule.');
                if (array_key_exists('characterClassId',$data)) throw new ConflictHttpException('Classe parente immuable.');
                if ($this->hasReferences($item)) {
                    $current=$this->serialize($item);
                    foreach ($data as $field=>$value) if (!in_array($field,['name','description'],true) && ($current[$field]??null)!==$value) throw new ConflictHttpException('Mécaniques verrouillées : cette référence est utilisée.');
                }
            }
            if ($item instanceof CharacterSubclass && !$this->subclassVisible($item,$actor)) throw new BadRequestHttpException('Classe parente incompatible.');
            $this->apply($category,$item,$data,$actor,$id===null);
            if ($item instanceof ProgressionDefinition) $this->validateStages($item);
            $this->em->persist($item); $this->em->flush();
            return $this->editorView($item,$actor,$official);
        });
    }

    /** Conservative guard includes reference rules as well as character assignments. */
    private function hasReferences(object $item): bool
    {
        $target=$this->em->getClassMetadata($item::class)->name;
        $db=$this->em->getConnection();
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $metadata) {
            if ($item instanceof ProgressionDefinition && in_array($metadata->getTableName(),['progression_stage','progression_adjustment_rule'],true)) continue;
            foreach ($metadata->associationMappings as $association) {
                if ($association->targetEntity!==$target || !$association->isToOneOwningSide()) continue;
                foreach ($association->joinColumns as $column) {
                    if ($db->fetchOne('SELECT 1 FROM '.$db->quoteIdentifier($metadata->getTableName()).' WHERE '.$db->quoteIdentifier($column->name).' = ? LIMIT 1',[$item->getId()])) return true;
                }
            }
        }
        return false;
    }

    private function validateStages(ProgressionDefinition $definition): void
    {
        $stages=$definition->getStages()->toArray();
        foreach ($stages as $index=>$stage) {
            if ($stage->getMinimumValue()<$definition->getMinimumValue() || ($definition->getMaximumValue()!==null && ($stage->getMaximumValue()===null || $stage->getMaximumValue()>$definition->getMaximumValue()))) throw new BadRequestHttpException('Palier hors des bornes de la progression.');
            foreach (array_slice($stages,$index+1) as $other) if ($stage->getMinimumValue()<=($other->getMaximumValue()??PHP_INT_MAX) && $other->getMinimumValue()<=($stage->getMaximumValue()??PHP_INT_MAX)) throw new BadRequestHttpException('Les paliers se chevauchent.');
        }
    }

    public function progressionChild(int $id, string $kind, ?int $childId, array $data, User $actor, bool $official, bool $delete=false): array
    {
        if ($official) $this->requireReferenceAdmin($actor);
        $stage=$kind==='stages';
        if (!$stage && $kind!=='adjustment-rules') throw new NotFoundHttpException();
        $fields=$stage?['label','description','minimumValue','maximumValue','iconUrl','displayOrder']:['direction','triggerType','description','adjustmentLabel','displayOrder'];
        if (array_diff(array_keys($data),$fields)) throw new BadRequestHttpException('Champ inconnu.');
        return $this->em->wrapInTransaction(function() use ($id,$kind,$childId,$data,$actor,$official,$delete,$stage) {
            $definition=$this->em->find(ProgressionDefinition::class,$id,\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            if (!$definition || !ReferenceVisibility::allows($definition,$actor) || ($official && $definition->getOrigin()!==ReferenceOrigin::Official)) throw new NotFoundHttpException('Progression indisponible.');
            if (!$official && $definition->getOrigin()!==ReferenceOrigin::Custom) throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Progression officielle en lecture seule.');
            if ($stage && $this->hasReferences($definition)) throw new ConflictHttpException('Les paliers de cette progression utilisée sont verrouillés.');
            $type=$stage?\App\Entity\ProgressionStage::class:\App\Entity\ProgressionAdjustmentRule::class;
            $child=$childId===null?null:$this->em->find($type,$childId);
            if ($childId!==null && (!$child || $child->getProgressionDefinition()->getId()!==$id)) throw new NotFoundHttpException('Relation indisponible.');
            if ($delete) {
                if (!$child) throw new NotFoundHttpException();
                if ($stage) $definition->removeStage($child); else $definition->removeAdjustmentRule($child);
                $this->em->remove($child);
            } elseif ($stage) {
                $min=array_key_exists('minimumValue',$data)?$this->integer($data,'minimumValue'):($child?->getMinimumValue()??throw new BadRequestHttpException('Minimum requis.'));
                $max=array_key_exists('maximumValue',$data)?($data['maximumValue']===null?null:$this->integer($data,'maximumValue')):$child?->getMaximumValue();
                if ($max!==null && $max<$min) throw new BadRequestHttpException('Intervalle invalide.');
                $child??=new \App\Entity\ProgressionStage($definition,$this->requiredString($data,'label',120),$min);
                $child->setMaximumValue(null)->setMinimumValue($min)->setMaximumValue($max);
                if (array_key_exists('label',$data)) $child->setLabel($this->requiredString($data,'label',120));
                foreach (['description'=>'setDescription','iconUrl'=>'setIconUrl'] as $field=>$method) if (array_key_exists($field,$data)) {
                    if ($data[$field]!==null && !is_string($data[$field])) throw new BadRequestHttpException('Texte attendu.');
                    if ($field==='iconUrl' && $data[$field]!==null && mb_strlen($data[$field])>255) throw new BadRequestHttpException('URL trop longue.');
                    $child->$method($data[$field]);
                }
                if (array_key_exists('displayOrder',$data)) $child->setDisplayOrder($this->integer($data,'displayOrder'));
                $definition->addStage($child); $this->validateStages($definition); $this->em->persist($child);
            } else {
                $direction=$child?->getDirection();
                if (array_key_exists('direction',$data)) $direction=is_string($data['direction'])?\App\Enum\ProgressionAdjustmentDirection::tryFrom($data['direction']):null;
                if ($direction===null) throw new BadRequestHttpException('Direction invalide.');
                $child??=new \App\Entity\ProgressionAdjustmentRule($definition,$direction,$this->requiredString($data,'description',10000),$this->requiredString($data,'adjustmentLabel',120));
                $child->setDirection($direction);
                foreach (['description'=>'setDescription','adjustmentLabel'=>'setAdjustmentLabel'] as $field=>$method) if (array_key_exists($field,$data)) $child->$method($this->requiredString($data,$field,$field==='description'?10000:120));
                if (array_key_exists('triggerType',$data)) {
                    if ($data['triggerType']!==null && !is_string($data['triggerType'])) throw new BadRequestHttpException('Déclencheur invalide.');
                    $child->setTriggerType($data['triggerType']);
                }
                if (array_key_exists('displayOrder',$data)) $child->setDisplayOrder($this->integer($data,'displayOrder'));
                $definition->addAdjustmentRule($child); $this->em->persist($child);
            }
            $this->em->flush();
            return $this->editorView($definition,$actor,$official);
        });
    }

    /** Complete race editor contract, shared with personal catalogue mutations. */
    public function races(User $actor, bool $official = false): array
    {
        if ($official) $this->requireReferenceAdmin($actor);
        $query=$this->em->getRepository(CharacterRace::class)->createQueryBuilder('r')->where('r.origin = :official')->setParameter('official',ReferenceOrigin::Official);
        if (!$official) $query->orWhere('r.origin = :custom AND r.owner = :owner')->setParameter('custom',ReferenceOrigin::Custom)->setParameter('owner',$actor);
        $races=[];
        foreach ($query->orderBy('r.name','ASC')->addOrderBy('r.id','ASC')->getQuery()->getResult() as $race) {
            if ($this->raceDependenciesVisible($race,$actor)) $races[]=$this->raceView($race,$actor,$official);
        }
        return ['races'=>$races,'abilities'=>array_map(static fn ($ability)=>['value'=>$ability->value,'label'=>$ability->label(),'abbreviation'=>$ability->abbreviation()],\App\Enum\Ability::cases())];
    }

    private function requireReferenceAdmin(User $actor): void
    {
        if (!in_array(SpellCatalogue::ADMIN_ROLE,$actor->getRoles(),true)) throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Administration officielle requise.');
    }

    private function raceDependenciesVisible(CharacterRace $race, User $actor): bool
    {
        $seen=[]; $current=$race;
        while ($current!==null) {
            if (isset($seen[$current->getId()]) || !ReferenceVisibility::allows($current,$actor)) return false;
            if ($race->getOrigin()===ReferenceOrigin::Official && $current->getOrigin()!==ReferenceOrigin::Official) return false;
            if ($current->getOrigin()===ReferenceOrigin::Custom && $current->getOwner()?->getId()!==$race->getOwner()?->getId()) return false;
            $seen[$current->getId()]=true; $current=$current->getParentRace();
        }
        return true;
    }

    public function saveRace(?int $id, array $data, User $actor, bool $official = false): array
    {
        if ($official) $this->requireReferenceAdmin($actor);
        $this->checkFields('races',$data,$id===null);
        return $this->em->wrapInTransaction(function () use ($id,$data,$actor,$official) {
            if ($id===null) {
                $slug=$this->requiredString($data,'slug',80); $this->uniqueSlug('races',$slug);
                $race=new CharacterRace($slug,$this->requiredString($data,'name',120));
                if (!$official) $race->markAsPersonalReference($actor);
            } else {
                $race=$this->em->find(CharacterRace::class,$id,\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                if (!$race || !$this->raceDependenciesVisible($race,$actor)) throw new NotFoundHttpException('Race indisponible.');
                if ($official && $race->getOrigin()!==ReferenceOrigin::Official) throw new NotFoundHttpException('Race officielle introuvable.');
                if (!$official && $race->getOrigin()!==ReferenceOrigin::Custom) throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Référence officielle en lecture seule.');
                if (array_key_exists('parentRaceId',$data)) throw new ConflictHttpException('Le parent reste immuable après création.');
                if ($this->raceUsed($race)) {
                    $current=$this->serialize($race);
                    // JSON numbers do not distinguish 9 from 9.0; an unchanged speed is not an edit.
                    foreach ($data as $field=>$value) if (!in_array($field,['name','description'],true) && json_encode($current[$field]??null,JSON_THROW_ON_ERROR)!==json_encode($value,JSON_THROW_ON_ERROR)) throw new ConflictHttpException('Les mécaniques d’une race utilisée sont verrouillées.');
                }
            }
            $this->apply('races',$race,$data,$actor,$id===null);
            if ($race->getParentRace()!==null && $official && $race->getParentRace()->getOrigin()!==ReferenceOrigin::Official) throw new BadRequestHttpException('Une race officielle ne dépend pas d’un CUSTOM.');
            if (array_key_exists('abilityModifiers',$data)) {
                if ($id!==null) throw new BadRequestHttpException('Utiliser les opérations de bonus identifiés.');
                if (!is_array($data['abilityModifiers']) || !array_is_list($data['abilityModifiers']) || count($data['abilityModifiers'])>20) throw new BadRequestHttpException('Liste de bonus invalide.');
                $keys=[];
                foreach ($data['abilityModifiers'] as $modifier) {
                    $modifier=$this->raceModifier($race,$modifier);
                    if ($modifier->getChoiceKey()!==null && isset($keys[$modifier->getChoiceKey()])) throw new BadRequestHttpException('Clé de choix dupliquée.');
                    if ($modifier->getChoiceKey()!==null) $keys[$modifier->getChoiceKey()]=true;
                    $race->addAbilityModifier($modifier);
                }
            }
            $this->em->persist($race); $this->em->flush();
            return $this->raceView($race,$actor,$official);
        });
    }

    private function raceModifier(CharacterRace $race, mixed $data): RaceAbilityModifier
    {
        if ($data instanceof \stdClass) $data=(array)$data;
        if (!is_array($data) || array_diff(array_keys($data),['ability','value','choiceKey']) || !is_int($data['value']??null)
            || !array_key_exists('ability',$data) || ($data['ability']!==null && !is_string($data['ability']))
            || (isset($data['choiceKey']) && (!is_string($data['choiceKey']) || mb_strlen($data['choiceKey'])>80))) throw new BadRequestHttpException('Bonus invalide.');
        $ability=$data['ability']===null?null:\App\Enum\Ability::tryFrom($data['ability']);
        if ($data['ability']!==null && $ability===null) throw new BadRequestHttpException('Caractéristique invalide.');
        return new RaceAbilityModifier($race,$data['value'],$ability,$data['choiceKey']??null);
    }

    private function raceUsed(CharacterRace $race): bool
    {
        return (bool)$this->em->getConnection()->fetchOne('WITH RECURSIVE descendants AS (SELECT id FROM character_race WHERE id=? UNION SELECT r.id FROM character_race r JOIN descendants d ON r.parent_race_id=d.id) SELECT 1 FROM character c JOIN descendants d ON c.race_id=d.id LIMIT 1',[$race->getId()]);
    }

    public function addRaceModifier(int $id, array $data, User $actor, bool $official=false): array
    {
        if ($official) $this->requireReferenceAdmin($actor);
        return $this->em->wrapInTransaction(function () use ($id,$data,$actor,$official) {
            $race=$this->em->find(CharacterRace::class,$id,\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            if (!$race || !$this->raceDependenciesVisible($race,$actor) || ($official && $race->getOrigin()!==ReferenceOrigin::Official)) throw new NotFoundHttpException('Race indisponible.');
            if (!$official && $race->getOrigin()!==ReferenceOrigin::Custom) throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Référence officielle en lecture seule.');
            if ($this->raceUsed($race)) throw new ConflictHttpException('Les bonus d’une race utilisée sont verrouillés.');
            $modifier=$this->raceModifier($race,$data);
            foreach ($race->getAbilityModifiers() as $existing) if ($modifier->getChoiceKey()!==null && $existing->getChoiceKey()===$modifier->getChoiceKey()) throw new ConflictHttpException('Clé de choix déjà utilisée.');
            $race->addAbilityModifier($modifier); $this->em->flush();
            return $this->raceView($race,$actor,$official);
        });
    }

    private function raceView(CharacterRace $race, User $actor, bool $official): array
    {
        $modifier=static fn (RaceAbilityModifier $m)=>['id'=>$m->getId(),'sourceRaceId'=>$m->getRace()->getId(),'sourceRaceName'=>$m->getRace()->getName(),'ability'=>$m->getAbility()?->value,'abilityLabel'=>$m->getAbility()?->label(),'abilityAbbreviation'=>$m->getAbility()?->abbreviation(),'value'=>$m->getValue(),'choiceKey'=>$m->getChoiceKey(),'requiresChoice'=>$m->requiresChoice()];
        $data=$this->serialize($race);
        $metadata=array_intersect_key($data,array_flip(['sizeOptions','walkingSpeed','movementSpeeds','languages','languageChoiceCount','senses','damageResistances','damageImmunities','conditionImmunities']));
        return $data+['origin'=>$race->getOrigin()->value,'custom'=>$race->getOrigin()===ReferenceOrigin::Custom,'editable'=>$official?$race->getOrigin()===ReferenceOrigin::Official:$race->getOrigin()===ReferenceOrigin::Custom && $race->getOwner()?->getId()===$actor->getId(),'deletable'=>false,
            'parentRace'=>$race->getParentRace()===null?null:['id'=>$race->getParentRace()->getId(),'name'=>$race->getParentRace()->getName()],
            'metadata'=>$metadata,'effectiveMetadata'=>(new CharacterRaceMetadataResolver())->resolve($race),'inheritedFeatChoiceCount'=>$race->getInheritedFeatChoiceCount(),
            'abilityModifiers'=>array_map($modifier,$race->getAbilityModifiers()->toArray()),'inheritedAbilityModifiers'=>array_map($modifier,$race->getInheritedAbilityModifiers())];
    }

    public function list(string $category, User $owner): array
    {
        $type = self::TYPES[$category];
        $items = $this->em->getRepository($type)->findBy(['origin' => ReferenceOrigin::Custom, 'owner' => $owner], ['name' => 'ASC']);
        return array_map(fn (object $item) => $this->serialize($item), $items);
    }

    public function get(string $category, int $id, User $owner): array
    {
        return $this->serialize($this->owned($category, $id, $owner));
    }

    public function create(string $category, array $data, User $owner): array
    {
        if ($category==='races') return $this->saveRace(null,$data,$owner);
        if (in_array($category,['classes','subclasses','feats','progressions'],true)) return $this->saveEditor($category,null,$data,$owner,false);
        $this->checkFields($category, $data, true);
        $slug = $this->requiredString($data, 'slug', 80);
        $name = $this->requiredString($data, 'name', 120);
        $this->uniqueSlug($category, $slug);
        $item = match ($category) {
            'classes' => new CharacterClass($slug, $name, $this->integer($data, 'hitDie', 6), $this->integer($data, 'subclassSelectionLevel', 3)),
            'subclasses' => new CharacterSubclass($this->visibleClass($this->integer($data, 'characterClassId'), $owner), $slug, $name),
            'races' => new CharacterRace($slug, $name),
            'feats' => new Feat($slug, $name),
            'progressions' => new ProgressionDefinition($slug, $name),
        };
        $item->markAsPersonalReference($owner);
        $this->apply($category, $item, $data, $owner, true);
        $this->em->persist($item);
        $this->em->flush();
        return $this->serialize($item);
    }

    public function patch(string $category, int $id, array $data, User $owner): array
    {
        if ($category==='races') return $this->saveRace($id,$data,$owner);
        if (in_array($category,['classes','subclasses','feats','progressions'],true)) return $this->saveEditor($category,$id,$data,$owner,false);
        $item = $this->owned($category, $id, $owner);
        $this->checkFields($category, $data, false);
        // Slug and structural parents are immutable after creation: other rules may refer to them.
        if (array_key_exists('slug', $data) || array_key_exists('characterClassId', $data) || array_key_exists('parentRaceId', $data)) {
            throw new ConflictHttpException('Identifiant et parent ne peuvent pas être modifiés après création.');
        }
        $this->apply($category, $item, $data, $owner, false);
        $this->em->flush();
        return $this->serialize($item);
    }

    private function owned(string $category, int $id, User $owner): object
    {
        $item = $this->em->getRepository(self::TYPES[$category])->findOneBy(['id' => $id, 'origin' => ReferenceOrigin::Custom, 'owner' => $owner]);
        if (!$item) throw new NotFoundHttpException('Référence personnelle introuvable.');
        return $item;
    }

    private function visibleClass(int $id, User $owner): CharacterClass
    {
        $class = $this->em->find(CharacterClass::class, $id);
        if (!$class || !ReferenceVisibility::allows($class, $owner)) throw new BadRequestHttpException('Classe inaccessible.');
        return $class;
    }

    private function uniqueSlug(string $category, string $slug): void
    {
        if ($this->em->getRepository(self::TYPES[$category])->findOneBy(['slug' => strtolower(trim($slug))])) {
            throw new ConflictHttpException('Ce slug existe déjà.');
        }
    }

    private function checkFields(string $category, array $data, bool $creating): void
    {
        $common = ['name', 'description'];
        $specific = match ($category) {
            'classes' => ['hitDie', 'subclassSelectionLevel', 'spellcastingProgression'],
            'subclasses' => ['characterClassId', 'spellcastingProgression'],
            'races' => ['parentRaceId', 'selectable', 'sizeOptions', 'walkingSpeed', 'movementSpeeds', 'languages', 'languageChoiceCount', 'senses', 'damageResistances', 'damageImmunities', 'conditionImmunities', 'featChoiceCount', ...($creating?['abilityModifiers']:[])],
            'feats' => ['repeatable', 'requiresAbilityChoice', 'chosenAbilityIncrease', 'allowedAbilities'],
            'progressions' => ['minimumValue', 'maximumValue', 'accentColor', 'gainLabel', 'spendLabel', 'bulkAdjustmentEnabled'],
        };
        $allowed = array_merge($common, $specific, $creating ? ['slug'] : []);
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $allowed, true)) throw new BadRequestHttpException('Champ inconnu ou non modifiable : '.$key);
        }
    }

    private function apply(string $category, object $item, array $data, User $owner, bool $creating): void
    {
        if (array_key_exists('name', $data)) $item->setName($this->requiredString($data, 'name', 120));
        if (array_key_exists('description', $data)) {
            if ($data['description'] !== null && !is_string($data['description'])) throw new BadRequestHttpException('Description invalide.');
            $item->setDescription($data['description']);
        }
        if (in_array($category, ['classes', 'subclasses'], true)) {
            if (array_key_exists('spellcastingProgression', $data)) {
                if ($category==='subclasses' && $data['spellcastingProgression']===null) {
                    $item->setSpellcastingProgression(null);
                } else {
                if (!is_string($data['spellcastingProgression'])) throw new BadRequestHttpException('Progression magique invalide.');
                $progression = SpellcastingProgressionType::tryFrom($data['spellcastingProgression']);
                if ($progression === null) throw new BadRequestHttpException('Progression magique inconnue.');
                $item->setSpellcastingProgression($progression);
                }
            }
        }
        if ($category === 'classes') {
            if (array_key_exists('hitDie', $data)) $item->setHitDie($this->integer($data, 'hitDie'));
            if (array_key_exists('subclassSelectionLevel', $data)) $item->setSubclassSelectionLevel($this->integer($data, 'subclassSelectionLevel'));
        }
        if ($category === 'races') {
            if ($creating && array_key_exists('parentRaceId', $data) && $data['parentRaceId'] !== null) {
                $parentId = $this->integer($data, 'parentRaceId');
                $parent = $this->em->find(CharacterRace::class, $parentId);
                if (!$parent || !$this->raceDependenciesVisible($parent, $owner)) throw new BadRequestHttpException('Race parente inaccessible.');
                $item->setParentRace($parent);
            }
            foreach (['selectable' => 'setSelectable'] as $field => $method) {
                if (array_key_exists($field, $data)) {
                    if (!is_bool($data[$field])) throw new BadRequestHttpException('Valeur booléenne attendue : '.$field);
                    $item->$method($data[$field]);
                }
            }
            foreach (['sizeOptions'=>'setSizeOptions', 'movementSpeeds'=>'setMovementSpeeds', 'languages'=>'setLanguages', 'senses'=>'setSenses', 'damageResistances'=>'setDamageResistances', 'damageImmunities'=>'setDamageImmunities', 'conditionImmunities'=>'setConditionImmunities'] as $field => $method) {
                if (array_key_exists($field, $data)) {
                    if ($data[$field] !== null && !is_array($data[$field])) throw new BadRequestHttpException('Liste invalide : '.$field);
                    $item->$method($data[$field]);
                }
            }
            if (array_key_exists('walkingSpeed', $data)) {
                if ($data['walkingSpeed'] !== null && !is_numeric($data['walkingSpeed'])) throw new BadRequestHttpException('Vitesse invalide.');
                $item->setWalkingSpeed($data['walkingSpeed'] === null ? null : (float)$data['walkingSpeed']);
            }
            if (array_key_exists('languageChoiceCount', $data)) $item->setLanguageChoiceCount($data['languageChoiceCount'] === null ? null : $this->integer($data, 'languageChoiceCount'));
            if (array_key_exists('featChoiceCount', $data)) $item->setFeatChoiceCount($this->integer($data, 'featChoiceCount'));
        }
        if ($category === 'feats') {
            if (array_key_exists('requiresAbilityChoice',$data)) {
                if (!is_bool($data['requiresAbilityChoice'])) throw new BadRequestHttpException('Booléen attendu : requiresAbilityChoice');
                $item->setRequiresAbilityChoice($data['requiresAbilityChoice']);
            }
            if (array_key_exists('allowedAbilities',$data)) {
                if (!is_array($data['allowedAbilities']) || !array_is_list($data['allowedAbilities'])) throw new BadRequestHttpException('Liste de caractéristiques attendue.');
                $abilities=[];
                foreach ($data['allowedAbilities'] as $value) {
                    if (!is_string($value) || !($ability=\App\Enum\Ability::tryFrom($value)) || isset($abilities[$value])) throw new BadRequestHttpException('Caractéristique invalide ou dupliquée.');
                    $abilities[$value]=$ability;
                }
                if ($abilities!==[] && !$item->requiresAbilityChoice()) throw new BadRequestHttpException('Un choix de caractéristique est nécessaire.');
                $item->setAllowedAbilities(...array_values($abilities));
            }
            foreach (['repeatable'=>'setRepeatable'] as $field => $method) {
                if (array_key_exists($field, $data)) {
                    if (!is_bool($data[$field])) throw new BadRequestHttpException('Booléen attendu : '.$field);
                    $item->$method($data[$field]);
                }
            }
            if (array_key_exists('chosenAbilityIncrease', $data)) $item->setChosenAbilityIncrease($this->integer($data, 'chosenAbilityIncrease'));
        }
        if ($category === 'progressions') {
            // Validate the final interval before applying either endpoint.
            $min=array_key_exists('minimumValue',$data)?$this->integer($data,'minimumValue'):$item->getMinimumValue();
            $max=array_key_exists('maximumValue',$data)?($data['maximumValue']===null?null:$this->integer($data,'maximumValue')):$item->getMaximumValue();
            if ($max!==null && $max<$min) throw new BadRequestHttpException('Intervalle invalide.');
            $item->setMaximumValue(null)->setMinimumValue($min)->setMaximumValue($max);
            foreach (['minimumValue'=>'setMinimumValue', 'maximumValue'=>'setMaximumValue'] as $field => $method) {
                if (array_key_exists($field, $data)) $item->$method($data[$field] === null && $field === 'maximumValue' ? null : $this->integer($data, $field));
            }
            foreach (['accentColor'=>'setAccentColor', 'gainLabel'=>'setGainLabel', 'spendLabel'=>'setSpendLabel'] as $field => $method) {
                if (array_key_exists($field, $data)) {
                    if ($data[$field] !== null && !is_string($data[$field])) throw new BadRequestHttpException('Texte invalide : '.$field);
                    $item->$method($data[$field]);
                }
            }
            if (array_key_exists('bulkAdjustmentEnabled', $data)) {
                if (!is_bool($data['bulkAdjustmentEnabled'])) throw new BadRequestHttpException('Booléen attendu.');
                $item->setBulkAdjustmentEnabled($data['bulkAdjustmentEnabled']);
            }
        }
    }

    private function integer(array $data, string $field, ?int $default = null): int
    {
        if (!array_key_exists($field, $data) && $default !== null) return $default;
        if (!array_key_exists($field, $data) || !is_int($data[$field])) throw new BadRequestHttpException('Entier attendu : '.$field);
        return $data[$field];
    }

    private function requiredString(array $data, string $field, int $limit): string
    {
        $value = $data[$field] ?? null;
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $limit) throw new BadRequestHttpException('Champ invalide : '.$field);
        return trim($value);
    }

    private function serialize(object $item): array
    {
        $out = ['id'=>$item->getId(), 'slug'=>$item->getSlug(), 'name'=>$item->getName(), 'description'=>$item->getDescription()];
        if ($item instanceof CharacterClass) $out += ['hitDie'=>$item->getHitDie(), 'subclassSelectionLevel'=>$item->getSubclassSelectionLevel(), 'spellcastingProgression'=>$item->getSpellcastingProgression()->value];
        if ($item instanceof CharacterSubclass) $out += ['characterClassId'=>$item->getCharacterClass()->getId(), 'spellcastingProgression'=>$item->getSpellcastingProgression()?->value];
        if ($item instanceof CharacterRace) $out += ['parentRaceId'=>$item->getParentRace()?->getId(), 'selectable'=>$item->isSelectable(), 'featChoiceCount'=>$item->getFeatChoiceCount(), 'sizeOptions'=>$item->getSizeOptions(), 'walkingSpeed'=>$item->getWalkingSpeed(), 'movementSpeeds'=>$item->getMovementSpeeds(), 'languages'=>$item->getLanguages(), 'languageChoiceCount'=>$item->getLanguageChoiceCount(), 'senses'=>$item->getSenses(), 'damageResistances'=>$item->getDamageResistances(), 'damageImmunities'=>$item->getDamageImmunities(), 'conditionImmunities'=>$item->getConditionImmunities()];
        if ($item instanceof Feat) $out += ['repeatable'=>$item->isRepeatable(), 'requiresAbilityChoice'=>$item->requiresAbilityChoice(), 'chosenAbilityIncrease'=>$item->getChosenAbilityIncrease(), 'allowedAbilities'=>array_map(static fn($a)=>$a->value,$item->getAllowedAbilities())];
        if ($item instanceof ProgressionDefinition) $out += ['minimumValue'=>$item->getMinimumValue(), 'maximumValue'=>$item->getMaximumValue(), 'accentColor'=>$item->getAccentColor(), 'gainLabel'=>$item->getGainLabel(), 'spendLabel'=>$item->getSpendLabel(), 'bulkAdjustmentEnabled'=>$item->isBulkAdjustmentEnabled(),
            'stages'=>array_map(static fn($s)=>['id'=>$s->getId(),'label'=>$s->getLabel(),'description'=>$s->getDescription(),'minimumValue'=>$s->getMinimumValue(),'maximumValue'=>$s->getMaximumValue(),'iconUrl'=>$s->getIconUrl(),'displayOrder'=>$s->getDisplayOrder()],$item->getStages()->toArray()),
            'adjustmentRules'=>array_map(static fn($r)=>['id'=>$r->getId(),'direction'=>$r->getDirection()->value,'triggerType'=>$r->getTriggerType(),'description'=>$r->getDescription(),'adjustmentLabel'=>$r->getAdjustmentLabel(),'displayOrder'=>$r->getDisplayOrder()],$item->getAdjustmentRules()->toArray())];
        return $out;
    }
}
