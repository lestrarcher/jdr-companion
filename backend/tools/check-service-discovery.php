<?php

declare(strict_types=1);

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

require dirname(__DIR__).'/vendor/autoload.php';

// Independent inventory: scandir does not rewind a partially consumed directory.
// This check neither boots the kernel nor connects to the database.
$root = dirname(__DIR__);
$files = [];
$directories = 0;
$walk = static function (string $path) use (&$walk, &$files, &$directories): void {
    $expected = array_values(array_diff(scandir($path), ['.', '..']));
    $actual = [];
    foreach (new DirectoryIterator($path) as $entry) {
        if (!$entry->isDot()) {
            $actual[] = $entry->getFilename();
        }
    }
    sort($expected);
    sort($actual);
    if ($expected !== $actual) {
        throw new RuntimeException('Incomplete directory iteration: '.$path);
    }
    ++$directories;
    foreach ($expected as $name) {
        $file = $path.'/'.$name;
        if (is_dir($file)) {
            $walk($file);
        } elseif (str_ends_with($name, '.php')) {
            $files[] = $file;
        }
    }
};
$walk($root.'/src');

$container = new ContainerBuilder();
$container->setParameter('kernel.project_dir', $root);
$loader = new YamlFileLoader($container, new FileLocator($root.'/config'));
$loader->load('services.yaml');
$checked = 0;
foreach ($files as $file) {
    $relative = substr($file, strlen($root.'/src/'));
    if (!preg_match('~^(Service|Command|Controller|Repository|Security)/~', $relative)) {
        continue;
    }
    $class = 'App\\'.str_replace('/', '\\', substr($relative, 0, -4));
    $reflection = new ReflectionClass($class);
    if (!$reflection->isInstantiable()) {
        continue;
    }
    if (!$container->hasDefinition($class)) {
        throw new RuntimeException('Service not discovered: '.$class);
    }
    ++$checked;
}

printf("OK: %d directories, %d PHP files, %d application services discovered.\n", $directories, count($files), $checked);
