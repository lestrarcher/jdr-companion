<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\{Beast, BeastHabitat, BeastNaturalWeapon, CharacterFeatureDefinition};
use App\Repository\BeastRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, ConflictHttpException};

/** Validates the whole request before changing the managed aggregate. */
final readonly class BeastEditor
{
    public function __construct(private EntityManagerInterface $em, private BeastRepository $beasts) {}

    public function save(Beast $beast, array $data): void
    {
        $slug = $this->text($data['slug'] ?? null, 'Slug', 120);
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)) $this->invalid('Slug invalide.');
        if ($beast->getId() !== null && $slug !== $beast->getSlug()) $this->invalid('Le slug existant est stable pour préserver les références et les imports. Modifiez le nom affiché.');
        $duplicate = $this->beasts->findOneBy(['slug' => $slug]);
        if ($duplicate && $duplicate !== $beast) throw new ConflictHttpException('Ce slug existe déjà.');
        $name = $this->text($data['name'] ?? null, 'Nom', 160);
        $cr = $data['challengeRating'] ?? null;
        if ($cr !== null && (!is_string($cr) || strlen($cr) > 12 || !preg_match('/^(0|[1-9][0-9]*|1\/[248])$/D', $cr))) $this->invalid('FP invalide.');
        $url = $data['sourceUrl'] ?? null;
        if ($url !== null && (!is_string($url) || strlen($url) > 300 || !preg_match('~^https?://~', $url) || !filter_var($url, FILTER_VALIDATE_URL))) $this->invalid('URL source invalide.');
        $block = $beast->getStatBlock();
        if (array_key_exists('statBlock', $data)) {
            if ($data['statBlock'] === null) $block = null;
            else {
                if (!is_array($data['statBlock']) || array_is_list($data['statBlock'])) $this->invalid('Statistiques invalides.');
                // Unknown imported metadata survives edits to supported fields.
                $block = array_replace($block ?? [], $data['statBlock']);
                $this->validateStats($block);
            }
        }
        $habitats = $data['habitats'] ?? array_map(fn ($h) => $h->getHabitat(), $beast->getHabitats()->toArray());
        $this->list($habitats, 'Habitats', 10);
        foreach ($habitats as $habitat) if (!is_string($habitat) || !in_array($habitat, BeastHabitat::VALUES, true)) $this->invalid('Habitat inconnu.');
        if (count($habitats) !== count(array_unique($habitats))) $this->invalid('Habitats en double.');
        $definitions = null;
        if (array_key_exists('featureIds', $data)) $definitions = $this->definitions($beast, $data['featureIds']);
        $weapons = $data['naturalWeapons'] ?? null;
        $existing = [];
        foreach ($beast->getNaturalWeapons() as $weapon) $existing[$weapon->getId()] = $weapon;
        if ($weapons !== null) {
            $this->list($weapons, 'Armes naturelles', 100);
            $ids = [];
            foreach ($weapons as $weapon) {
                if (!is_array($weapon)) $this->invalid('Arme invalide.');
                $id = $weapon['id'] ?? null;
                if ($id !== null) {
                    if (!is_int($id) || !isset($existing[$id]) || isset($ids[$id])) $this->invalid('Arme inconnue, étrangère ou en double.');
                    $ids[$id] = true;
                }
                $this->text($weapon['name'] ?? null, 'Nom de l’arme', 160);
                if (!in_array($weapon['attackType'] ?? null, ['melee', 'ranged', 'special'], true)) $this->invalid('Type d’attaque invalide.');
                if (($weapon['attackBonus'] ?? null) !== null) $this->integer($weapon['attackBonus'], -30, 50, 'Bonus d’attaque');
                if (!is_string($weapon['description'] ?? null) || strlen($weapon['description']) > 30000) $this->invalid('Description invalide.');
                $damage = $weapon['damageComponents'] ?? null;
                $this->list($damage, 'Dégâts', 20);
                foreach ($damage as $component) {
                    if (!is_array($component) || !is_string($component['dice'] ?? null)
                        || !preg_match('/^(?:[1-9][0-9]*d[1-9][0-9]*(?:[+-][0-9]+)?|[0-9]+)$/D', $component['dice'])
                        || (($component['type'] ?? null) !== null && (!is_string($component['type']) || mb_strlen($component['type']) > 80))) $this->invalid('Composante de dégâts invalide.');
                }
            }
        }
        $beast->edit($slug, $name, $cr, $url, $block);
        $beast->replaceHabitats($habitats);
        if ($definitions !== null) $beast->replaceFeatures($definitions);
        if ($weapons !== null) {
            $kept = [];
            foreach ($weapons as $order => $values) {
                $weapon = isset($values['id']) ? $existing[$values['id']] : new BeastNaturalWeapon($beast);
                if (!isset($values['id'])) $beast->addNaturalWeapon($weapon);
                $weapon->edit(trim($values['name']), $values['attackType'], $values['attackBonus'] ?? null, $values['damageComponents'], $values['description'], $order);
                $kept[] = $weapon;
            }
            foreach ($existing as $weapon) if (!in_array($weapon, $kept, true)) $beast->removeNaturalWeapon($weapon);
        }
        $this->em->persist($beast);
        $this->em->flush();
    }

    public function replaceFeatures(Beast $beast, mixed $ids): void
    {
        $beast->replaceFeatures($this->definitions($beast, $ids));
        $this->em->flush();
    }
    private function definitions(Beast $beast, mixed $ids): array
    {
        $this->list($ids, 'Capacités', 200);
        $definitions = [];
        $existing = array_map(fn ($f) => $f->getDefinition()->getId(), $beast->getFeatures()->toArray());
        foreach ($ids as $id) {
            $this->integer($id, 1, PHP_INT_MAX, 'Identifiant de capacité');
            if (isset($definitions[$id])) $this->invalid('Capacités en double.');
            $definition = $this->em->find(CharacterFeatureDefinition::class, $id);
            if (!$definition || (!$definition->isVisible() && !in_array($id, $existing, true))) $this->invalid('Capacité inconnue ou masquée.');
            $definitions[$id] = $definition;
        }
        return array_values($definitions);
    }
    private function validateStats(array $block): void
    {
        $this->integer($block['armorClass'] ?? null, 1, 50, 'CA');
        $this->integer($block['hitPoints'] ?? null, 1, 100000, 'PV');
        if (!is_array($block['abilities'] ?? null) || !is_array($block['speed'] ?? null)) $this->invalid('Caractéristiques et vitesses requises.');
        foreach (['str','dex','con','int','wis','cha'] as $key) $this->integer($block['abilities'][$key] ?? null, 1, 30, $key);
        foreach ($block['speed'] as $key => $value) {
            if (!in_array($key, ['walk','swim','fly','climb','burrow'], true) || !(is_int($value) || is_float($value)) || $value < 0 || $value > 10000) $this->invalid('Vitesse invalide.');
        }
        if (isset($block['hitDice']) && (!is_string($block['hitDice']) || !preg_match('/^[1-9][0-9]{0,2}d[1-9][0-9]{0,3}(?:[+-][0-9]{1,5})?$/D', $block['hitDice']))) $this->invalid('Dés de vie invalides.');
        foreach (['size', 'type'] as $key) if (isset($block[$key])) $this->text($block[$key], $key, 80);
        if (isset($block['type']) && !in_array(mb_strtolower($block['type']), ['beast', 'bête'], true)) $this->invalid('Ce référentiel est réservé aux bêtes.');
        if (isset($block['senses'])) {
            if (!is_array($block['senses'])) $this->invalid('Sens invalides.');
            foreach ($block['senses'] as $key => $value) if (!is_string($key) || strlen($key) > 80 || !(is_string($value) || is_int($value) || is_float($value))) $this->invalid('Sens invalides.');
        }
    }
    private function text(mixed $value, string $label, int $max): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $max) $this->invalid($label.' invalide.');
        return trim($value);
    }
    private function integer(mixed $value, int $min, int $max, string $label): void
    {
        if (!is_int($value) || $value < $min || $value > $max) $this->invalid($label.' invalide.');
    }
    private function list(mixed $value, string $label, int $max): void
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $max) $this->invalid($label.' : liste invalide.');
    }
    private function invalid(string $message): never { throw new BadRequestHttpException($message); }
}
