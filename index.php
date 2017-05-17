<?php

require_once dirname(__FILE__).'/vendor/autoload.php';

$file_name = dirname(__FILE__)."/config.ini";

$manager = new AsteriskManager($file_name);

if($manager->connect()){
    $peers = $manager->sendRequest('SIPpeers');
    print_r($peers);
}