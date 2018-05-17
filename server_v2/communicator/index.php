<?php

/**
 * This file used to start the WebsocketServer
 * pid - file where saved the PID of the linix-daemon on which Websocketserver running. Used to kill the previous daemon before to start the new daemo
 * websocket - string where you should specify the port of WebsocketServer
 * log - Used to save logs of WebsocketServer
 */
 
 
require dirname(__FILE__).'/WebsocketServer.php';

$config = array(
    'pid' => 'out/websocket_pid.txt',
    'websocket' => 'tcp://0.0.0.0:8001',
    'log' => 'out/websocket_log.txt'
);


$websocketserver = new WebsocketServer($config);

$websocketserver->Stop();
$websocketserver->Start();

?>