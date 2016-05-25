<?php
 
/**
 * Class to handle all db operations of Chat-project
 *
 * @author Igor Ivanov
 */
require_once dirname(__FILE__) . '/DbHandler.php';
 
class DbHandlerChat extends DbHandler{
 
    function __construct() {
        parent::__construct();
    }
	
/* ------------- `chat_private` ------------------ */
 
    /**
     * Add private message
     * @param Integer $sender User_id of sender
     * @param Integer $destination User_id of destination
	 * @param Integer $message Type of message
	 * @param String $value Value of message
     */
    public function addMessagePrivate($sender,$destination,$message,$value) {
        
            // insert query
            $stmt = $this->conn->prepare("INSERT INTO chat_private(sender, destination, message, value) values(?, ?, ?, ?)");
            $stmt->bind_param("iiis", $sender,$destination,$message,$value);
 
            $result = $stmt->execute();
 
            $stmt->close();
 
            // Check for successful insertion
            if ($result) {
                // User successfully inserted
                return false;
            } else {
                // Failed to create user
                return true;
            };
			
    }
    
     
    /**
     * Listing all messages of User
     * @param Integer $user_id User_id of destination user
     */
    public function getMessagesPrivate($user_id,$last_timestamp) {
	
		$last_datetime=new DateTime();
        $last_datetime->setTimestamp($last_timestamp);
        $last_date_string=$last_datetime->format('Y-m-d H:i:s');
		
        $stmt = $this->conn->prepare("
			SELECT id, sender, destination, message, value, date 
			FROM chat_private 
			WHERE (destination = ? ) AND ( date > '$last_date_string' ) 
			ORDER BY date 
		");
		
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
        
        
            $result = array();
            $stmt->bind_result($id,$sender,$destination,$message,$value,$date);            
            
			while($stmt->fetch()){
				
				$res=array();
				$timestamp_object = DateTime::createFromFormat('Y-m-d H:i:s', $date);
	            $res["id"] = $id;
	            $res["sender"] = $sender;
	            $res["destination"] = $destination;
	            $res["message"] = $message;
	            $res["value"] = $value;
				$res["date"] = $timestamp_object->getTimestamp();
				
				$result[]=$res;
			}
            $stmt->close();
            return $result;
        } else {
            return NULL;
        }
    }
    
    
	/**
     * Delete one messages by id    	
	 * @param Integer $message_id
	 *return deleted rows count;
     */
    public function deleteMessagePrivate($message_id) {  
    	    	
        $stmt = $this->conn->prepare("DELETE FROM chat_private WHERE id = ? ");
        $stmt->bind_param("i", $message_id);
        $result = $stmt->execute();
        
        $count=$stmt->affected_rows;
        
        return $count;
    }
	
    /**
     * Delete all messages of User    	
	 * @param Integer $user_id
	 *return deleted rows count;
     */
    public function deleteMessagesPrivate($user_id) {  
    	    	
        $stmt = $this->conn->prepare("DELETE FROM chat_private WHERE user_id = ? ");
        $stmt->bind_param("i", $user_id);
        $result = $stmt->execute();
        
        $count=$stmt->affected_rows;
        
        return $count;
    }
	
	
/*-------------`chat_group`------------------*/
 
    /**
     * Add group message
     * @param Integer $sender User_id of sender
     * @param Integer $groupid Group_id
	 * @param Integer $message Type of message
	 * @param String $value Value of message
	 * @return Integer id of row (server id of group-message)
     */
    public function addMessageGroup($sender,$groupid,$message,$value) {
        
            // insert query
            $stmt = $this->conn->prepare("INSERT INTO chat_group(sender, groupid, message, value) values(?, ?, ?, ?)");
            $stmt->bind_param("iiis", $sender,$groupid,$message,$value);
 
            $result = $stmt->execute();
			$id=$this->conn->insert_id;
            $stmt->close();
 
            // Check for successful insertion
            if ($result) {
                // User successfully inserted
                return $id;
            } else {
                // Failed to create user
                return NULL;
            };
			
    }
    
   
	/**
     * Listing all messages of group since $last_timestamp
     * @param Integer $userid
	 * @param Long $last_timestamp
     */
    public function getGroupMessagesOfUser($userid,$last_timestamp) {
	
		$last_datetime=new DateTime();
        $last_datetime->setTimestamp($last_timestamp);
        $last_date_string=$last_datetime->format('Y-m-d H:i:s');
		
	
        $stmt = $this->conn->prepare("
		SELECT 
			c.id AS id, c.sender AS sender, c.groupid AS groupid, c.message AS message, c.value AS value, c.date AS date 
		FROM 
			chat_group AS c 
		INNER JOIN	
			(	SELECT groupid 
				FROM group_users 
				WHERE ( ( userid = ? ) AND (( status = 0)OR( status = 1 ) OR ( status = 2 )) ) 
			) AS g 
		ON 
			c.groupid = g.groupid 
		WHERE 
			  c.date > '$last_date_string' 
		ORDER BY c.date 
		");
        
		$stmt->bind_param("i", $userid);
        if ($stmt->execute()) {
        
        
            $result = array();
            $stmt->bind_result($id,$sender,$groupid,$message,$value,$date);            
            
			while($stmt->fetch()){
				
				$timestamp_object = DateTime::createFromFormat('Y-m-d H:i:s', $date);	        
				$res=array();
			
	            $res["id"] = $id;
	            $res["sender"] = $sender;
	            $res["group_id"] = $groupid;
	            $res["message"] = $message;
	            $res["value"] = $value;
				$res["date"] = $timestamp_object->getTimestamp();
				
				$result[]=$res;
			}
            $stmt->close();
            return $result;
        } else {
            return NULL;
        }
    }
	
	/**
     * Listing last 20 messages of group
     * @param Integer $userid	 
     */
    public function getLast20GroupMessagesOfUser($userid) {
		
		$num=20;
	
        $stmt = $this->conn->prepare("
		SELECT o . * 
		FROM ( 		
			SELECT 
				c.id AS id, c.sender AS sender, c.groupid AS groupid, c.message AS message, c.value AS value, c.date AS date 
			FROM 
				chat_group AS c 
			INNER JOIN 
			( 	SELECT t.groupid 
				FROM group_users AS t 
				WHERE t.userid = ?  
			) 	AS g 
			ON c.groupid = g.groupid 
			ORDER BY c.date DESC 
			LIMIT ? 
		) AS o 
		ORDER BY o.date 
		");
        
		$stmt->bind_param("ii", $userid,$num);
        if ($stmt->execute()) {
        
        
            $result = array();
            $stmt->bind_result($id,$sender,$groupid,$message,$value,$date);            
            
			while($stmt->fetch()){
				
				$timestamp_object = DateTime::createFromFormat('Y-m-d H:i:s', $date);	        
				$res=array();
			
	            $res["id"] = $id;
	            $res["sender"] = $sender;
	            $res["group_id"] = $groupid;
	            $res["message"] = $message;
	            $res["value"] = $value;
				$res["date"] = $timestamp_object->getTimestamp();
				
				$result[]=$res;
			}
            $stmt->close();
            return $result;
        } else {
            return NULL;
        }
    }
	
	
 
}
 
?>