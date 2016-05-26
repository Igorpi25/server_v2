<?php
 
/**
 * Class to handle operations of Map-project
 *
 * @author Igor Ivanov
 */
require_once dirname(__FILE__) . '/DbHandler.php';
 
class DbHandlerMap extends DbHandler{
 
    function __construct() {
        parent::__construct();
    }
	
	public function setUserLocation($user_id, $latitude, $longitude, $accuracy, $provider) {
        
        $response = array();
         
            // updatequery
            $stmt = $this->conn->prepare("UPDATE `points` SET `latitude` = ? , `longitude` = ? , `timestamp` = CURRENT_TIMESTAMP(), `accuracy` = ?, provider = ? WHERE `user_id` = ? ");
            $stmt->bind_param("ssssi", $latitude, $longitude, $accuracy, $provider,$user_id );
 
            $result = $stmt->execute();
			$affectedrows=$stmt->affected_rows;
            $stmt->close();
 
			if($affectedrows==0){
				$stmt = $this->conn->prepare("INSERT INTO points (user_id, latitude,longitude, accuracy, provider) VALUES(?,?,?,?,?)");
				$stmt->bind_param("issss", $user_id, $latitude, $longitude, $accuracy, $provider);
				$result = $stmt->execute();
				$stmt->close();
			}
			
			
    }    
	
	/**
     * Fetching locations of friends of user
     * @param String $user_id id of the user
     */
    public function getFriendsLocation($user_id) {
        
        
        
        $stmt = $this->conn->prepare("
		SELECT f.friendid AS id FROM (
			SELECT f.* FROM friends f
			WHERE (f.userid = $user_id) AND (f.status = 3)
		) f 
		INNER JOIN points p ON f.id = p.user_id 
		WHERE ( f.id != $user_id )		
		");
       	
		if($stmt->execute()){
		
	        $points = array();
	        $stmt->bind_result($id,$userid,$timestamp,$latitude,$longitude,$altitude,$accuracy,$provider);
				
			while($stmt->fetch()){			
				$timestamp_object = DateTime::createFromFormat('Y-m-d H:i:s', $timestamp);			
				$point=array();
				
				$point["userid"]=$user_id;
				$point["timestamp"]=$timestamp_object->getTimestamp();
				$point["latitude"]=$latitude;
				$point["longitude"]=$longitude;
				if($altitude!=NULL){$point["altitude"]=$altitude;}		        
				if($accuracy!=NULL){$point["accuracy"]=$accuracy;}
				if($provider!=NULL){$point["provider"]=$provider;}
				
				$points[]=$point;
			}
			
	        $stmt->close();
	        return $points;
        }else{
        	return NULL;
        }
    }
	
	/**
     * Fetching user location     
     */
    public function getUserLocation($user_id) {
                
        $stmt = $this->conn->prepare("SELECT p.* FROM `points` p WHERE  p.`user_id` = $user_id  ");
		
		
		if($stmt->execute()){
	        if($stmt->bind_result($id,$userid,$timestamp,$latitude,$longitude,$altitude,$accuracy,$provider)){
				
				if($stmt->fetch()){
					$timestamp_object = DateTime::createFromFormat('Y-m-d H:i:s', $timestamp);
					
					$point=array();
					
					$point["userid"]=$userid;
					$point["timestamp"]=$timestamp_object->getTimestamp();
					$point["latitude"]=$latitude;
					$point["longitude"]=$longitude;
					if($altitude!=NULL){$point["altitude"]=$altitude;}		        
					if($accuracy!=NULL){$point["accuracy"]=$accuracy;}
					if($provider!=NULL){$point["provider"]=$provider;}
					
					$stmt->close();
					return $point;
				}
	        }
	        $stmt->close();	        
        }
		
		return NULL;
    }
	
	/**
     * Fetching all users location
     */
    public function getAllUsersLocation() {
                
        $stmt = $this->conn->prepare("SELECT p.* FROM points p ");
	
		if($stmt->execute()){
		
	        $points = array();
	        $stmt->bind_result($id,$user_id,$timestamp,$latitude,$longitude,$altitude,$accuracy,$provider);
	        
			while($stmt->fetch()){			
				$timestamp_object = DateTime::createFromFormat('Y-m-d H:i:s', $timestamp);			
				$point=array();
				$point["userid"]=$user_id;
				$point["timestamp"]=$timestamp_object->getTimestamp();
				$point["latitude"]=$latitude;
				$point["longitude"]=$longitude;
				if($altitude!=NULL){$point["altitude"]=$altitude;}		        
				if($accuracy!=NULL){$point["accuracy"]=$accuracy;}
				if($provider!=NULL){$point["provider"]=$provider;}
				
				$points[]=$point;
			}
	        
	        $stmt->close();
	        return $points;
        }else{
        	return NULL;
        }
    }
 }
?>