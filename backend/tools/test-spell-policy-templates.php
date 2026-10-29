<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use App\Entity\CharacterClass;
use App\Enum\SpellcastingProgressionType;
use App\Service\SpellPolicyTemplateFactory;

$factory = new SpellPolicyTemplateFactory();

$wizard = new CharacterClass(
    'wizard-template-test',
    'Magicien de test',
    6,
    2,
    SpellcastingProgressionType::Full,
);

$checks = 0;

$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('ÉCHEC : '.$message);
    }

    ++$checks;
    echo "OK : {$message}\n";
};

$rule = static function (array $template, string $key): array {
    foreach ($template['rules'] as $entry) {
        if ($entry['key'] === $key) {
            return $entry;
        }
    }

    throw new RuntimeException('Règle absente : '.$key);
};

// Chevalier occulte : écoles et exceptions officielles.
$knight = $factory->restrictedThirdCaster(
    $wizard,
    'test-eldritch-knight',
    ['abjuration', 'evocation'],
);

$check(
    $rule($knight, 'restricted')['parameters']->values['schools']
        === ['abjuration', 'evocation'],
    'Chevalier occulte : écoles autorisées',
);

foreach ([3, 8, 14, 20] as $level) {
    $check(
        $rule($knight, 'unrestricted')['parameters']
            ->at('opportunities', $level) === 1,
        "Chevalier occulte : exception niveau {$level}",
    );
}

// Sous-classe maison : écoles et exceptions personnalisées.
$custom = $factory->restrictedThirdCaster(
    $wizard,
    'test-shadow-knight',
    ['illusion', 'necromancy'],
    [3, 7, 13, 19],
);

$check(
    $rule($custom, 'restricted')['parameters']->values['schools']
        === ['illusion', 'necromancy'],
    'Sous-classe maison : écoles personnalisées',
);

foreach ([3, 7, 13, 19] as $level) {
    $check(
        $rule($custom, 'unrestricted')['parameters']
            ->at('opportunities', $level) === 1,
        "Sous-classe maison : exception niveau {$level}",
    );
}

// Les budgets connus doivent rester identiques à la progression.
foreach ([$knight, $custom] as $template) {
    for ($level = 1; $level <= 20; ++$level) {
        $current = $template['policy']->atLevel($level);
        $expected = $template['policy']->additionsAtLevel(
            $level,
            'known',
        );

        $actual = $rule($template, 'restricted')['parameters']
            ->at('opportunities', $level)
            + $rule($template, 'unrestricted')['parameters']
                ->at('opportunities', $level);

        $check(
            $expected === $actual,
            "Budget de sorts niveau {$level} : {$template['policy']->key}",
        );

        $check(
            $current['known'] >= 0,
            "Quota cumulé valide niveau {$level}",
        );
    }
}

// Les configurations invalides doivent être refusées.
foreach ([
    ['invalid-school', ['unknown-school'], [3]],
    ['duplicate-school', ['illusion', 'illusion'], [3]],
    ['invalid-exception', ['illusion'], [2]],
    ['duplicate-exception', ['illusion'], [3, 3]],
] as [$key, $schools, $levels]) {
    try {
        $factory->restrictedThirdCaster(
            $wizard,
            $key,
            $schools,
            $levels,
        );

        throw new RuntimeException(
            "Configuration invalide acceptée : {$key}"
        );
    } catch (InvalidArgumentException) {
        $check(true, "Configuration invalide refusée : {$key}");
    }
}

echo "\n{$checks} vérifications réussies.\n";
