<?php

declare(strict_types=1);

use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Recreate the test DB schema fresh once per phpunit run. We don't use migrations here
// because the schema is fully derived from entity attributes, and SchemaTool is faster
// and avoids any drift between migrations and entities during development.
$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();
$schemaTool = new SchemaTool($em);
$metadata = $em->getMetadataFactory()->getAllMetadata();

if ([] !== $metadata) {
    $schemaTool->dropSchema($metadata);
    $schemaTool->createSchema($metadata);
}

$kernel->shutdown();
