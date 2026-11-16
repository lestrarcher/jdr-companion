<?php
declare(strict_types=1);
namespace App\Tests\Service;
use App\Controller\MagicItemWeaponProfileController;
use App\Entity\MagicItem;
use App\Enum\ReferenceOrigin;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class MagicItemWeaponProfileControllerTest extends TestCase
{
    private function controller(bool $allowed): MagicItemWeaponProfileController
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->with('ROLE_REFERENCE_ADMIN')->willReturn($allowed);
        $container = new Container(); $container->set('security.authorization_checker',$auth);
        $controller = new MagicItemWeaponProfileController(); $controller->setContainer($container); return $controller;
    }
    public function testReferenceConfigurationAndValidation(): void
    {
        $item = new MagicItem(null,'Test',null,null,ReferenceOrigin::Official);
        $item->classify('weapon',false,true,'hand','one');
        $em = $this->createMock(EntityManagerInterface::class); $em->method('find')->willReturn($item);
        $em->expects(self::once())->method('flush');
        $profile = json_decode(file_get_contents(__DIR__.'/../../data/weapon-profiles-2014.json'),true)['phb2014-weapon-dagger'];
        $request = fn ($value) => Request::create('/','PUT',server:['CONTENT_TYPE'=>'application/json'],content:json_encode(['weaponProfile'=>$value]));
        $controller = $this->controller(true);
        self::assertSame(200,$controller->reference(1,$request($profile),$em)->getStatusCode());
        self::assertSame($profile,$item->getWeaponProfile());
        self::assertSame(422,$controller->reference(1,$request(['dice'=>'invented']),$em)->getStatusCode());
        self::assertSame($profile,$item->getWeaponProfile());
    }
    public function testPlayerCannotConfigureReferences(): void
    {
        $em = $this->createMock(EntityManagerInterface::class); $em->expects(self::never())->method('find');
        $this->expectException(AccessDeniedException::class);
        $this->controller(false)->reference(1,Request::create('/','PUT'),$em);
    }
}
