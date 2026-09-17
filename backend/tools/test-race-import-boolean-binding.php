<?php

declare(strict_types=1);

// Run: docker compose exec -T backend php tools/test-race-import-boolean-binding.php

use App\Kernel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

require __DIR__.'/../vendor/autoload.php';

$kernel = new Kernel('dev', true);
$kernel->boot();
/** @var Connection $connection */
$connection = $kernel->getContainer()->get('doctrine')->getConnection();
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
};

$connection->beginTransaction();
try {
    $connection->executeStatement('CREATE TEMPORARY TABLE race_boolean_binding_test (id INT PRIMARY KEY, selectable BOOLEAN NOT NULL) ON COMMIT DROP');
    $true = true;
    $false = false;
    $check(get_debug_type($true) === 'bool' && get_debug_type($false) === 'bool', 'Catalogue booleans must remain PHP bool values.');
    $connection->insert('race_boolean_binding_test', ['id' => 1, 'selectable' => $true], ['selectable' => ParameterType::BOOLEAN]);
    $connection->insert('race_boolean_binding_test', ['id' => 2, 'selectable' => $false], ['selectable' => ParameterType::BOOLEAN]);
    $check($connection->fetchOne('SELECT selectable FROM race_boolean_binding_test WHERE id = 1') === true, 'selectable=true must be stored as true.');
    $check($connection->fetchOne('SELECT selectable FROM race_boolean_binding_test WHERE id = 2') === false, 'selectable=false must be stored as false, not an empty string.');
    $connection->update('race_boolean_binding_test', ['selectable' => $false], ['id' => 1], ['selectable' => ParameterType::BOOLEAN]);
    $check($connection->fetchOne('SELECT selectable FROM race_boolean_binding_test WHERE id = 1') === false, 'A planned true to false update must store false.');
} finally {
    $connection->rollBack();
    $kernel->shutdown();
}

echo "OK ($checks checks)\n";
