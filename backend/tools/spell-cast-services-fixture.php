<?php
declare(strict_types=1);
use App\Service as S;
use App\Repository as R;
require_once __DIR__.'/training-presenter-fixture.php';
function spellConcurrencyServices($registry): array {
    $em=$registry->getManager(); $db=$em->getConnection();
    $ability=new S\CharacterAbilityCalculator();
    $features=new S\CharacterFeatureResolver(new R\CharacterFeatureRuleRepository($registry));
    $resources=new S\CharacterResourceResolver(new R\TrackableResourceRuleRepository($registry),$ability,$features,$em);
    $slots=new S\CharacterSpellSlotCalculator(); $slotState=new S\CharacterSpellSlotStateService($slots);
    $catalogue=new S\SpellCatalogue($db);
    $access=new S\CharacterSpellAccessResolver($catalogue,new S\SpellSubclassGrants($db,$catalogue),$resources);
    $acquisitions=new S\CharacterSpellAcquisitions($em,$access,$ability);
    $directory=new S\PlayerSpellDirectory($db,$em,new S\CharacterFeatureSpellService($db,$features,$ability),$ability,
        $access,$acquisitions,new S\CharacterSpellPreparation($acquisitions,$ability),$catalogue,
        new S\CharacterSpellAvailabilityContext($slots,$slotState,$resources,new R\TrackableResourceDefinitionRepository($registry)));
    $executor=new S\CharacterSpellCastExecutor($em,new S\CharacterSpellCastCommandValidator($directory), new S\CharacterSpellHitPointEffects($em, new S\CharacterHitPointBoostService(new S\CharacterHitPointStateService(new S\CharacterHitPointCalculator($ability)),new R\CharacterActiveEffectRepository($registry),$em)));
    $hp=new S\CharacterHitPointCalculator($ability); $hpState=new S\CharacterHitPointStateService($hp);
    $states=new R\CharacterSessionStateRepository($registry);
    $sync=new S\CharacterSessionStateSynchronizer($hp,$hpState,$resources,$states,$slotState);
    $profile=new S\CharacterProfileSerializer($ability,$features,$resources,$hp,$slots,
        new S\CharacterActionResolver(new R\CharacterActionClassRuleRepository($registry)),trainingPresenterForRegistry($registry));
    $serializer=new S\CharacterSessionStateSerializer($profile,$hpState,new R\CharacterActiveEffectRepository($registry),$slotState,$directory);
    $controller=new App\Controller\CharacterSessionStateController($profile,$sync,$serializer);
    $controller->setContainer(new Symfony\Component\DependencyInjection\Container());
    return compact('executor','controller','states','sync','hpState','catalogue','directory');
}
