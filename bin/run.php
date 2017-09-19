#!/usr/bin/env php
<?php

if (!$loader = include __DIR__ . '/../vendor/autoload.php') {
    die('You must set up the project dependencies.');
}

defined('DS') || define('DS', DIRECTORY_SEPARATOR);
defined('APPLICATION_PATH') || define('APPLICATION_PATH', realpath(dirname(__FILE__) . '/..'));

$version = '0.1';
$app = new \Cilex\Application('TwitterSentiment', $version);

$config = include __DIR__ . '/../config/settings.php';

$app['application.config'] = $config;

$app['debug'] = $config['debug'];

$app->command(new \Cilex\Command\ConnectCommand());
$app->command(new \Cilex\Command\GreetCommand());
$app->command(new \Cilex\Command\DemoInfoCommand());

$app->command('foo', function ($input, $output) {
    $output->writeln('Example output');
});

$app->run();
