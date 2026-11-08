<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Controller\MagicItemArmorClassController;
use App\Entity\{MagicItem, User};
use App\Enum\ReferenceOrigin;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class MagicItemArmorClassControllerTest extends TestCase
{
    private function controller(bool $allowed, string $role): MagicItemArmorClassController
    {
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->expects(self::once())->method('isGranted')->with($role)->willReturn($allowed);
        $container = new Container();
        $container->set('security.authorization_checker', $authorization);
        $controller = new MagicItemArmorClassController();
        $controller->setContainer($container);
        return $controller;
    }

    public function testReferenceConfigurationAndInvalidPayloadAreValidated(): void
    {
        $item = new MagicItem(null, 'Fixture', null, null, ReferenceOrigin::Official);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($item);
        $em->expects(self::once())->method('flush');
        $effects = [['key' => 'fixture', 'label' => 'Fixture', 'type' => 'bonus', 'value' => 1]];
        $response = $this->controller(true, 'ROLE_REFERENCE_ADMIN')->reference(1,
            Request::create('/', 'PUT', content: json_encode(['armorClassEffects' => $effects])), $em);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($effects, $item->getArmorClassEffects());
        $response = $this->controller(true, 'ROLE_REFERENCE_ADMIN')->reference(1,
            Request::create('/', 'PUT', content: '{"armorClassEffects":[{"type":"guess-from-name"}]}'), $em);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame($effects, $item->getArmorClassEffects());
    }

    public function testReferenceAdminRequiredBeforeAnyLookupOrWrite(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('find');
        $em->expects(self::never())->method('flush');
        $this->expectException(AccessDeniedException::class);
        $this->controller(false, 'ROLE_REFERENCE_ADMIN')->reference(1, new Request(), $em);
    }

    public function testReferenceRouteCannotModifyPersonalItem(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn(new MagicItem(null, 'Fixture', null, $this->createMock(User::class)));
        $em->expects(self::never())->method('flush');
        $this->expectException(NotFoundHttpException::class);
        $this->controller(true, 'ROLE_REFERENCE_ADMIN')->reference(1, new Request(), $em);
    }

}
