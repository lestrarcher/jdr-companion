<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Campaign;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Campaign>
 */
final class CampaignVoter extends Voter
{
    public const VIEW = 'CAMPAIGN_VIEW';
    public const MANAGE = 'CAMPAIGN_MANAGE';

    protected function supports(
        string $attribute,
        mixed $subject,
    ): bool {
        return
            $subject instanceof Campaign &&
            in_array(
                $attribute,
                [
                    self::VIEW,
                    self::MANAGE,
                ],
                true,
            );
    }

    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        TokenInterface $token,
    ): bool {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Campaign $campaign */
        $campaign = $subject;

        /*
         * On compare les identifiants plutôt que les objets
         * PHP, pour rester fiable avec les proxies Doctrine.
         */
        return
            $campaign->getOwner()->getId() ===
            $user->getId();
    }
}
