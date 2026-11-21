<?php
declare(strict_types=1);
namespace App\Service;
use App\Entity\{Beast, BeastHabitat, BeastFeature, BeastNaturalWeapon};

final class BeastSerializer
{
    public function catalogue(Beast $beast): array
    {
        return ['id' => $beast->getId(), 'slug' => $beast->getSlug(), 'name' => $beast->getName(), 'origin' => $beast->getOrigin()->value, 'ownerId' => $beast->getOwner()?->getId(),
            'challengeRating' => $beast->getChallengeRating(), 'sourceUrl' => $beast->getSourceUrl(),
            'imageUrl' => $beast->getImageFilename() ? '/api/public/beasts/images/'.$beast->getImageFilename().'/thumbnail' : null,
            'statBlock' => $beast->getStatBlock(),
            'naturalWeapons' => array_values(array_map(fn (BeastNaturalWeapon $w) => $w->toArray(), $beast->getNaturalWeapons()->toArray())),
            'features' => array_values(array_map(fn (BeastFeature $f) => ['id' => $f->getDefinition()->getId(), 'name' => $f->getDefinition()->getName(), 'description' => $f->getDefinition()->getDescription()], array_filter($beast->getFeatures()->toArray(), fn (BeastFeature $f) => $f->getDefinition()->isVisible()))),
            'habitats' => array_values(array_map(fn (BeastHabitat $h) => $h->getHabitat(), $beast->getHabitats()->toArray()))];
    }
    public function detail(Beast $beast): array
    {
        return $this->catalogue($beast) + ['imageFilename' => $beast->getImageFilename(), 'customized' => $beast->isCustomized(),
            'featureIds' => array_values(array_map(fn (BeastFeature $f) => $f->getDefinition()->getId(), $beast->getFeatures()->toArray()))];
    }
}
