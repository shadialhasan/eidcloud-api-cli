<?php

declare(strict_types=1);

require_once __DIR__ . '/ApiCliTest.php';

use EidCloud\ApiCli\Tests\ApiCliTest;

$test = new ApiCliTest();
$success = $test->run();

exit($success ? 0 : 1);
