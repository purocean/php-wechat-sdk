<?php

require '../src/Qywx.php';

$config = require('./config.php');

$qywx = new Wxsdk\Qywx($config);

var_dump($qywx->getAccessToken());

$testMediaId = $config['testMediaId'];

var_dump($qywx->downloadMedia($testMediaId));
