<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

/*
config: array
pid: string - file's name where will be saved pid(process id) 
websocket: string - tcp://[ip]:[port] ,example: tcp://127.0.0.1:8000
log: string - file's name to save logs
*/

//------------------Transport constants----------------------------
define("TRANSPORT_NOTIFICATION",100);
define("TRANSPORT_TEXT",1);
define("TRANSPORT_MAP",2);
define("TRANSPORT_PROFILE",3);

//------------------Map message constants ------------------------
define("OUTGOING_START_BROADCAST", 1);
define("OUTGOING_STOP_BROADCAST", 2);
define("OUTGOING_COORS", 3);
define("OUTGOING_CONFIRM_START_RECIEVE", 4);

define("INCOMING_START_RECIEVE", 1);
define("INCOMING_STOP_RECIEVE", 2);
define("INCOMING_COORS", 3);

define("RECIEVER_TYPE_FRIENDS",1);
define("RECIEVER_TYPE_ONE_USER",2);
define("RECIEVER_TYPE_GROUP",3);
define("RECIEVER_TYPE_ALL",4);

define("RECIEVER_RADIUS_MAX",300000);//In 300000 km radius

//-----------------Profile message constants (надо снизу перевести вот сюда )-----------------
define("OUTGOING_USERS_DELTA", 1);
define("OUTGOING_GROUPS_DELTA", 2);
define("OUTGOING_GROUP_USERS_DELTA", 3);

define("INCOMING_FRIEND_OPERATION", 1);
define("INCOMING_GROUP_OPERATION", 2);
define("INCOMING_ME_OPERATION", 3);

define("GROUPOPERATION_ADD_USERS", 0);
define("GROUPOPERATION_SAVE", 1);
define("GROUPOPERATION_CREATE", 2);
define("GROUPOPERATION_USER_STATUS", 4);

define("GROUPSTATUS_COMMON_USER", 0);
define("GROUPSTATUS_ADMIN_CREATER", 1);
define("GROUPSTATUS_ADMIN", 2);
define("GROUPSTATUS_BANNED", 3);
define("GROUPSTATUS_MISSING", 4);
define("GROUPSTATUS_LEAVE", 5);
define("GROUPSTATUS_REMOVED", 6);
define("GROUPSTATUS_NOT_IN_GROUP", 7);

//-------------------Console-----------------------------------------

define("CONSOLE_OPERATION_USER_CHANGED", 0);
define("CONSOLE_OPERATION_GROUP", 1);
define("CONSOLE_OPERATION_CHECK_SERVER", 2);
define("CONSOLE_OPERATION_USER_REGISTERED", 3);



class WebsocketServer {

public $map_userid_connect=array();//HashMap key : userid, value: connect
public $map_connectid_userid=array();//HashMap key : connectid, value: userid ($connectid=getIdByConnect($connect))
public $connects=array();

public $recievers=array();

public $db_chat,$db_profile,$db_map;

public function __construct($config) {
        $this->config = $config;
				
		require_once dirname(__FILE__)."/../include/DbHandlerChat.php";
		require_once dirname(__FILE__)."/../include/DbHandlerProfile.php";
		require_once dirname(__FILE__)."/../include/DbHandlerMap.php";
        
		require_once dirname(__FILE__)."/../include/Config.php";
		require_once dirname(__FILE__)."/../include/DbConnect.php";
         
		
		$mysqli = mysqli_init();
		if (!$mysqli) {
			die('mysqli_init failed');
		}
		
		if (!$mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 31536000)) {
			die('mysqli->options MYSQLI_OPT_CONNECT_TIMEOUT failed');
		}

		if (!$mysqli->real_connect(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME)) {
			die('mysqli->real_connect failed (' . mysqli_connect_errno() . ') '. mysqli_connect_error());
		}
         	
		//Set timezone
		DbConnect::setTimezone($mysqli);          
		
		$this->db_chat = new DbHandlerChat($mysqli);
		$this->db_profile = new DbHandlerProfile($mysqli);
		$this->db_map = new DbHandlerMap($mysqli);
}

public function Start(){

	/*$pid = @file_get_contents($this->config['pid']);
    if ($pid) {
        $this->log("Start. Failed. Another pid-file found pid=".$pid);
        die("Start. Failed. Another pid-file found pid=".$pid);
    }*/
       
	$socket = stream_socket_server($this->config['websocket'], $errno, $errstr);	
	if (!$socket) {
	    die("e1 $errstr ($errno)\n");
	}	
	file_put_contents($this->config['pid'], posix_getpid());
	
	$this->log("Start. Success. config=(".$this->config['websocket'].") pid=".posix_getpid());
		
	while (true) {
	    //формируем массив прослушиваемых сокетов:
	    $read = $this->connects;
	    $read []= $socket;
	    $write = $except = null;
	
	    if (!stream_select($read, $write, $except, null)) {//ожидаем сокеты доступные для чтения (без таймаута)
	        break;
	    }
	
	    if (in_array($socket, $read)) {//есть новое соединение
		
	        //принимаем новое соединение и производим рукопожатие:
	        if (($connect = stream_socket_accept($socket, -1)) && $info = $this->handshake($connect)) {
			    
				if( isset($info["console"]) ){//Локальная консольная команда
					
					$this->ProcessConsoleOperation($connect,$info);
					
				}else{//Обычный пользователь
									
					$userid=intval($info["userid"]);
					$last_timestamp=$info["last_timestamp"];
					
					//если есть другое соединение этого userid, то удаляем его и закрываем соккет
					if( array_key_exists(strval($userid), $this->map_userid_connect) ){
						$prev_connect=$this->getConnectByUserId($userid);
						
						$this->onClose($prev_connect);//вызываем пользовательский сценарий
						$this->removeConnect($prev_connect);
						stream_socket_shutdown($prev_connect, STREAM_SHUT_RDWR );//закрываем соккет и запрещаем прием и отдачу данных
											
						$this->log("Connect. Previous connection removed. connectid=".$this->getIdByConnect($prev_connect).", userid=".$userid);				
					}
					
					$this->log("Connect. Accepted. connectid=".$this->getIdByConnect($connect).", userid=".$userid.", last_timestamp=".$last_timestamp);
					$this->putConnect($connect,$userid);
					
					$this->onOpen($connect, $info);//вызываем пользовательский сценарий
				}
	        }
	        unset($read[ array_search($socket, $read) ]);
	    }
	
	    foreach($read as $connect) {//обрабатываем все соединения
	        $data = fread($connect, 100000);
	
	        if (!$data) { //соединение было закрыто
	            
				$this->log("Connect. Closed. connectid=".$this->getIdByConnect($connect).", userid=".$this->getUserIdByConnect($connect));	            
				
				$this->onClose($connect);//вызываем пользовательский сценарий
				
				$this->removeConnect($connect);
				
				fclose($connect);
	            
	            continue;
	        }
	
	        $this->OnMessage($connect,$this->connects, $data);
						
	    }
	}
	
	$this->log("close server");
	fclose($server);

}
    
public function Stop(){
	$pid = @file_get_contents($this->config['pid']);
        if ($pid) {
        	posix_kill($pid, 15);//SIGTERM=15
        	unlink($this->config['pid']);
            
        	$this->log("Stop. Success. pid=".$pid." Pid-file has been unlinked");           
        } else {
        	$this->log("Stop. Pid-file not found. pid=".$pid);
        }
}
     
//--------------------Функции протокола Profile (profile protocol methods)---------------------

protected function outgoingUsersDelta($connect,$timestamp){
	
    // Listing users have changed since $timestamp 
		
    $result = $this->db_profile->getUsersDelta($this->getUserIdByConnect($connect),$timestamp);
 	    
 	$json = array();
    $json["transport"]=TRANSPORT_PROFILE;
	$json["type"]=OUTGOING_USERS_DELTA;
	$json["last_timestamp"]=time();	
    $json["users"]=$result;
	
	$data_string=json_encode($json);
	
	fwrite($connect, $this->encode($data_string));	
	
    $this->log("outgoingUsersDelta. userid=".$this->getUserIdByConnect($connect)." connectId=".$this->getIdByConnect($connect).", json=".json_encode($json));        
}

protected function outgoingOneUser($connect,$userid){
	
    // Send one user info to $connect
		
    $one_user = $this->db_profile->getFriendById($this->getUserIdByConnect($connect),$userid);
 	    
 	$json = array();
    $json["transport"]=TRANSPORT_PROFILE;
	$json["type"]=OUTGOING_USERS_DELTA;
	$json["last_timestamp"]=time();	
    $json["users"]=array();
	$json["users"][]=$one_user;
	
	
	$data_string=json_encode($json);
		
	fwrite($connect, $this->encode($data_string));		
    $this->log("outgoingOneUser. userid=".$this->getUserIdByConnect($connect)." connectId=".$this->getIdByConnect($connect).", json=".json_encode($json));        
}

protected function outgoingNotifyFriends($userid){
	
    // Notify about user info changed
		
    $friends = $this->db_profile->getAllFriends($userid);
 	
	$this->log("<<outgoingNotifyFriends:");
	
	foreach($friends as $friend) {
		$friend_id=$friend["id"];
		if( array_key_exists(strval($friend_id), $this->map_userid_connect) ){
			$friend_connect=$this->getConnectByUserId($friend_id);
			
			$this->outgoingOneUser($friend_connect,$userid);
		}
	
	}
	$this->log(">>");
	
}

protected function outgoingNotifyGroupmates($groupid,$userid){
	
    // Notify about user info changed
		
	$this->log("<outgoingNotifyGroupmates groupid=$groupid userid=$userid:");
		
	$groupmates=$this->db_profile->getUsersInGroup($groupid);
	
	foreach($groupmates as $groupmate) {
		$groupmate_id=$groupmate["userid"];
		if( array_key_exists(strval($groupmate_id), $this->map_userid_connect) ){
			$groupmate_connect=$this->getConnectByUserId($groupmate_id);				
			$this->outgoingOneUser($groupmate_connect,$userid);
		}
	}
		
	
	$this->log(">");	
}

protected function outgoingNotifyAllGroupmates($userid){
	
    // Notify about user info changed
		
    $groups = $this->db_profile->getGroupsOfUser($userid);
 	
	$this->log("<<outgoingNotifyAllGroupmates. userid=$userid:");
	
	foreach($groups as $group) {
		$group_id=$group["id"];
		
		$this->outgoingNotifyGroupmates($group_id,$userid);
		
	}
	$this->log(">>");
	
}

protected function outgoingGroupsDelta($connect,$timestamp){
	
    // Listing group have changed since $timestamp 
		
    $result = $this->db_profile->getGroupsDelta($this->getUserIdByConnect($connect),$timestamp);
 	    
 	$json = array();
    $json["transport"]=TRANSPORT_PROFILE;
	$json["type"]=OUTGOING_GROUPS_DELTA;
	$json["last_timestamp"]=time();	
    $json["groups"]=$result;
	
	$data_string=json_encode($json);
	
	fwrite($connect, $this->encode($data_string));	
	
    $this->log("outgoingGroupsDelta. userid=".$this->getUserIdByConnect($connect)." connectId=".$this->getIdByConnect($connect).", json=".json_encode($json));        
}

protected function outgoingGroupUsersDelta($connect,$timestamp){
	
    // Listing group users have changed since $timestamp 
		
    $result = $this->db_profile->getGroupUsersDelta($this->getUserIdByConnect($connect),$timestamp);
 	
	// Check users in result before operation
	$this->outgoingUsersIfUnknown($connect,$result);
	
 	$json = array();
    $json["transport"]=TRANSPORT_PROFILE;
	$json["type"]=OUTGOING_GROUP_USERS_DELTA;
	$json["last_timestamp"]=time();	
    $json["group_users"]=$result;
	
	$data_string=json_encode($json);
	
	fwrite($connect, $this->encode($data_string));	
	
    $this->log("outgoingGroupUsersDelta. userid=".$this->getUserIdByConnect($connect)." connectId=".$this->getIdByConnect($connect).", json=".json_encode($json));        
}

protected function outgoingGroupmatesDelta($connect,$timestamp){
	
    // Listing groupmates info have changed since $timestamp 
		
    $result = $this->db_profile->getGroupmatesDelta($this->getUserIdByConnect($connect),$timestamp);
 	
 	$json = array();
    $json["transport"]=TRANSPORT_PROFILE;
	$json["type"]=OUTGOING_USERS_DELTA;
	$json["last_timestamp"]=time();	
    $json["users"]=$result;
	
	$data_string=json_encode($json);
	
	fwrite($connect, $this->encode($data_string));	
	
    $this->log("outgoingGroupmatesDelta. userid=".$this->getUserIdByConnect($connect)." connectId=".$this->getIdByConnect($connect).", json=".json_encode($json));        
}

protected function outgoingGroup($connect,$groupid){
	
    // Send one group	
	
	$groups = $this->db_profile->getGroupById($groupid);	
	
 	$json = array();
    $json["transport"]=TRANSPORT_PROFILE;
	$json["type"]=OUTGOING_GROUPS_DELTA;
	$json["last_timestamp"]=time();	
    $json["groups"]= $groups;
	
	
	$data_string=json_encode($json);
	
	fwrite($connect, $this->encode($data_string));	
	
    $this->log("outgoingGroupsDelta. userid=".$this->getUserIdByConnect($connect)." connectId=".$this->getIdByConnect($connect).", json=".json_encode($json));        
}

protected function outgoingGroupUsers($connect,$changed_users){
	
	// Check changed_users before operation
	$this->outgoingUsersIfUnknown($connect,$changed_users);
	
    // Send changed users of group	
 	$json = array();
    $json["transport"]=TRANSPORT_PROFILE;
	$json["type"]=OUTGOING_GROUP_USERS_DELTA;
	$json["last_timestamp"]=time();	
    $json["group_users"]=$changed_users;
	
	$data_string=json_encode($json);	
	fwrite($connect, $this->encode($data_string));	
	
    $this->log("outgoingGroupUsersDelta. userid=".$this->getUserIdByConnect($connect)." connectId=".$this->getIdByConnect($connect).", json=".json_encode($json));        
}

protected function outgoingUsersIfUnknown($connect,$users){
	// Send users which friend-status STATUS_DEFAULT:0
	
	$connect_userid=$this->getUserIdByConnect($connect);
	
	foreach($users as $user){		
		$status=$this->db_profile->getFriendStatus($connect_userid,$user["userid"]);
		if(($status!=3)||($status!=1)||($status!=2)){
			$this->outgoingOneUser($connect,$user["userid"]);
		}
	}
}

protected function outgoingNotifyGroupChanged($groupid){
	
    // Notify all users of group about group has been changed
		
    $users = $this->db_profile->getUsersInGroup($groupid);
 		
	$this->log("<<outgoingNotifyGroupChanged groupid=".$groupid." :");
	
	foreach($users as $user) {
		$user_id=$user["userid"];
		//If user connected then notify him
		if( array_key_exists(strval($user_id), $this->map_userid_connect) ){
			$connect=$this->getConnectByUserId($user_id);
			
			$this->outgoingGroup($connect,$groupid);
		}
	
	}
	$this->log(">>");
	
}

protected function outgoingNotifyGroupUsersChanged($groupid,$changed_users){	
    // Notify all users of group(groupid) about some users has been changed
			
    $users = $this->db_profile->getUsersInGroup($groupid);
 	
	$this->log("<<outgoingNotifyGroupUsers groupid=".$groupid." :");
	
	foreach($users as $user) {
		$user_id=$user["userid"];
		//If user connected then notify him
		if( array_key_exists(strval($user_id), $this->map_userid_connect) ){
			$connect=$this->getConnectByUserId($user_id);
			
			$this->outgoingGroupUsers($connect,$changed_users);
		}
	
	}
	$this->log(">>");
	
}

protected function groupOperationAddUsers($senderid,$groupid,$users){	
	
	$this->log("groupOperationAddUsers. senderid=".$senderid." groupid=".$groupid." users=".json_encode($users)); 												
	
    //Presently all user consists in group can do that operation. Status: 0,1,2
	$current_user_status=$this->db_profile->getUserStatusInGroup($groupid,$senderid);			
	if( !( ($current_user_status==0)||($current_user_status==1)||($current_user_status==2) ) ){					
		$this->log("groupOperationAddUsers. No permission. Sender not in group. senderid=".$senderid." groupid=".$groupid); 												
		return;
	}

	$status = 0;//Common user status
	$changed_at = time();
	
	$changed_users = array();
	
	foreach($users as $user){
		$userid=$user["id"];
		
		//You can add user to group only if this user is your friend
		$friend_status=$this->db_profile->getFriendStatus($senderid,$userid);
		if($friend_status!=3){
			$this->log("groupOperationAddUsers. No permission. User not friend of sender. senderid=".$senderid." userid=".$userid); 												
			continue;
		}
		
		$this->db_profile->addUserToGroup($groupid,$userid,$status);
		
		//Send group-info and group-users to new user
		if(array_key_exists(strval($userid), $this->map_userid_connect)) {
			
			$user_connect=$this->getConnectByUserId($userid);
		
			$this->outgoingGroup($user_connect, $groupid);			
			$group_users=$this->db_profile->getUsersInGroup($groupid);			
			
			//Send values to groupusers-table
			$this->outgoingGroupUsers($user_connect, $group_users);
			
			//Send info about users in group
			$this->outgoingUsersIfUnknown($user_connect,$group_users);
			
		}
		//Send new user-info to groupmates
		$this->outgoingNotifyGroupmates($groupid,$userid);
		
		$this->log("groupOperationAddUsers. User added. userid=".$userid." groupid=".$groupid);
		
		$new_user = array();
		$new_user["groupid"]=$groupid;
		$new_user["userid"]=$userid;
		$new_user["status_in_group"]=$status;
		$new_user["changed_at"]=$changed_at;
		
		$changed_users[]=$new_user;
	}
	
	$this->log("groupOperationAddUsers. Users added. added_users=".json_encode($changed_users));
						
	$this->outgoingNotifyGroupUsersChanged($groupid,$changed_users);
	
}

protected function groupOperationSave($senderid,$groupid,$json){	
		
    //Presently all user consists in group can do that operation. Status: 0,1,2
	$current_user_status=$this->db_profile->getUserStatusInGroup($groupid,$senderid);			
	if( !( ($current_user_status==0)||($current_user_status==1)||($current_user_status==2) ) ){					
		$this->log("INCOMING_GROUP_OPERATION. No permission. senderid=".$senderid." groupid=".$groupid." operationid=".$operationid); 												
		return;
	}
		
	if(isset($json["name"])){
		$name = $json["name"];
		$this->db_profile->changeGroupName($name,$groupid);	
	}
	
	$this->log("groupOperationSave. Group saved. groupid=".$groupid);
	
	$this->outgoingNotifyGroupChanged($groupid);
	
}

protected function groupOperationCreate($senderid){	
		    
	$groupid=$this->db_profile->createGroup($senderid);	
	
	$this->log("groupOperationCreate. Group created. senderid=".$senderid." groupid=".$groupid);
		
	$changed_users= json_decode('[{"userid":'.$senderid.', "groupid":'.$groupid.', "status_in_group":1, "changed_at": '.time().'}]',true);
		
	$this->outgoingNotifyGroupChanged($groupid);
	$this->outgoingNotifyGroupUsersChanged($groupid,$changed_users);
	
	return $groupid;
					
}

protected function groupOperationUserStatus($senderid,$userid,$groupid,$status){	
		    
	
	$sender_status=$this->db_profile->getUserStatusInGroup($groupid,$senderid);
	$user_status=$this->db_profile->getUserStatusInGroup($groupid,$userid);
	
	$count=0;
	
	switch($status){
		case GROUPSTATUS_LEAVE :
			if( (($sender_status==0)||($sender_status==1)||($sender_status==2)) &&($senderid==$userid) ){
				$count=$this->db_profile->changeUserStatusInGroup($groupid,$userid,$status);
			}
		break;
		
		case GROUPSTATUS_REMOVED :
			if( (($sender_status==1)||($sender_status==2)) && ($senderid!=$userid) && (($user_status==0)||($user_status==2)) ){
				$count=$this->db_profile->changeUserStatusInGroup($groupid,$userid,$status);
			}
		break;
		
		case GROUPSTATUS_ADMIN :
			if( (($sender_status==1)||($sender_status==2)) && ($senderid!=$userid) && (($user_status==0)) ){
				$count=$this->db_profile->changeUserStatusInGroup($groupid,$userid,$status);
			}
		break;
		
	}
	
	$this->log("groupOperationUserStatus. count=".$count.". senderid=".$senderid." userid=".$userid." groupid=".$groupid);
	
	if($count>0){		
		$changed_users= json_decode('[{"userid":'.$userid.', "groupid":'.$groupid.', "status_in_group":'.$status.', "changed_at": '.time().'}]',true);	
		$this->outgoingNotifyGroupUsersChanged($groupid,$changed_users);
	}

	return $count;
}

protected function ProcessMessageProfile($sender,$connects,$json) {
	
	$this->log("ProcessMessageProfile. Sender.connectId=".$this->getIdByConnect($sender).", Sender.userid=".$this->getUserIdByConnect($sender).", json=".json_encode($json));
	
	switch($json["type"]){
		case INCOMING_FRIEND_OPERATION :			
		
			// reading post params
			$userid = $this->getUserIdByConnect($sender);
			$friendid = $json["friendid"];
			$operationid = $json["operationid"];
	        
			$result=$this->db_profile->friendOperation($userid,$friendid,$operationid);
			
			if($result!=NULL){		
				
				$delivered=false;
				
				$this->outgoingOneUser($sender,$friendid);
					
				foreach($connects as $connect){
					if ($this->getUserIdByConnect($connect)==$friendid) {
						$this->outgoingOneUser($connect,$userid);						
						$delivered=true;						
						break;
					}		
				}
				if(!$delivered)	$this->log("outgoingOneUser. Offline. friendid=".$friendid." Message not delivered");  
			}
			
		break;
		
		case INCOMING_GROUP_OPERATION :			
		
			// reading post params
			$senderid = $this->getUserIdByConnect($sender);
			$operationid = $json["operationid"];
	        
			switch($operationid){
				case GROUPOPERATION_ADD_USERS :
					$users=$json["users"];
					$groupid = $json["groupid"];
					$this->groupOperationAddUsers($senderid,$groupid,$users);											
				break;
				
				case GROUPOPERATION_SAVE :	
					$groupid = $json["groupid"];		
					$this->groupOperationSave($senderid,$groupid,$json);										
				break;
				
				case GROUPOPERATION_CREATE :			
					$this->groupOperationCreate($senderid);
				break;
				
				case GROUPOPERATION_USER_STATUS :		
					$groupid = $json["groupid"];				
					$userid = $json["userid"];
					$status = $json["status"];
					$this->groupOperationUserStatus($senderid,$userid,$groupid,$status);
				break;
			}			
			
		break;
		
		case INCOMING_ME_OPERATION :			
		
			// reading post params
			$user_id = $this->getUserIdByConnect($sender);
			
			//Сохранение name
			if(isset($json["name"])){
				
				$name=$json["name"];
				
				$user_json = $this->db_profile->getUserById($user_id);
				$status=$user_json["status"];
			
				$this->db_profile->updateUser($user_id,$name,$status);
				
				$this->log("Sender:");  
				//Уведомляем отправителя об изменении name
				$this->outgoingOneUser($sender,$user_id);
				
				//Уведомляем друзей
				$this->outgoingNotifyFriends($user_id);
				$this->outgoingNotifyAllGroupmates($user_id);
				
			}
			
			
		break;
		
	}
		
		
	
}
	 
//--------------------Console operations-----------------------------------	 

protected function ProcessConsoleOperation($connect,$info) {
	
	$this->log("ProcessConsoleOperation. operation = ".$info["operation"]);
	
	switch($info["operation"]){
		case CONSOLE_OPERATION_USER_CHANGED:
			$userid=$info["userid"];
			
			$this->log("<<<CONSOLE_OPERATION_USER_CHANGED:");
			
			if( array_key_exists(strval($userid), $this->map_userid_connect) ){				
				$this->outgoingOneUser($this->getConnectByUserId($userid),$userid);					
			}
			
			$this->outgoingNotifyFriends($userid);	
			$this->outgoingNotifyAllGroupmates($userid);
			
			$this->log(">>>");				
			
			//Response to console client
			$response = array();
			$response["message"]="WebsocketServer. ProcessConsoleOperation CONSOLE_OPERATION_USER_CHANGED success userid=".$userid;					
			$data_string=json_encode($response);
			fwrite($connect, $this->encode($data_string));
		
		break;
				
		case CONSOLE_OPERATION_GROUP:
			
			$group_operationid=$info["group_operationid"];
			$senderid=$info["senderid"];
			
			switch($group_operationid){
				case GROUPOPERATION_ADD_USERS :
					$users=json_decode(stripslashes($info["users"]),true);
					$groupid=$info["groupid"];
					$this->groupOperationAddUsers($senderid,$groupid,$users);	

					//Response to console client					
					$response = array();
					$response["message"]="WebsocketServer. ProcessConsoleOperation CONSOLE_OPERATION_GROUP.GROUPOPERATION_ADD_USERS success groupid=".$groupid;					
					$data_string=json_encode($response);
					fwrite($connect, $this->encode($data_string));	
					
				break;
				
				case GROUPOPERATION_SAVE :	
					$json=json_decode($info["json"],true);
					$groupid=$info["groupid"];
					$this->groupOperationSave($senderid,$groupid,$json);
					
					//Response to console client					
					$response = array();
					$response["message"]="WebsocketServer. ProcessConsoleOperation CONSOLE_OPERATION_GROUP.GROUPOPERATION_SAVE success groupid=".$groupid;					
					$data_string=json_encode($response);
					fwrite($connect, $this->encode($data_string));
					
				break;
				
				case GROUPOPERATION_CREATE :
					$groupid=$this->groupOperationCreate($senderid);
					
					//Response to console client					
					$response = array();
					$response["groupid"]=$groupid;
					$response["message"]="WebsocketServer. ProcessConsoleOperation CONSOLE_OPERATION_GROUP.GROUPOPERATION_CREATE success groupid=".$groupid;					
					$data_string=json_encode($response);
					fwrite($connect, $this->encode($data_string));
					
				break;
			}	
			
		break;
		
		case CONSOLE_OPERATION_CHECK_SERVER:
			$userid=$info["userid"];
			
			$this->log("<<<CONSOLE_OPERATION_CHECK_SERVER:");
			$this->log("user_id=".$userid);				
			$this->log(">>>");				
			
			//Response to console client
			$response = array();
			$response["message"]="WebsocketServer. Server is running";					
			$response["status"]=1;
			$data_string=json_encode($response);
			fwrite($connect, $this->encode($data_string));
		
		break;
		
		case CONSOLE_OPERATION_USER_REGISTERED:
			$senderid=$info["senderid"];
			$sendername=$info["sendername"];
			$group_notification_id=$info["group_notification_id"];
			
			$this->log("<<<CONSOLE_OPERATION_USER_REGISTERED:");
			
			$this->outgoingNotifyFriends($senderid);	
			$this->outgoingNotifyAllGroupmates($senderid);
			
			$users_array=$this->db_profile->getUsersInGroup(2);//Все в ChatDemo Community
			
			
			
			foreach($users_array as $user){
				$status=$user["status_in_group"];
				$userid=$user["userid"];
				//Если одногрупник статус 0 или 1 или 2, и сейчас подключен
				if( (($status==0)||($status==1)||($status==2)) && (array_key_exists(strval($userid), $this->map_userid_connect)) ){
					
					$groupmate_connect=$this->getConnectByUserId($userid);
					
					//Одногрупник не сам отправитель
					if($userid!=$senderid){
						//Отправляем сообщение одногрупнику
						
						$data_string='{"transport":"'.TRANSPORT_NOTIFICATION.'","value":"'.$sendername.' joined the group","date":"'.time().'","group_id":"2","id":"'.$group_notification_id.'","sender":"'.$senderid.'", "last_timestamp":"'.time().'"}';
		
						fwrite($groupmate_connect, $this->encode($data_string));	
						$this->log("SendToDestination. Group. Destination.connectid=".$this->getIdByConnect($groupmate_connect).", Destination.userid=".$this->getUserIdByConnect($groupmate_connect)." Message-data: ".$data_string);
					}
				}		
			}
			
			$this->log(">>>");				
			
			//Response to console client
			$response = array();
			$response["message"]="WebsocketServer. ProcessConsoleOperation CONSOLE_OPERATION_USER_REGISTERED success senderid=".$senderid;					
			$response["status"]=1;
			$data_string=json_encode($response);
			fwrite($connect, $this->encode($data_string));
			
			error_log("WebsocketServer.Finished");
			
		break;
	}
	
}
	 
//--------------------Функции протокола чата (chat protocol methods)-----------------------

protected function ConfirmToSender($connect, $json) {
	
	$transport=TRANSPORT_TEXT;
	$message_id=$json["message_id"];
	$date=$json["date"];
	
	if(isset($json["interlocutor_id"])) {
		
		$interlocutor_id=$json["interlocutor_id"];	
		$string_data='{"transport":"'.$transport.'", "message_id":"'.$message_id.'", "interlocutor_id":"'.$interlocutor_id.'", "date":"'.$date.'", "last_timestamp":"'.time().'"}';
		fwrite($connect, $this->encode($string_data));
		$this->log("ConfirmToSender. Private. Sender.connectId=".$this->getIdByConnect($connect).", Sender.userid=".$this->getUserIdByConnect($connect).", client.message_id=".$message_id);
	}else 
	if(isset($json["group_id"])) {
		
		$group_id=$json["group_id"];	
		$id=$json["id"];//server id of group-message
		$string_data='{"transport":"'.$transport.'", "message_id":"'.$message_id.'", "id":"'.$id.'", "group_id":"'.$group_id.'", "date":"'.$date.'", "last_timestamp":"'.time().'"}';
		fwrite($connect, $this->encode($string_data));
		$this->log("ConfirmToSender. Group. Sender.connectId=".$this->getIdByConnect($connect).", Sender.userid=".$this->getUserIdByConnect($connect).", group_id=".$group_id.", client.message_id=".$message_id);
	}
		
}

protected function SendToDestination($connect, $json) {
	
	$transport=TRANSPORT_TEXT;
	$value=$json["value"];
	$date=$json["date"];
	
	if(isset($json["interlocutor_id"])) {
		
		$interlocutor_id=$json["interlocutor_id"];
		
		$data_string='{"transport":"'.$transport.'","value":"'.$value.'","date":"'.$date.'","interlocutor_id":"'.$interlocutor_id.'", "last_timestamp":"'.time().'"}';
		
		fwrite($connect, $this->encode($data_string));	
		$this->log("SendToDestination. Private. Destination.connectid=".$this->getIdByConnect($connect).", Destination.userid=".$this->getUserIdByConnect($connect)." Message-data: ".$data_string);
	}else
	if(isset($json["group_id"])) {
	
		
		$group_id=$json["group_id"];
		$id=$json["id"];//server id of group-message
		$sender=$json["sender"];
		
		$data_string='{"transport":"'.$transport.'","value":"'.$value.'","date":"'.$date.'","group_id":"'.$group_id.'","id":"'.$id.'","sender":"'.$sender.'", "last_timestamp":"'.time().'"}';
		
		fwrite($connect, $this->encode($data_string));	
		$this->log("SendToDestination. Group. Destination.connectid=".$this->getIdByConnect($connect).", Destination.userid=".$this->getUserIdByConnect($connect)." Message-data: ".$data_string);
	}
	
}

protected function ProcessMessageChat($sender,$connects,$json) {
	
	$json["date"]=time();
	
	if(array_key_exists("interlocutor_id",$json)){
		//Private message
		
		$destination_id=$json["interlocutor_id"];
		$json["interlocutor_id"]=$this->getUserIdByConnect($sender);
		
		$this->log("<<ChatMessage. Private. sender=".$this->getUserIdByConnect($sender)." destination=".$destination_id." transport=".$json["transport"]." value=".$json["value"]);
		
		//$delivered=false;
		
		foreach($connects as $connect){
			if($sender==$connect){			
				$this->ConfirmToSender($connect,$json);
			}else if ($this->getUserIdByConnect($connect)==$destination_id) {
				$this->SendToDestination($connect,$json);
				//$delivered=true; 
			}		
		}
		
		$this->log("Accumulate. sender=".$this->getUserIdByConnect($sender)." destination_id=".$destination_id." transport=".$json["transport"]." value=".$json["value"]);
		$this->db_chat->addMessagePrivate($this->getUserIdByConnect($sender),$destination_id,intval($json["transport"]),$json["value"]);
		
		/*//Если destination не найден, то сохраняем сообщение в БД, чтобы отправить потом
		if(!$delivered){	
			
			$this->log("Accumulate. sender=".$this->getUserIdByConnect($sender)." destination_id=".$destination_id." transport=".$json["transport"]." value=".$json["value"]);
			$this->db_chat->addMessagePrivate($this->getUserIdByConnect($sender),$destination_id,intval($json["transport"]),$json["value"]);
		}*/
		
		$this->log(">>");
	
	}else 
	if(array_key_exists("group_id",$json)){
		//Group message
		
		//Проверяем состоит ли отправитель в группе
		if(!$this->db_profile->isUserInGroup($json["group_id"],$this->getUserIdByConnect($sender)))return;
		
		$group_id=$json["group_id"];
		$json["sender"]=$this->getUserIdByConnect($sender);
				
		//Все групповые сообщения сохраняются в БД		
		$id=$this->db_chat->addMessageGroup($this->getUserIdByConnect($sender),$group_id,intval($json["transport"]),$json["value"]);
		$this->log("<<ChatMessage. Group. sender=".$this->getUserIdByConnect($sender)." group_id=".$group_id." transport=".$json["transport"]." value=".$json["value"]." id=".$id);
		
		//Это нужно для SendToDestination и ConfirmToSender
		$json["id"]=$id;
		
		//Готовим массив user-ов одногрупников
		$users_array=$this->db_profile->getUsersInGroup($group_id);
		
		foreach($users_array as $user){
			$status=$user["status_in_group"];
			$userid=$user["userid"];
			//Если одногрупник статус 0 или 1 или 2, и сейчас подключен
			if( (($status==0)||($status==1)||($status==2)) && (array_key_exists(strval($userid), $this->map_userid_connect)) ){
				
				$connect=$this->getConnectByUserId($userid);
				
				//Одногрупник - есть отправитель?
				if($connect==$sender){
					//Подтверждаем 
					$this->ConfirmToSender($connect,$json);
				}else {
					//Отправляем сообщение другому одногрупнику
					$this->SendToDestination($connect,$json);
				}
			}		
		}
		
		$this->log(">>");
		
		
	}
}

//--------------------Функции протокола карты (map protocol methods)-----------------------

protected function outgoingConfirmStartRecieve($connect) {
		
	$this->log("outgoingStopBroadcast. connectId=".$this->getIdByConnect($connect));
	
	$string_data='{"transport":"'.TRANSPORT_MAP.'", "type":"'.OUTGOING_CONFIRM_START_RECIEVE.'", "last_timestamp":"'.time().'"}';
	fwrite($connect, $this->encode($string_data));
	
		
}

protected function outgoingStartBroadcast($connect) {
	
	$this->log("outgoingStartBroadcast. connectId=".$this->getIdByConnect($connect));
	
	$string_data='{"transport":"'.TRANSPORT_MAP.'", "type":"'.OUTGOING_START_BROADCAST.'", "last_timestamp":"'.time().'"}';
	fwrite($connect, $this->encode($string_data));
	
		
}

protected function outgoingStopBroadcast($connect) {
	
	$this->log("outgoingStopBroadcast. connectId=".$this->getIdByConnect($connect));
	
	$string_data='{"transport":"'.TRANSPORT_MAP.'", "type":"'.OUTGOING_STOP_BROADCAST.'", "last_timestamp":"'.time().'"}';
	fwrite($connect, $this->encode($string_data));
	
		
}

protected function outgoingCoors($connect, $json) {
	$this->log("outgoingCoors. connectId=".$this->getIdByConnect($connect).", json=".json_encode($json));
	
	$json["transport"]=TRANSPORT_MAP;
	$json["type"]=OUTGOING_COORS;
	$json["last_timestamp"]=time();
	$data_string=json_encode($json);
	
	fwrite($connect, $this->encode($data_string));	
	
		
}

protected function putReciever($userid,$connect,$type,$latitude,$longitude,$radius,$clientid) {
	
	$this->log("putReciever. connectId=".$this->getIdByConnect($connect).", userid=".$userid.", type=".$type.", latitude=".$latitude.", longitude=".$longitude.", radius=".$radius," clientid=".$clientid);
	
	//Add to Recievers Array
	$reciever=array();
	
	$reciever["connect"]=$connect;
	$reciever["type"]=$type;
	$reciever["latitude"]=$latitude;
	$reciever["longitude"]=$longitude;
	$reciever["radius"]=$radius;
	$reciever["clientid"]=$clientid;//Used if type=RECIEVER_TYPE_ONE_USER and RECIEVER_TYPE_GROUP
	
	$this->recievers[strval($userid)]=$reciever;
	
}

protected function notifyTargetedUsers($userid,$type,$clientid){
	
	$this->log("notifyTargetedUsers. userid=".$userid.", type=".$type." clientid=".$clientid);
	
	//!!!!!!!!!!!!!Нужно добавить радиус действия!!!!!!!!!!!!!!!!!
	
	//Notify targeted users
	switch($type){
		case RECIEVER_TYPE_ALL :			
			foreach($this->connects as $conn){
				if($this->getUserIdByConnect($conn)!=$userid)
					$this->outgoingStartBroadcast($conn);
			}
		break;
		
		case RECIEVER_TYPE_FRIENDS :
			
			$friends=$this->db_profile->getAllFriends($userid);
			
			foreach($friends as $friend){
				if( isset( $this->map_userid_connect[strval($friend["id"])] ) ){
					$conn=$this->getConnectByUserId($friend["id"]);				
					$this->outgoingStartBroadcast($conn);
				}
			}
		break;
		
		case RECIEVER_TYPE_ONE_USER :
				if( isset( $this->map_userid_connect[strval($clientid)] ) ){
					$conn=$this->getConnectByUserId($clientid);				
					$this->outgoingStartBroadcast($conn);
				}
		break;
		
		case RECIEVER_TYPE_GROUP :
			$groupid=$clientid;
			
			$group_users=$this->db_profile->getUsersInGroup($groupid);
			
			foreach($group_users as $user){
				if( isset( $this->map_userid_connect[strval($user["userid"])] ) ){
					$conn=$this->getConnectByUserId($user["userid"]);				
					$this->outgoingStartBroadcast($conn);
				}
			}
		break;
	}
}

protected function removeReciever($userid) {	
	$this->log("removeReciever. userid=".$userid);
	
	if(isset($this->recievers[strval($userid)])){
		unset($this->recievers[strval($userid)]);	
	}
}

protected function resendCoorsToRecievers($sender,$sender_userid,$coors){
	$this->log("resendCoorsToRecievers. sender.connectId=".$this->getIdByConnect($sender).", sender_userid=".$sender_userid.", coors=".json_encode($coors));
	$resent_count=0;//Счетчик количества принявших ресиверов
	
	foreach($this->recievers as $reciever_userid=>$reciever){
		
		$this->log("resendCoorsToRecievers. reciever_userid=".$reciever_userid.", reciever.connectid=".$this->getIdByConnect($reciever["connect"]));
		
		//Предупреждаем чтобы ресивер сам себе не отправлял координаты
		if($reciever_userid==$sender_userid){
			$this->log("resendCoorsToRecievers. reciever_userid=sender_userid");
			$reciever["latitude"]=$coors["latitude"];
			$reciever["longitude"]=$coors["longitude"];
			$reciever["accuracy"]=$coors["accuracy"];			
			$reciever["provider"]=$coors["provider"];
			$resent_count++;
			continue;
		}
		
		//Если у ресивера установлен радиус действия и если отправитель находится вне радиуса, то пропускаем
		if( (isset($reciever["radius"]))&&($reciever["radius"]>0)&&(isset($reciever["latitude"]))&&(isset($reciever["longitude"])) ){
			if($this->distanceBetween($coors["latitude"],$coors["longitude"],$reciever["latitude"],$reciever["longitude"])>$reciever["radius"]){
				$this->log("resendCoorsToRecievers. Out of Radius");
				continue;
			}
		}
			
		//Notify targeted users
		switch($reciever["type"]){
			case RECIEVER_TYPE_ALL :	
				$this->log("resendCoorsToRecievers. RECIEVER_TYPE_ALL");				
				$this->outgoingCoors($reciever["connect"],$coors);
				$resent_count++;
			break;
			
			case RECIEVER_TYPE_FRIENDS :
				$this->log("resendCoorsToRecievers. RECIEVER_TYPE_FRIENDS");				
				if($this->db_profile->getFriendStatus($sender_userid,$reciever_userid)==3){
					$this->outgoingCoors($reciever["connect"],$coors);
					$resent_count++;
				}
			break;
			
			case RECIEVER_TYPE_ONE_USER :
				$this->log("resendCoorsToRecievers. RECIEVER_TYPE_ONE_USER");				
				if($reciever["clientid"]==$sender_userid){
					$this->outgoingCoors($reciever["connect"],$coors);
					$resent_count++;
				}
			break;
			
			case RECIEVER_TYPE_GROUP :
				$this->log("resendCoorsToRecievers. RECIEVER_TYPE_GROUP");								
				$groupid=$reciever["clientid"];				
				
				if($this->db_profile->isUserInGroup($groupid,$sender_userid)){
					$this->outgoingCoors($reciever["connect"],$coors);
					$resent_count++;
				}
				
			break;
		}
	}
	
	$this->log("resendCoorsToRecievers. resent_count=".$resent_count);
	
	return $resent_count;
}

private function distanceBetween($ax,$ay,$bx,$by){
	return sqrt( pow($ax-$bx,2)+pow($ay-$by,2) );
}

protected function ProcessMessageMap($sender,$connects,$json) {
	
	$this->log("ProcessMessageMap. Sender.connectId=".$this->getIdByConnect($sender).", Sender.userid=".$this->getUserIdByConnect($sender).", json=".json_encode($json));
	
	switch($json["type"]){
		case INCOMING_START_RECIEVE :			
			
			$user_location=$this->db_map->getUserLocation($this->getUserIdByConnect($sender));
			
			$radius=( isset($json["radius"]) )? $json["radius"] : 0;
			$clientid=( isset($json["clientid"]) )? $json["clientid"] : 0;
			
			
			if($user_location!=NULL){
				$this->putReciever($this->getUserIdByConnect($sender),$sender,$json["reciever_type"],$user_location["latitude"],$user_location["longitude"],$radius,$clientid);
			}else{
				$this->putReciever($this->getUserIdByConnect($sender),$sender,$json["reciever_type"],null,null,$radius,$clientid);
			}
			
			$this->outgoingConfirmStartRecieve($sender);
			
			$this->notifyTargetedUsers($this->getUserIdByConnect($sender),$json["reciever_type"],$clientid);
			
			/*//Отправляем последнее положение друзей
			$friends=$this->db_map->getFriendsLocation($this->getUserIdByConnect($sender));			
			foreach($friends as $friend){				
				$coors=array();
				$coors["transport"]=TRANSPORT_MAP;
				$coors["type"]=OUTGOING_COORS;
				$coors["userid"]=$friend["userid"];
				$coors["timestamp"]=$friend["timestamp"];				
				$coors["latitude"]=$friend["latitude"];
				$coors["longitude"]=$friend["longitude"];
				$coors["accuracy"]=$friend["accuracy"];
				$coors["provider"]=$friend["provider"];
				
				$this->outgoingCoors($this->getConnectByUserId($friend["userid"]), $coors);
			}*/
		break;
		
		case INCOMING_STOP_RECIEVE :
			
			$senderid=$this->getUserIdByConnect($sender);
			
			$recievertype=0;
			
			if( isset( $this->recievers[$senderid] ) ){
				$reciever=$this->recievers[$senderid];
				$recievertype=$reciever["type"];
				$clientid=$reciever["clientid"];
				
				$this->removeReciever($this->getUserIdByConnect($sender));
				$this->outgoingStartBroadcast($sender);
				$this->notifyTargetedUsers($this->getUserIdByConnect($sender),$recievertype,$clientid);
			}
			
		break;
		
		case INCOMING_COORS :
							
			//$this->db_map->setUserLocation($this->getUserIdByConnect($sender),$json["latitude"],$json["longitude"],$json["accuracy"],$json["provider"]);
			
			$coors=array();
			$coors["transport"]=TRANSPORT_MAP;
			$coors["type"]=OUTGOING_COORS;
			$coors["userid"]=$this->getUserIdByConnect($sender);
			$coors["timestamp"]=time();				
			$coors["latitude"]=$json["latitude"];
			$coors["longitude"]=$json["longitude"];
			$coors["accuracy"]=$json["accuracy"];
			$coors["provider"]=$json["provider"];
			
			$resend_count=$this->resendCoorsToRecievers($sender,$this->getUserIdByConnect($sender),$coors);
			if($resend_count==0){
				$this->outgoingStopBroadcast($sender);
			}
			
		break;
	}
		
		
	
}

//-----------Функции обеспечения связи между UserId и Connection----

protected function getIdByConnect($connect) {
        return intval($connect);
}

protected function getConnectByUserId($userid) {
    return $this->map_userid_connect[strval($userid)];
}

protected function getUserIdByConnect($connect) {
	$connectid=$this->getIdByConnect($connect);
	return $this->map_connectid_userid[strval($connectid)];
}

protected function putConnect($connect,$userid) {
	$connectid=$this->getIdByConnect($connect);
	
	$this->map_connectid_userid[strval($connectid)]=$userid;
	$this->map_userid_connect[strval($userid)]=$connect;
	array_push($this->connects,$connect);
}

protected function removeConnect($connect) {
	
	$connectid=$this->getIdByConnect($connect);
	
	
	unset($this->map_userid_connect[strval($this->getUserIdByConnect($connect))]);
	unset($this->map_connectid_userid[strval($connectid)]);
	unset($this->connects[array_search($connect, $this->connects)]);
}

//---------------------Служебные-------------------------

public function log($message){
	//Лог в укзанный в config файл
	
	if($this->config['log']){
		file_put_contents($this->config['log'], "pid:".posix_getpid()." ".date("Y-m-d H:i:s")." ".$message."\n",FILE_APPEND); 
	}
}

//-------Стандартные функции протокола WebSocket----------
  
protected function onOpen($connect, $info) {
	
	//-------------Notification---------------------------
	
	//Отправляем уведомление об удачном подключении	
	fwrite($connect, $this->encode('{"transport":"100","value":"Connected to chat-server","last_timestamp":"'.time().'"}'));
	
	$userid=$info["userid"];
	$last_timestamp=$info["last_timestamp"];
	
	//-------Private-----------------------------
	
	if($last_timestamp!=0){
	
		$messages=$this->db_chat->getMessagesPrivate($userid,$last_timestamp);	
		//Если есть сохраненные в БД private-сообщения, для только что подключившегося User, от текущего Interlocutor, то отправляем
		if($messages){
			$this->log("<<ChatMessage. De-accumulate. Private. connectid=".$this->getIdByConnect($connect)." userid=".$userid);
			foreach($messages as $message){	
					
					
					$json=array();
					$json["interlocutor_id"]=$message["sender"];
					$json["transport"]=$message["message"];
					$json["value"]=$message["value"];
					$json["date"]=$message["date"];
					
					$this->SendToDestination($connect,$json);
					//После отправления удаляем из БД, таким образом исключая повторное отправление
					//$this->db_chat->deleteMessagePrivate(intval($message["id"]));
							
			}
			$this->log(">>");
		}
	} else {//do nothing		
		//$messages=$this->db_chat->getLast20GroupMessagesOfUser($userid);	
	}
	
	//-------Group--------------------------
	
	$messages=null;
	
	if($last_timestamp!=0){
	
		$messages=$this->db_chat->getGroupMessagesOfUser($userid,$last_timestamp);
		//Если за время отсуствия были групповые сообщения для User
		if($messages){
			$this->log("<<ChatMessage. De-accumulate. Group. connectid=".$this->getIdByConnect($connect)." userid=".$userid);
			foreach($messages as $message){									
				$this->SendToDestination($connect,$message);						
			}
			$this->log(">>");
		}
		
	} else {//do nothing		
		//$messages=$this->db_chat->getLast20GroupMessagesOfUser($userid);	
	}
	
	//------------Map------------------
	
	$this->outgoingStartBroadcast($connect);
	
	//----------Profile--------------------------
	
	$this->outgoingUsersDelta($connect,$info["last_timestamp"]);	
	$this->outgoingGroupsDelta($connect,$info["last_timestamp"]);
	$this->outgoingGroupUsersDelta($connect,$info["last_timestamp"]);
	$this->outgoingGroupmatesDelta($connect,$info["last_timestamp"]);
	
}

protected function onClose($connect) {
	//Пользовательский сценарий. Обратный вызов после закрытия соединения
	
	//-----------Map-------------------	
	
	$this->removeReciever($this->getUserIdByConnect($connect));
}

protected function onMessage($sender,$connects,$data) {
	//Пользовательский сценарий. Обратный вызов при получении сообщения
	
	$message_string= $this->decode($data)['payload'] . "\n";		
	$json=json_decode($message_string,true);
	
	if( array_key_exists("transport",$json) ){
		
		switch($json["transport"]){
		
			case TRANSPORT_TEXT :{
				$this->ProcessMessageChat($sender,$connects,$json);
				break;
			}
			
			case TRANSPORT_MAP:{
				$this->ProcessMessageMap($sender,$connects,$json);
				break;
			}
			
			case TRANSPORT_PROFILE:{
				$this->ProcessMessageProfile($sender,$connects,$json);
				break;
			}
		}
		
	}
	
}

function handshake($connect){
    $info = array();

    //$this->log("handshake begin");

    $line = fgets($connect);
    $header = explode(' ', $line);
    $info['method'] = $header[0];
    $info['uri'] = $header[1];
	
	//$this->log("handshake header-method : ".$info['method']);
	
    //считываем заголовки из соединения
    while ($line = rtrim(fgets($connect))) {
        if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
            $info[$matches[1]] = $matches[2];
        } else {
		
            break;
        }
    }
	
	
    $address = explode(':', stream_socket_get_name($connect, true)); //получаем адрес клиента
    $info['ip'] = $address[0];
    $info['port'] = $address[1];
	
    if (empty($info['Sec-WebSocket-Key'])) {
		$this->log("handshake is failed 'Sec-WebSocket-Key' is missing");
        return false;
    }

	//$this->log('Sec-WebSocket-Key'.$info['Sec-WebSocket-Key']);
	
    //отправляем заголовок согласно протоколу вебсокета
    $SecWebSocketAccept = base64_encode(pack('H*', sha1($info['Sec-WebSocket-Key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
	
    $upgrade = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
        "Upgrade: websocket\r\n" .
        "Connection: Upgrade\r\n" .
        "Sec-WebSocket-Accept: $SecWebSocketAccept\r\n\r\n";
    
	fwrite($connect, $upgrade);
	
    //$this->log("handshake info : ".implode('  ',$info));
	//$this->log("handshake end");

    return $info;
}

function encode($payload, $type = 'text', $masked = false){
    $frameHead = array();
    $payloadLength = strlen($payload);

    switch ($type) {
        case 'text':
            // first byte indicates FIN, Text-Frame (10000001):
            $frameHead[0] = 129;
            break;

        case 'close':
            // first byte indicates FIN, Close Frame(10001000):
            $frameHead[0] = 136;
            break;

        case 'ping':
            // first byte indicates FIN, Ping frame (10001001):
            $frameHead[0] = 137;
            break;

        case 'pong':
            // first byte indicates FIN, Pong frame (10001010):
            $frameHead[0] = 138;
            break;
    }

    // set mask and payload length (using 1, 3 or 9 bytes)
    if ($payloadLength > 65535) {
        $payloadLengthBin = str_split(sprintf('%064b', $payloadLength), 8);
        $frameHead[1] = ($masked === true) ? 255 : 127;
        for ($i = 0; $i < 8; $i++) {
            $frameHead[$i + 2] = bindec($payloadLengthBin[$i]);
        }
        // most significant bit MUST be 0
        if ($frameHead[2] > 127) {
            return array('type' => '', 'payload' => '', 'error' => 'frame too large (1004)');
        }
    } elseif ($payloadLength > 125) {
        $payloadLengthBin = str_split(sprintf('%016b', $payloadLength), 8);
        $frameHead[1] = ($masked === true) ? 254 : 126;
        $frameHead[2] = bindec($payloadLengthBin[0]);
        $frameHead[3] = bindec($payloadLengthBin[1]);
    } else {
        $frameHead[1] = ($masked === true) ? $payloadLength + 128 : $payloadLength;
    }

    // convert frame-head to string:
    foreach (array_keys($frameHead) as $i) {
        $frameHead[$i] = chr($frameHead[$i]);
    }
    if ($masked === true) {
        // generate a random mask:
        $mask = array();
        for ($i = 0; $i < 4; $i++) {
            $mask[$i] = chr(rand(0, 255));
        }

        $frameHead = array_merge($frameHead, $mask);
    }
    $frame = implode('', $frameHead);

    // append payload to frame:
    for ($i = 0; $i < $payloadLength; $i++) {
        $frame .= ($masked === true) ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
    }

    return $frame;
}

function decode($data){
    $unmaskedPayload = '';
    $decodedData = array();

    // estimate frame type:
    $firstByteBinary = sprintf('%08b', ord($data[0]));
    $secondByteBinary = sprintf('%08b', ord($data[1]));
    $opcode = bindec(substr($firstByteBinary, 4, 4));
    $isMasked = ($secondByteBinary[0] == '1') ? true : false;
    $payloadLength = ord($data[1]) & 127;

    // unmasked frame is received:
    if (!$isMasked) {
        return array('type' => '', 'payload' => '', 'error' => 'protocol error (1002)');
    }

    switch ($opcode) {
        // text frame:
        case 1:
            $decodedData['type'] = 'text';
            break;

        case 2:
            $decodedData['type'] = 'binary';
            break;

        // connection close frame:
        case 8:
            $decodedData['type'] = 'close';
            break;

        // ping frame:
        case 9:
            $decodedData['type'] = 'ping';
            break;

        // pong frame:
        case 10:
            $decodedData['type'] = 'pong';
            break;

        default:
            return array('type' => '', 'payload' => '', 'error' => 'unknown opcode (1003)');
    }

    if ($payloadLength === 126) {
        $mask = substr($data, 4, 4);
        $payloadOffset = 8;
        $dataLength = bindec(sprintf('%08b', ord($data[2])) . sprintf('%08b', ord($data[3]))) + $payloadOffset;
    } elseif ($payloadLength === 127) {
        $mask = substr($data, 10, 4);
        $payloadOffset = 14;
        $tmp = '';
        for ($i = 0; $i < 8; $i++) {
            $tmp .= sprintf('%08b', ord($data[$i + 2]));
        }
        $dataLength = bindec($tmp) + $payloadOffset;
        unset($tmp);
    } else {
        $mask = substr($data, 2, 4);
        $payloadOffset = 6;
        $dataLength = $payloadLength + $payloadOffset;
    }

    /**
     * We have to check for large frames here. socket_recv cuts at 1024 bytes
     * so if websocket-frame is > 1024 bytes we have to wait until whole
     * data is transferd.
     */
    if (strlen($data) < $dataLength) {
        return false;
    }

    if ($isMasked) {
        for ($i = $payloadOffset; $i < $dataLength; $i++) {
            $j = $i - $payloadOffset;
            if (isset($data[$i])) {
                $unmaskedPayload .= $data[$i] ^ $mask[$j % 4];
            }
        }
        $decodedData['payload'] = $unmaskedPayload;
    } else {
        $payloadOffset = $payloadOffset - 4;
        $decodedData['payload'] = substr($data, $payloadOffset);
    }

    return $decodedData;
}
    
}