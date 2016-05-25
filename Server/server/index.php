<?php



require $_SERVER["DOCUMENT_ROOT"].'/libs/Websocket/WebsocketServer.php';



$config = array(
    'pid' => 'out/websocket_pid.txt',
    'websocket' => 'tcp://0.0.0.0:8001',
    'log' => 'out/websocket_log.txt'
);


$websocketserver = new WebsocketServer($config);

$websocketserver->Stop();
$websocketserver->Start();




?>