<?php

require_once '../include/SimpleImage.php'; 
require_once '../include/DbHandler.php';
require_once '../include/PassHash.php';

require_once '../libs/Slim/Slim.php';

 
\Slim\Slim::registerAutoloader();
 
$app = new \Slim\Slim();
 
// User id from db - Global Variable
$user_id = NULL;

// Server update span time. Igors var
$update_span=10*1000;

$app->get('/hello/:name', function ($name) {
    echo "Hello, " . $name;
});
  
/**
 * Verifying required params posted or not
 */
function verifyRequiredParams($required_fields) {
    $error = false;
    $error_fields = "";
    $request_params = array();
    $request_params = $_REQUEST;
    // Handling PUT request params
    if ($_SERVER['REQUEST_METHOD'] == 'PUT') {
        $app = \Slim\Slim::getInstance();
        parse_str($app->request()->getBody(), $request_params);
    }
    foreach ($required_fields as $field) {
        if (!isset($request_params[$field]) || strlen(trim($request_params[$field])) <= 0) {
            $error = true;
            $error_fields .= $field . ', ';
        }
    }
 
    if ($error) {
        // Required field(s) are missing or empty
        // echo error json and stop the app
        $response = array();
        $app = \Slim\Slim::getInstance();
        $response["error"] = true;
        $response["message"] = 'Required field(s) ' . substr($error_fields, 0, -2) . ' is missing or empty';
        echoRespnse(400, $response);
        $app->stop();
    }
}
 
/**
 * Validating email address
 */
function validateEmail($email) {
    $app = \Slim\Slim::getInstance();
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $response["error"] = true;
        $response["message"] = 'Email address is not valid';
        echoRespnse(400, $response);
        $app->stop();
    }
}
 
/**
 * Echoing json response to client
 * @param String $status_code Http response code
 * @param Int $response Json response
 */
function echoRespnse($status_code, $response) {
    $app = \Slim\Slim::getInstance();
    // Http response code
    $app->status($status_code);
 
    // setting response content type to json
    $app->contentType('application/json');
 
    echo json_encode($response,JSON_UNESCAPED_SLASHES);
}
 

/**
 * User Registration
 * url - /register
 * method - POST
 * params - name, email, password
 */
$app->post('/register', function() use ($app) {
            // check for required params
            verifyRequiredParams(array('name', 'email', 'password'));
 
            $response = array();
 
            // reading post params
            $name = $app->request->post('name');
            $email = $app->request->post('email');
            $password = $app->request->post('password');
 
            // validating email address
            validateEmail($email);
 
            $db = new DbHandler();
            $res = $db->createUser($name, $email, $password);
 
            if ($res == USER_CREATED_SUCCESSFULLY) {
            	$response["success"] = 1;
                $response["error"] = false;
                $response["message"] = "You are successfully registered";
                echoRespnse(201, $response);
            } else if ($res == USER_CREATE_FAILED) {
            	$response["success"] = 0;
                $response["error"] = true;
                $response["message"] = "Oops! An error occurred while registereing";
                echoRespnse(200, $response);
            } else if ($res == USER_ALREADY_EXISTED) {
            	$response["success"] = 0;
                $response["error"] = true;
                $response["message"] = "Sorry, this email already existed";
                echoRespnse(200, $response);
            }
        });
		
/**
 * User Login
 * url - /login
 * method - POST
 * params - email, password
 */
$app->post('/login', function() use ($app) {
            // check for required params
            verifyRequiredParams(array('email', 'password'));
 
            // reading post params
            $email = $app->request()->post('email');
            $password = $app->request()->post('password');
            $response = array();
 
            $db = new DbHandler();
            // check for correct email and password
            if ($db->checkLogin($email, $password)) {
                // get the user by email
                $user = $db->getUserByEmail($email);
 
                if ($user != NULL) {
                    $response["error"] = false;
                    $response['apiKey'] = $user['api_key'];
                    $response['user_id'] = $user['id'];
                } else {
                    // unknown error occurred
                    $response['error'] = true;
                    $response['message'] = "An error occurred. Please try again";
                }
            } else {
                // user credentials are wrong
                $response['error'] = true;
                $response['message'] = 'Login failed. Incorrect credentials';
            }
 
            echoRespnse(200, $response);
        });
		
/**
 * Adding Middle Layer to authenticate every request
 * Checking if the request has valid api key in the 'Authorization' header
 */
function authenticate(\Slim\Route $route) {
    // Getting request headers
    $headers = apache_request_headers();
    $response = array();
    $app = \Slim\Slim::getInstance();
 
    // Verifying 'Api-Key' Header
    if (isset($headers['Api-Key'])) {
        $db = new DbHandler();
 
        // get the api key
        $api_key = $headers['Api-Key'];
        // validating api key
        if (!$db->isValidApiKey($api_key)) {
            // api key is not present in users table
            $response["error"] = true;
            $response["message"] = "Access Denied. Invalid Api key";
            $response["success"] = 0;
            echoRespnse(401, $response);
            $app->stop();
        } else {
            global $user_id;
            // get user primary key id
            $user = $db->getUserId($api_key);
            if ($user != NULL)
                $user_id = $user["id"];
        }
    } else {
        // api key is missing in header
        $response["error"] = true;
        $response["message"] = "Api key is misssing";
        $response["success"] = 0;
        echoRespnse(400, $response);
        $app->stop();
    }
    
    
}

/*Check api key by 'authenticate'-protocol*/
$app->post('/testapikey', 'authenticate', function() {
            
            $response=array();
            $response["error"] = false;
            $response["message"] = "Api key is actual";
            $response["success"] = 1;
             
            echoRespnse(200, $response);
        });
   
/**
 * Listing all users
 * method GET
 * url /users/all         
 */
$app->get('/users/all', 'authenticate', function() {
           
            
            $db = new DbHandler();
            
            // listing all users            
            $result = $db->getAllUsers();
 	    
 	    $response = array();
 	    if($result==null){
 	    	$response["success"] = 0;
            	$response["error"] = true;
 	    }else{		
            	$response["success"] = 1;
            	$response["error"] = false;
            	$response["users"]=$result;
            }
 
            echoRespnse(200, $response);
        });
        
/**
 * Get user by id
 * method GET
 * url /users/:id         
 */
$app->get('/users/:id', 'authenticate', function($id) {
           
            
            $db = new DbHandler();
            
            if($id==0){
            	global $user_id;
            	$id=$user_id;
            }
                      
            $result = $db->getUserById($id);
 	    
 	    $response = array();
 	    if($result==NULL){
 	    	$response["success"] = 0;
            	$response["error"] = true;
 	    }else{		
            	$response["success"] = 1;
            	$response["error"] = false;
            	
            	$users=array();
            	$users[]=$result;
            	
            	$response["users"]=$users;
            }
 
            echoRespnse(200, $response);
});

/**
 * Save user's params
 * method POST
 * url /users         
 */
$app->post('/users', 'authenticate', function() use ($app) {
           
            $db = new DbHandler();
            
            global $user_id;
            
            $user = $db->getUserById($user_id);
            
            // reading post params
            $name = $app->request->post('Name');
            $status= $app->request->post('Status');
                                  
            if($name==null)$name=$user["name"];
            if($status==null)$status=$user["status"];
 	    
 	    $response = $db->updateUser($user_id,$name,$status);
 
            echoRespnse(200, $response);
});
        
function createThumb($image,$size,$path){	

        $image->thumbnail($size, $size);
        
        $format= $image->get_original_info()['format'];         
        $uniqid=uniqid();
        
        $filename=$uniqid. '.'. $format;
               
        if($image->save($path.$filename)){
        	return $filename;        
        }else{
        	return new Exception("Can not writeImage to ".$filename);
        }
}

/**
 * Upload new user's avatar
 * method POST
 * url - /avatars/upload
 */
$app->post('/avatars/upload', 'authenticate', function() use ($app) {
	
	
 
	// array for final json respone
	$response = array();
  	
  	try{
  		// Check if the file is missing
		if (!isset($_FILES['image']['name'])) {
			throw new Exception('Not received any file!F');
		}
		
		if($_FILES['image']['size'] > 2*1024*1024) { 
			throw new Exception('File is too big');
		}
			
	    	$tmpFile = $_FILES["image"]["tmp_name"];
	    	
	    	// Check if the file is really an image
	    	list($width, $height) = getimagesize($tmpFile);    		
    		if ($width == null && $height == null) {
    			throw new Exception('File is not image!F');
    		}
 	
 		$image = new abeautifulsite\SimpleImage($tmpFile);
	  	
	  	$value_full=createThumb($image,size_full,$_SERVER['DOCUMENT_ROOT'].path_fulls);
	  	$value_avatar=createThumb($image,size_avatar,$_SERVER['DOCUMENT_ROOT'].path_avatars);
	  	$value_icon=createThumb($image,size_icon,$_SERVER['DOCUMENT_ROOT'].path_icons);
	  	
	  	global $user_id;
	  	$db = new DbHandler();
	  	if(!$db->createAvatar($user_id,$value_full,$value_avatar,$value_icon)){
	  		unlink($_SERVER['DOCUMENT_ROOT'].path_fulls.$value_full);
	  		unlink($_SERVER['DOCUMENT_ROOT'].path_avatars.$value_avatar);
	  		unlink($_SERVER['DOCUMENT_ROOT'].path_icons.$value_icon);
	  		throw new Exception('Failed to insert to DB');
	  	}	  	
 	         	        
	        $response['message'] = 'File uploaded successfully!';
	        $response['error'] = false;
	        $response['success'] = 1;
	
	} catch (Exception $e) {
		// Exception occurred. Make error flag true
	        $response['error'] = true;
	        $response['message'] = $e->getMessage();
	     	$response['success'] = 0;
	}
	
	echoRespnse(200,$response);
   	      	
});

/**
 * Get user's avatar
 * method GET
 * url - /avatars/:user_id
 * return - url of user's avatar image
 */
$app->get('/avatars/:user_id', 'authenticate', function($id) use ($app) {
	
	$response = array();
	
	try{	
		$db = new DbHandler();
	        
	        if($id==0){
	        	global $user_id;	
	        	$id=$user_id;
	        }
	            
	        $result = $db->getUserAvatar($id);
	        
	        $result['full']=URL_HOME.path_fulls.$result['full'];
	        $result['avatar']=URL_HOME.path_avatars.$result['avatar'];
	        $result['icon']=URL_HOME.path_icons.$result['icon'];
	        
	 	$response['avatars'] = $result;
	        $response['error'] = false;
	        $response['success'] = 1;
        
        } catch (Exception $e) {
        
	        $response['error'] = true;
	        $response['message'] = $e->getMessage();
	     	$response['success'] = 0;
	}
	
	echoRespnse(200, $response);
	
	
   	      	
});

//-----------------Chat Group-------------------------------

/**
 * Create group and add users
 * method POST
 * url - /chat/create_group
 * return - groupid
 */
$app->post('/chat/create_group', 'authenticate', function () use ($app)  {
	
	
	$response = array();
	
	try{	
			$db = new DbHandlerChat();
	        global $user_id;	
	        			
			$groupid=$db->createGroup($user_id);
			
			if($groupid==NULL)throw new Exception("post('/chat/create_group' groupid==NULL exception");
			
			$response['groupid'] = $groupid;
	        $response['error'] = false;
	        $response['success'] = 1;
        
        } catch (Exception $e) {
        
	        $response['error'] = true;
	        $response['message'] = $e->getMessage();
	     	$response['success'] = 0;
	}
	
	echoRespnse(200, $response);

});	

/**
 * Get all groups 
 * method GET
 * url - /chat/groups/all
 */
$app->get('/chat/groups/all', 'authenticate', function () {
		
	$response = array();
	
	try{	
			$db = new DbHandlerChat();
	        global $user_id;
	        			
			$groups=$db->getAllGroups();
			
			if($groups==NULL)throw new Exception("Exception get('/chat/all groups==NULL ");
			
			$response['groups'] = $groups;
	        $response['error'] = false;
	        $response['success'] = 1;
        
        } catch (Exception $e) {
        
	        $response['error'] = true;
	        $response['message'] = $e->getMessage();
	     	$response['success'] = 0;
	}
	
	echoRespnse(200, $response);

});	

/**
 * Get group by id
 * method GET
 * url - /chat/groups/:groupid
 */
$app->get('/chat/groups/:groupid', 'authenticate', function ($groupid) {
		
	$response = array();
	
	try{	
			$db = new DbHandlerChat();
	        global $user_id;
	        			
			$groups=$db->getGroupById($groupid);
			
			if($groups==NULL)throw new Exception("Exception get('/chat/".$groupid."' groups==NULL ");
			
			$response['groups'] = $groups;
	        $response['error'] = false;
	        $response['success'] = 1;
        
        } catch (Exception $e) {
        
	        $response['error'] = true;
	        $response['message'] = $e->getMessage();
	     	$response['success'] = 0;
	}
	
	echoRespnse(200, $response);

});	

/**
 * Add user to group
 * method POST
 * url - /chat/add_group_users
 */
$app->post('/chat/add_group_users', 'authenticate', function () use ($app) {
	
	// check for required params
    verifyRequiredParams(array('groupid','usersid'));
	
	$response = array();
	
	try{	
		$db = new DbHandlerChat();
	        
		global $user_id;//Current user	
	        
		// reading post params
            	$groupid = $app->request->post('groupid');
		$usersid = $app->request->post('usersid');
		
		//По умолчанию все добавляемые обычные юзеры
		$status=0;

		$integerIDs = array_map('intval', explode(',', $usersid));

		foreach($integerIDs as $userid){
			
			//Presently all user consists in group can add new user. Status: 0,1,2
			$current_user_status=$db->getUserStatusInGroup($groupid,$user_id);			
			if(($current_user_status==0)||($current_user_status==1)||($current_user_status==2)){
				
				if(($status<0)||($status>1))throw new Exception("Illegal user-status (status=".$status.") requested");
				if(($status==2)&&(current_user_status==0))throw new Exception("Not permitted user-status (status=".$status.") requested");
				
				$db->addUserToGroup($groupid,$userid,$status);
				
			}else{
				if($user_id==$userid){throw new Exception("Only user consists in group can add new user. Try '/chat/join_to_group' instead");}
				else {new Exception("Only user consists in group can add new user");}			
			}
		
		}
		
		$response['message'] = "Users:".implode(" ",$integerIDs)." successfully added to group:".$groupid;
	        $response['error'] = false;
	        $response['success'] = 1;
        
        } catch (Exception $e) {
        
	        $response['error'] = true;
	        $response['message'] = $e->getMessage();
	     	$response['success'] = 0;
	}
	
	echoRespnse(200, $response);

});	

/**
 * Get users in group
 * method GET
 * url - /chat/get_group_users/:groupid
 * return - list of users
 */
$app->get('/chat/get_group_users/:groupid', 'authenticate', function ($groupid) use ($app) {
		
	$response = array();
	
	try{	
			$db = new DbHandlerChat();
	        global $user_id;	
	        			
			$users=$db->getUsersInGroup($groupid);
			
			if($users==NULL)throw new Exception("Exception get('/chat/get_users_in_group/:".$groupid."' users==NULL");
			
			$response['users'] = $users;
	        $response['error'] = false;
	        $response['success'] = 1;
        
        } catch (Exception $e) {
        
	        $response['error'] = true;
	        $response['message'] = $e->getMessage();
	     	$response['success'] = 0;
	}
	
	echoRespnse(200, $response);

});	

/**
 * Save group url
 * method POST
 * url - /chat/save_group
 */
$app->post('/chat/save_group', 'authenticate', function () use ($app) {
	
	// check for required params
    	verifyRequiredParams(array('name','groupid'));
	
	$response = array();
	
	try{	
		$db = new DbHandlerChat();
	        
		global $user_id;//Current user	
	        
		// reading post params
            	$name = $app->request->post('name');
		$groupid = $app->request->post('groupid');
			
			
			//Presently all user consists in group can add new user. Status: 0,1,2
			$current_user_status=$db->getUserStatusInGroup($groupid,$user_id);			
			if(($current_user_status==0)||($current_user_status==1)||($current_user_status==2)){
								
				if(($status==2)&&(current_user_status==0))throw new Exception("Not permitted user-status (status=".$status.") requested");
				
				$db->changeGroupName($name,$groupid);
				
			}else{
				throw new Exception("Only user consists in group can change group name");
			}
			
		$response['message'] = "Group name changed successfully";
	        $response['error'] = false;
	        $response['success'] = 1;
        
		} catch (Exception $e) {
        
	        $response['error'] = true;
	        $response['message'] = $e->getMessage();
	     	$response['success'] = 0;
		}
	
	echoRespnse(200, $response);

});	
 
$app->run();

?>