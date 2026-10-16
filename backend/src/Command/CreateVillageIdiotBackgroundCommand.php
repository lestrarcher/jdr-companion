<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\{CharacterBackground, ProficiencyDefinition, ProficiencyGrantRule, User};
use App\Enum\{ProficiencyAcquisitionMode, ProficiencyType, ReferenceOrigin};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:dnd:create-village-idiot',
    description: 'Crée l’historique privé Idiot du village et ses maîtrises fixes.'
)]
final class CreateVillageIdiotBackgroundCommand extends Command
{
    private const SLUG = 'idiot-du-village';
    private const NAME = 'Idiot du village';
    private const KEY = 'custom-idiot-du-village-skills';

    private const DESCRIPTION = <<<'TEXT'
Simple d'esprit avéré ou acteur de génie, vous êtes une figure emblématique dans votre village. En toutes circonstances, vos actions et réactions sont totalement inattendues. Ces facéties amusent ou agacent les gens qui les subissent, mais dans tous les cas, ils vous en excusent.

Vous passez la plupart de votre temps à errer sans but évident, à interpeller les passants avec des exclamations incompréhensibles, à courir après les oiseaux avec un bâton ou à surgir derrière des buissons pour faire peur aux promeneurs avant de vous éloigner en riant.

Bref, vous êtes l'idiot du village !

Source : Gobelin de bronze 2015.

Aptitude — Pitoyable :
La plupart des gens vous considèrent comme inoffensif et ne vous prêtent guère attention. Vous pouvez circuler dans de nombreux endroits sans éveiller immédiatement la méfiance. Lorsque vous commettez une bêtise ou vous trouvez dans un endroit interdit, les habitants ont tendance à se montrer plus indulgents. Cette aptitude est purement narrative.

Outil : un jeu simple dont vous seul semblez connaître les règles (jeu du caillou, par exemple).
Langue : Gnééééé, composée de sons et d'onomatopées.
Équipement : bâton, sifflet, vêtements communs rapiécés, bourse de jolis cailloux et un objet au choix valant au maximum 5 po.
TEXT;

    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('owner-email', InputArgument::REQUIRED, 'Adresse e-mail du MJ propriétaire')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Valider sans écrire');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $run = function () use ($input, $io, $dryRun): void {
                $em = $this->entityManager;

                $owner = $em->getRepository(User::class)->findOneBy([
                    'email' => trim((string) $input->getArgument('owner-email')),
                ]);

                if (!$owner) {
                    throw new \DomainException('Compte MJ introuvable.');
                }

                $skills = [];

                foreach (['stealth', 'performance'] as $slug) {
                    $skill = $em->getRepository(ProficiencyDefinition::class)
                        ->findOneBy(['slug' => $slug]);

                    if (!$skill
                        || $skill->getType() !== ProficiencyType::Skill
                        || $skill->getOrigin() !== ReferenceOrigin::Official
                        || $skill->getOwner() !== null
                    ) {
                        throw new \DomainException(
                            'Compétence officielle manquante ou incompatible : '.$slug
                        );
                    }

                    $skills[] = $skill;
                }

                $background = $em->getRepository(CharacterBackground::class)
                    ->findOneBy(['slug' => self::SLUG]);

                $newBackground = $background === null;

                if ($background && (
                    $background->getOrigin() !== ReferenceOrigin::Custom
                    || $background->getOwner()?->getId() !== $owner->getId()
                    || $background->getName() !== self::NAME
                    || $background->getDescription() !== self::DESCRIPTION
                )) {
                    throw new \DomainException(
                        'Historique existant différent ou appartenant à un autre propriétaire.'
                    );
                }

                $background ??= CharacterBackground::forOwner(
                    $owner,
                    self::SLUG,
                    self::NAME
                )->setDescription(self::DESCRIPTION);

                $rule = $em->getRepository(ProficiencyGrantRule::class)
                    ->findOneBy(['importKey' => self::KEY]);

                $newRule = $rule === null;

                if ($rule) {
                    $slugs = array_map(
                        static fn (ProficiencyDefinition $option) => $option->getSlug(),
                        $rule->getOptions()->toArray()
                    );
                    sort($slugs);

                    if (
                        $newBackground
                        || $rule->getCharacterBackground()?->getId() !== $background->getId()
                        || $rule->getOrigin() !== ReferenceOrigin::Custom
                        || $rule->getOwner()?->getId() !== $owner->getId()
                        || $rule->getUnlockLevel() !== 1
                        || $rule->getChoiceCount() !== 0
                        || $rule->getAcquisitionMode() !== ProficiencyAcquisitionMode::Any
                        || $slugs !== ['performance', 'stealth']
                    ) {
                        throw new \DomainException('Règle existante incompatible.');
                    }
                }

                if (!$newBackground) {
                    foreach (
                        $em->getRepository(ProficiencyGrantRule::class)
                            ->findBy(['characterBackground' => $background])
                        as $existing
                    ) {
                        if ($existing !== $rule) {
                            throw new \DomainException(
                                'Autre règle de maîtrise déjà liée à cet historique.'
                            );
                        }
                    }
                }

                if ($newRule) {
                    $rule = ProficiencyGrantRule::forOwner(
                        $owner,
                        self::NAME.' : Discrétion et Représentation'
                    )
                        ->setImportKey(self::KEY)
                        ->setCharacterBackground($background);

                    foreach ($skills as $skill) {
                        $rule->addOption($skill);
                    }

                    $rule->validateRule();
                }

                $io->writeln('Propriétaire : '.$owner->getEmail());
                $io->writeln(($newBackground ? 'create' : 'unchanged').' : '.self::NAME);
                $io->writeln(($newRule ? 'create' : 'unchanged').' : maîtrises fixes');

                if (!$dryRun) {
                    if ($newBackground) {
                        $em->persist($background);
                    }

                    if ($newRule) {
                        $em->persist($rule);
                    }
                }
            };

            if ($dryRun) {
                $run();
            } else {
                $this->entityManager->wrapInTransaction(function () use ($run): void {
                    $this->entityManager->getConnection()->executeStatement(
                        'LOCK TABLE character_background, proficiency_grant_rule, proficiency_grant_rule_option IN SHARE ROW EXCLUSIVE MODE'
                    );

                    $run();
                });
            }

            $io->success(
                $dryRun
                    ? 'Simulation terminée : aucune écriture.'
                    : 'Historique Idiot du village créé ou déjà présent.'
            );

            return Command::SUCCESS;
        } catch (\Throwable $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }
    }
}
