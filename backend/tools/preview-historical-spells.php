<?php
declare(strict_types=1);

// Read-only diagnostic: IDs are explicit; no session lookup, transaction or mutation.
require __DIR__.'/../vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
$kernel = new App\Kernel('dev', false); $kernel->boot();
$registry = $kernel->getContainer()->get('doctrine'); $em = $registry->getManager();
$catalogue = new App\Service\SpellCatalogue($em->getConnection());
$resources = new App\Service\CharacterResourceResolver(new App\Repository\TrackableResourceRuleRepository($registry), new App\Service\CharacterAbilityCalculator(), new App\Service\CharacterFeatureResolver(new App\Repository\CharacterFeatureRuleRepository($registry)));
$service = new App\Service\CharacterSpellAcquisitions($em, new App\Service\CharacterSpellAccessResolver($catalogue, new App\Service\SpellSubclassGrants($em->getConnection(), $catalogue), $resources));
foreach (array_slice($argv, 1) as $id) {
    if (!ctype_digit($id)) throw new InvalidArgumentException('Positive character IDs required.');
    $character = $em->find(App\Entity\Character::class, (int)$id);
    if (!$character) throw new RuntimeException('Character not found.');
    $owner = $character->getCampaign()->getOwner();
    foreach ($service->status($character, $owner) as $status) {
        $summary = ['character'=>$character->getName(), 'class'=>$status['className'], 'status'=>$status['status']];
        if ($status['status'] === 'policy-configured') {
            $preview = $service->historicalPreview($character, $status['policyIds'][0], $owner); $totals = [];
            foreach ($preview['categories'] as $category) $totals[$category['label']] = $category['remaining'];
            $summary += ['policyId'=>$status['policyIds'][0], 'level'=>$preview['classLevel'], 'known'=>count($preview['expectedAcquisitionIds']), 'remaining'=>$totals, 'warning'=>$preview['warning']];
        }
        echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
    }
}
$kernel->shutdown();
