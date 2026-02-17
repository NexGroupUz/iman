<?php
require_once __DIR__ . "/../src/Config.php";
require_once __DIR__ . "/../src/Db.php";
require_once __DIR__ . "/../src/TelephonyClient.php";
require_once __DIR__ . "/../src/QueueRepo.php";
require_once __DIR__ . "/../src/Worker.php";
require_once __DIR__ . "/../src/bootstrap.php";
loadDotEnv(__DIR__ . "/../.env");

date_default_timezone_set(Config::env("APP_TIMEZONE", "Asia/Tashkent"));

$tel = new TelephonyClient();
$db = new Db();
$w = new Worker($db, $tel);

$w->tick();
echo "ok\n";
