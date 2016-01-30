<?php
 
/**
 * Class to handle all db operations
 * This class will have CRUD methods for database tables
 *
 * @author Igor Ivanov
 */
class DbHandler {
 
    private $conn;
 
    function __construct() {
        require_once dirname(__FILE__) . '/DbConnect.php';
        // opening db connection
        $db = new DbConnect();
        $this->conn = $db->connect();
        
    }
 
    /* ------------- `users` table method ------------------ */
 
    /**
     * Creating new user
     * @param String $name User full name
     * @param String $email User login email id
     * @param String $password User login password
     */
    public function createUser($name, $email, $password) {
        require_once 'PassHash.php';
        $response = array();
 
        // First check if user already existed in db
        if (!$this->isUserExists($email)) {
            // Generating password hash
            $password_hash = PassHash::hash($password);
 
            // Generating API key
            $api_key = $this->generateApiKey();
 
            // insert query
            $stmt = $this->conn->prepare("INSERT INTO users(name, email, password_hash, api_key, status) values(?, ?, ?, ?, 1)");
            $stmt->bind_param("ssss", $name, $email, $password_hash, $api_key);
 
            $result = $stmt->execute();
 
            $stmt->close();
 
            // Check for successful insertion
            if ($result) {
                // User successfully inserted
                return USER_CREATED_SUCCESSFULLY;
            } else {
                // Failed to create user
                return USER_CREATE_FAILED;
            }
        } else {
            // User with same email already existed in the db
            return USER_ALREADY_EXISTED;
        }
 
        return $response;
    }
    
    /**
     * Update user 
     * @param String $name User full name
     * @param String $email User login email id
     * @param String $password User login password
     */
    public function updateUser($id, $name, $status) {
        require_once 'PassHash.php';
        
        $response = array();
         
            // updatequery
            $stmt = $this->conn->prepare("UPDATE `users` SET `name` = ? , `status` = ? , `changed_at` = CURRENT_TIMESTAMP() WHERE `id` = ? ");
            $stmt->bind_param("sii", $name, $status,$id);
 
            $result = $stmt->execute();
 
            $stmt->close();
 
            // Check for successful insertion
            if ($result) {
                $response["success"]=1;
                $response["error"]=false;
                $response["message"]="User is successfully updated";
            } else {
                $response["success"]=0;
                $response["error"]=true;
                $response["message"]="Update query to DB failed";
            }
        
 
        return $response;
    }    
 
    /**
     * Checking user login
     * @param String $email User login email id
     * @param String $password User login password
     * @return boolean User login status success/fail
     */
    public function checkLogin($email, $password) {
        // fetching user by email
        $stmt = $this->conn->prepare("SELECT password_hash FROM users WHERE email = ?");
 
        $stmt->bind_param("s", $email);
 
        $stmt->execute();
 
        $stmt->bind_result($password_hash);
 
        $stmt->store_result();
 
        if ($stmt->num_rows > 0) {
            // Found user with the email
            // Now verify the password
 
            $stmt->fetch();
 
            $stmt->close();
 
            if (PassHash::check_password($password_hash, $password)) {
                // User password is correct
                return TRUE;
            } else {
                // user password is incorrect
                return FALSE;
            }
        } else {
            $stmt->close();
 
            // user not existed with the email
            return FALSE;
        }
    }
 
    /**
     * Checking for duplicate user by email address
     * @param String $email email to check in db
     * @return boolean
     */
    private function isUserExists($email) {
        $stmt = $this->conn->prepare("SELECT id from users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();
        $num_rows = $stmt->num_rows;
        $stmt->close();
        return $num_rows > 0;
    }
 
    /**
     * Fetching user by email
     * @param String $email User email id
     */
    public function getUserByEmail($email) {
        $stmt = $this->conn->prepare("SELECT id, name, email, api_key, status, created_at FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        if ($stmt->execute()) {
        
        
            $res = array();
            $stmt->bind_result($id,$name, $email, $api_key, $status, $created_at);            
            
            $stmt->fetch();
            
	            $res["id"] = $id;
	            $res["name"] = $name;
	            $res["email"] = $email;
	            $res["api_key"] = $api_key;
	            $res["status"] = $status;
	            $res["created_at"] = $created_at;
	            
	    
            
            $stmt->close();
            return $res;
        } else {
            return NULL;
        }
    }
    
    /**
     * Fetching user by id
     * @param int $id User id
     * returns public columns of 'users' table
     */
    public function getUserById($id) {
        $stmt = $this->conn->prepare("SELECT u.id, u.name, u.status, u.changed_at, a.filename_icon, a.filename_avatar, a.filename_full FROM users u LEFT OUTER JOIN avatars a ON u.avatar = a.id WHERE u.id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
        
	    $stmt->store_result();
            if($stmt->num_rows==0)return NULL;
            
            $stmt->bind_result($id,$name, $status, $changed_at, $icon, $avatar,$full);            
            
            $stmt->fetch();
            
	            $res= array();
	            $res["id"] = $id;
	            $res["name"] = $name;
	            $res["status"] = $status;
	            $res["changed_at"] = $changed_at;	
	            
	            $avatars=array();
	            
	            if($full) $avatars['full']=URL_HOME.path_fulls.$full;
	            if($avatar) $avatars['avatar']=URL_HOME.path_avatars.$avatar;
	            if($icon) $avatars['icon']=URL_HOME.path_icons.$icon;
	            
	            $res['avatars']=$avatars;
	            
            
            $stmt->close();
            return $res;
        } else {
            return NULL;
        }
    }
    
    /**
     * Get all users
     * returns public columns of 'users' table
     */
    public function getAllUsers() {
        $stmt = $this->conn->prepare("SELECT u.id, u.name, u.status, u.changed_at, a.filename_icon, a.filename_avatar, a.filename_full FROM users u LEFT OUTER JOIN avatars a ON u.avatar = a.id ");
        
        if ($stmt->execute()) {
        
        
            $stmt->bind_result($id,$name, $status, $changed_at, $icon, $avatar,$full);            
            
            $users=array();
            while($stmt->fetch()){
            	    
            	    $res= array();
	            $res["id"] = $id;
	            $res["name"] = $name;
	            $res["status"] = $status;
	            $res["changed_at"] = $changed_at;	
	            
	            $avatars=array();
	            
	            if($full) $avatars['full']=URL_HOME.path_fulls.$full;
	            if($avatar) $avatars['avatar']=URL_HOME.path_avatars.$avatar;
	            if($icon) $avatars['icon']=URL_HOME.path_icons.$icon;
	            
	            if(count($avatars))
	            $res['avatars']=$avatars;          
	            
	            $users[]=$res;
	    }            
            $stmt->close();
            
            return $users;
        } else {
            return NULL;
        }
    }
 
    /**
     * Fetching user api key
     * @param String $user_id user id primary key in user table
     */
    public function getApiKeyById($user_id) {
        $stmt = $this->conn->prepare("SELECT api_key FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            $res = array();
            $stmt->bind_result($api_key);            
            $stmt->fetch();
            $res["api_key"] = $api_key;
            
            $stmt->close();
            return $res;
        } else {
            return NULL;
        }
    }
 
    /**
     * Fetching user id by api key
     * @param String $api_key user api key
     */
    public function getUserId($api_key) {
        $stmt = $this->conn->prepare("SELECT id FROM users WHERE api_key = ?");
        $stmt->bind_param("s", $api_key);
        if ($stmt->execute()) {
            $res = array();
            $stmt->bind_result($id);            
            $stmt->fetch();
            $res["id"] = $id;
            
            $stmt->close();
            return $res;
        } else {
            return NULL;
        }
    }
 
    /**
     * Validating user api key
     * If the api key is there in db, it is a valid key
     * @param String $api_key user api key
     * @return boolean
     */
    public function isValidApiKey($api_key) {
        $stmt = $this->conn->prepare("SELECT id from users WHERE api_key = ?");
        $stmt->bind_param("s", $api_key);
        $stmt->execute();
        $stmt->store_result();
        $num_rows = $stmt->num_rows;
        $stmt->close();
        return $num_rows > 0;
    }
 
    /**
     * Generating random Unique MD5 String for user Api key
     */
    private function generateApiKey() {
        return md5(uniqid(rand(), true));
    }
 
    /* ------------- `points` table method ------------------ */
 
    /**
     * Creating new point
     * @param Integer $user_id 
     * @param Integer $timestamp 
	 * @param Integer $latitude
	 * @param Integer $longitude
	 * @param Integer $altitude
	 * @param Integer $accuracy
	 * @param Integer $provider
     */
    public function createPoint($user_id, $latitude, $longitude, $altitude, $accuracy, $provider) {  
    	
          
          echo "latitude = ".$latitude."\n"."longitude = ".$longitude."\n";
          
        $stmt = $this->conn->prepare("INSERT INTO points (user_id, latitude,longitude, altitude, accuracy, provider) VALUES(?,?,?,?,?,?)");
        $stmt->bind_param("issssi", $user_id, $latitude, $longitude, $altitude, $accuracy, $provider);
        $result = $stmt->execute();
        $stmt->close();
 
		$new_task_id = $this->conn->insert_id;
		
		if($result){
			return $new_task_id;
		}else{
			return NULL;
		}        
    }
  
    /**
     * Fetching all points since $last_timestamp
     * @param String $user_id id of the user
     */
    public function getPointsSince($user_id,$last_timestamp) {
        
        
        
        $last_datetime=new DateTime();
        $last_datetime->setTimestamp($last_timestamp);

        $p_timestamp=$last_datetime->format('Y-m-d H:i:s');
        
        //echo "last_timestam=".$p_timestamp."\n";
        
        $stmt = $this->conn->prepare("SELECT p.* FROM points p WHERE ( p.timestamp >= '$p_timestamp' ) AND ( p.user_id != $user_id ) GROUP BY p.user_id ");
       // $stmt->bind_param("si", $p_timestamp,$user_id);
	
	if($stmt->execute()){
		
	        $points = array();
	        $stmt->bind_result($id,$user_id,$timestamp,$latitude,$longitude,$altitude,$accuracy,$provider);
	        
	        while($stmt->fetch()){
	        
	        	$timestamp_object = DateTime::createFromFormat('Y-m-d H:i:s', $timestamp);
	        
	        	$point=array();
	        	
		        $point["id"]=$id;
		        $point["user_id"]=$user_id;
		        $point["timestamp"]=$timestamp_object->getTimestamp();
		        //$point["datetime"]=$timestamp;
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
     * Fetching user's avatars by user_id
     * @param int $user_id User id
     * returns user's avatars filenames
     */
    public function getUserAvatar($user_id) {
    
        $stmt = $this->conn->prepare("SELECT a.filename_full, a.filename_avatar, a.filename_icon, a.created_at FROM users u LEFT OUTER JOIN avatars a ON u.avatar = a.id WHERE u.id = ? AND u.avatar != 0 ");
        
        
        $stmt->bind_param("i", $user_id);
        
        if ($stmt->execute()) {
        
	    $stmt->store_result();
            if($stmt->num_rows==0){
            	throw new Exception("User's avatars not exist user_id=".$user_id);
            }
            
            $stmt->bind_result($filename_full, $filename_avatar,$filename_icon,$changed_at);            
            
            $stmt->fetch();
            
	            $res= array();
	            $res['full'] = $filename_full;
	            $res['avatar'] = $filename_avatar;
	            $res['icon'] = $filename_icon;
	            $res['changed_at'] = $changed_at;	
	            
            $stmt->close();
            
            return $res;
        } else {
            throw new Exception("BD can't execute: $stmt->execute()==NULL ");
        }
    }
    
    /**
     * Create new user's photos(full,avatar,icon)     	
	 * @param String $user_id
	 * @param String $filename_full
	 * @param String $filename_avatar
	 * @param String $filename_icon
	 *return true if success, false otherwise;
     */
    public function createAvatar($user_id, $filename_full, $filename_avatar, $filename_icon) {  
    	
		if(!$this->getUserById($user_id))return NULL;
		
		//If user have avatar already, delete row in avatars table and unlink files
    	$this->deleteAvatar($user_id);
    	  
		//Create new avatar row 
        $stmt = $this->conn->prepare("INSERT INTO avatars (filename_full, filename_avatar, filename_icon) VALUES(?,?,?)");
        $stmt->bind_param("sss", $filename_full, $filename_avatar, $filename_icon);
        $result = $stmt->execute();
		//Save avatar id
		$new_avatar_id = $this->conn->insert_id;
        $stmt->close();
 
		//Update avatar column on users table		
		$stmt = $this->conn->prepare("UPDATE `users` SET `avatar` = ? WHERE `id` = ? ");
        $stmt->bind_param("ii", $new_avatar_id,$user_id);
		$result = $stmt->execute();
		$stmt->close();
		
		if($result){
			return true;
		}else{
			return false;
		}        
    }
    
    /**
     * Delete(unlink) files from directory, and delete rows from datebase    	
	 * @param String $user_id
	 *return deleted rows count;
     */
    public function deleteAvatar($user_id) {  
    	
    	$stmt = $this->conn->prepare("SELECT a.filename_full, a.filename_avatar, a.filename_icon, a.id FROM users u LEFT OUTER JOIN avatars a ON u.avatar = a.id WHERE u.id = ? ");
        $stmt->bind_param("i", $user_id);
        
        if ($stmt->execute()) {
        
			$stmt->store_result();
            if($stmt->num_rows==0){
            	return 0;
            }            
            $stmt->bind_result($filename_full, $filename_avatar,$filename_icon,$avatar_id);            
            $stmt->close();
			
            //Unlinking files
            if( file_exists($_SERVER['DOCUMENT_ROOT'].path_fulls.$filename_full)&& ($filename_full!=NULL) ){
				unlink($_SERVER['DOCUMENT_ROOT'].path_fulls.$filename_full);}            						
			if(file_exists($_SERVER['DOCUMENT_ROOT'].path_avatars.$filename_avatar)&& ($filename_full!=NULL) ){
				unlink($_SERVER['DOCUMENT_ROOT'].path_avatars.$filename_avatar);}    						
			if(file_exists($_SERVER['DOCUMENT_ROOT'].path_icons.$filename_icon) && ($filename_full!=NULL) ){
				unlink($_SERVER['DOCUMENT_ROOT'].path_icons.$filename_icon);}    		
				
			//Update avatar column on users table
			$new_avatar_id = $this->conn->insert_id;
			$stmt = $this->conn->prepare("UPDATE `users` SET `avatar` = NULL WHERE `id` = ? ");
			$stmt->bind_param("i", $user_id);
			$stmt->execute();
			$stmt->close();
			
			//Delete row in avatars table
			$stmt = $this->conn->prepare("DELETE FROM avatars WHERE id = ? ");
			$stmt->bind_param("i", $avatar_id);			
			$stmt->execute();
			$count=$stmt->affected_rows;
			$stmt->close();
			
        } else {
            throw new Exception("BD can't execute: $stmt->execute()==NULL ");
        }
		
        return $count;
    }
 
}
 
?>