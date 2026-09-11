<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class OptimisticConflictSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 20]];
    }

    public function onException(ExceptionEvent $event): void
    {
        if ($event->getThrowable() instanceof OptimisticLockException) {
            $event->setResponse(new JsonResponse([
                'message' => 'Le personnage a été modifié ailleurs. Rechargez son état avant de recommencer.',
            ], 409));
        }
    }
}
