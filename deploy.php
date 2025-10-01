#!/usr/bin/env php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Command\DeployCommand;
use Symfony\Component\Console\Application;

$app = new Application('GitHub Deploy CLI', '1.0.0');
$app->add(new DeployCommand());
$app->run();
