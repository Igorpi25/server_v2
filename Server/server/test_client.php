<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'class.websocket_client.php';

$client = new WebsocketClient;

$header_json = array();

$header_json["last_timestamp"]=0;
$header_json["userid"]=2;


if(!$client->connect($header_json,'127.0.0.1', 8001,"/")){
	
	echo "<br><b>Not connected</b><br>";
}else{
	echo "<br><b>Connected</b><br>";
	
	for($i=0;$i<3;$i++){
		$message = fread($client->_Socket, 1024);//stream_get_line($this->_Socket, 1024, "\r\n\r\n");
		echo "<br><b>Message:</b><br>".implode(",",$client->_hybi10Decode($message));
	}	
	
	$client->disconnect();
	echo "<br><b>Disconnected</b><br>";
}
?>
