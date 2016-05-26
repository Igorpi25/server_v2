<?php

require_once 'include/SimpleImage.php'; 
require_once 'include/DbHandlerProfile.php';
require_once 'include/PassHash.php';

require_once 'libs/Slim/Slim.php';
require_once 'communicator/WebsocketClient.php';

define('WEBSOCKET_SERVER_PORT', 8001);

 
\Slim\Slim::registerAutoloader();
 
$app = new \Slim\Slim();
 
// User id from db - Global Variable
$user_id = NULL;

/**
 * It used to Slim testing during installation the server 
 */
$app->get('/hello/:name', function ($name) {
		//Console command to notify that group has been changed
		/*$json_header=array();
		$json_header["console"]="v1/index/hello";
		$json_header["operation"]=CONSOLE_OPERATION_GROUP;
		$json_header["group_operationid"]=GROUPOPERATION_SAVE;
		$json_header["groupid"]=2;
		$json_header["senderid"]=1;
		
		$json=array();
		$json["name"]="$name";
		
		$json_header["json"]=json_encode($json);
		$console_response=consoleCommand($json_header);			
				
		echo $console_response["message"];*/
		echo "hello";
});

/**
 * Echoing json response to client
 * @param String $status_code Http response code
 * @param Int $response Json response
 */
function echoResponse($status_code, $response) {
    $app = \Slim\Slim::getInstance();
    // Http response code
    $app->status($status_code);
 
    // setting response content type to json
    $app->contentType('application/json');
 
    echo json_encode($response,JSON_UNESCAPED_SLASHES);
}

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
        echoResponse(400, $response);
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
        echoResponse(400, $response);
        $app->stop();
    }
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
 
            $db = new DbHandlerProfile();
            $res = $db->createUser($name, $email, $password);
 
            if ($res == USER_CREATED_SUCCESSFULLY) {
            	$response["success"] = 1;
                $response["error"] = false;
                $response["message"] = "You are successfully registered";
                echoResponse(201, $response);
            } else if ($res == USER_CREATE_FAILED) {
            	$response["success"] = 0;
                $response["error"] = true;
                $response["message"] = "Oops! An error occurred while registereing";
                echoResponse(200, $response);
            } else if ($res == USER_ALREADY_EXISTED) {
            	$response["success"] = 0;
                $response["error"] = true;
                $response["message"] = "Sorry, this email already existed";
                echoResponse(200, $response);
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
 
            $db = new DbHandlerProfile();
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
 
            echoResponse(200, $response);
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
        $db = new DbHandlerProfile();
 
        // get the api key
        $api_key = $headers['Api-Key'];
        // validating api key
        if (!$db->isValidApiKey($api_key)) {
            // api key is not present in users table
            $response["error"] = true;
            $response["message"] = "Access Denied. Invalid Api key";
            $response["success"] = 0;
            echoResponse(401, $response);
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
        echoResponse(400, $response);
        $app->stop();
    }
    
    
}

$app->post('/testapikey', 'authenticate', function() {
            
            $response=array();
            $response["error"] = false;
            $response["message"] = "Api key is actual";
            $response["success"] = 1;
             
            echoResponse(200, $response);
});

//----------------Users-----------------------------------------

/**
 * Listing all users
 * method GET
 * url /users/all         
 */
$app->get('/users/all', 'authenticate', function() {
           
            global $user_id;
			
            $db = new DbHandlerProfile();
            
            // listing all users            
            $result = $db->getAllFriends($user_id);
 	    
 	    $response = array();
 	    //if($result==null){
		//		$response["success"] = 0;
        //    	$response["error"] = true;
 	    //}else{		
            	$response["success"] = 1;
            	$response["error"] = false;
            	$response["users"]=$result;
        //}
 
            echoResponse(200, $response);
        });
        
/**
 * Get user by id
 * method GET
 * url /users/:id         
 */
$app->get('/users/:id', 'authenticate', function($id) {
           
            
            $db = new DbHandlerProfile();
            global $user_id;
            if($id==0){
            	
            	$id=$user_id;
            }
                      
            $result = $db->getFriendById($user_id,$id);
 	    
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
 
            echoResponse(200, $response);
});

/**
 * Save user's params
 * method POST
 * url /users         
 */
$app->post('/users', 'authenticate', function() use ($app) {
           
            $db = new DbHandlerProfile();
            
            global $user_id;
            
            $user = $db->getUserById($user_id);
            
            // reading post params
            $name = $app->request->post('Name');
            $status= $app->request->post('Status');
                                  
            if($name==null)$name=$user["name"];
            if($status==null)$status=$user["status"];
 	    
 	    $response = $db->updateUser($user_id,$name,$status);
 
            echoResponse(200, $response);
});

//-----------------Photo Uploading--------------------------

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
	  	$db = new DbHandlerProfile();
	  	if(!$db->createUserAvatar($user_id,$value_full,$value_avatar,$value_icon)){
	  		unlink($_SERVER['DOCUMENT_ROOT'].path_fulls.$value_full);
	  		unlink($_SERVER['DOCUMENT_ROOT'].path_avatars.$value_avatar);
	  		unlink($_SERVER['DOCUMENT_ROOT'].path_icons.$value_icon);
	  		throw new Exception('Failed to insert to DB');
	  	}	  	
 	     
	    $response['message'] = 'File uploaded successfully!';
	    $response['error'] = false;
	    $response['success'] = 1;
			
		//Console command to notify users
		$json_header=array();
		$json_header["console"]="v1/index/avatars/upload";
		$json_header["operation"]=CONSOLE_OPERATION_USER_CHANGED;
		$json_header["userid"]=$user_id;		
		$console_response=consoleCommand($json_header);
		
		$response['consoleCommand'] = $console_response["message"];
			
	} catch (Exception $e) {
		// Exception occurred. Make error flag true
	        $response['error'] = true;
	        $response['message'] = $e->getMessage();
	     	$response['success'] = 0;
	}
	
	echoResponse(200,$response);
   	      	
});

/**
 * Upload group panorama photo
 * method POST
 * url - /group_panorama/upload
 */
$app->post('/group_panorama/upload/:group_id', 'authenticate', function($group_id) use ($app) {
	
	global $user_id;
	$db = new DbHandlerProfile();
	
	// array for final json respone
	$response = array();
  	
  	try{
		//Checkin user permission to this operation
		$user_status_in_group=$db->getUserStatusInGroup($group_id,$user_id);		
		if( ($user_status_in_group!=0)&&($user_status_in_group!=1)&&($user_status_in_group!=2) ) {
			//User doesn't consist in group
			throw new Exception('No permission. User does not consist in group');
		} if( ($user_status_in_group!=1)&&($user_status_in_group!=2) ) {
			//User is Not Super-admin and not Admin
			throw new Exception('No permission. Only admin can change group-profile photo');
		}
	
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
	  	
	  	
	  	
	  	if(!$db->createGroupAvatar($group_id,$value_full,$value_avatar,$value_icon)){
	  		unlink($_SERVER['DOCUMENT_ROOT'].path_fulls.$value_full);
	  		unlink($_SERVER['DOCUMENT_ROOT'].path_avatars.$value_avatar);
	  		unlink($_SERVER['DOCUMENT_ROOT'].path_icons.$value_icon);
	  		throw new Exception('Failed to insert to DB');
	  	}	  	
 	     
	    $response['message'] = 'File uploaded successfully!';
	    $response['error'] = false;
	    $response['success'] = 1;
			
		
		//Console command to notify users
		$json_header=array();
		$json_header["console"]="v1/index/group_panorama/upload/".$group_id;
		$json_header["operation"]=CONSOLE_OPERATION_GROUP;
		$json_header["group_operationid"]=GROUPOPERATION_SAVE;
		$json_header["groupid"]=$group_id;
		$json_header["senderid"]=$user_id;
		$json_header["json"]='{dummy:"Dummy"}';
		$console_response=consoleCommand($json_header);
		
		$response['consoleCommand'] = $console_response["message"];
			
	} catch (Exception $e) {
		// Exception occurred. Make error flag true
	        $response['error'] = true;
	        $response['message'] = $e->getMessage();
	     	$response['success'] = 0;
	}
	
	echoResponse(200,$response);
   	      	
});

//------------------Group-------------------------------

/**
 * Create group and add users
 * method POST
 * url - /create_group
 * return - groupid
 */
$app->post('/create_group', 'authenticate', function () use ($app)  {
	
	try{
	
		
		global $user_id;	
		
		$response = array();
				
		//Console command to notify that group has been changed
		$json_header=array();
		$json_header["console"]="v1/index/create_group";
		$json_header["operation"]=CONSOLE_OPERATION_GROUP;
		$json_header["group_operationid"]=GROUPOPERATION_CREATE;
		$json_header["senderid"]=$user_id;
		$console_response=consoleCommand($json_header);			
		$response['consoleCommand_create'] = $console_response["message"];
		
		$groupid = $console_response["groupid"];//Get created groupid from console response
		
		$users=$app->request->post('users');	// reading post params
		
		//Console command to notify that some users in group has been changed
		$json_header=array();
		$json_header["console"]="v1/index/create_group";
		$json_header["operation"]=CONSOLE_OPERATION_GROUP;
		$json_header["group_operationid"]=GROUPOPERATION_ADD_USERS;
		$json_header["senderid"]=$user_id;
		$json_header["groupid"]=$groupid;
		$json_header["users"]=$users;
		$console_response=consoleCommand($json_header);
		
		$response['consoleCommand_add_users'] = $console_response["message"];
		
		$response['error'] = false;
		$response['message'] = "Group created and users added to group";
		$response['groupid'] = $groupid;
		$response['success'] = 1;
		
	} catch (Exception $e) {
	
		$response['error'] = true;
		$response['message'] = $e->getMessage();
		$response['success'] = 0;
	}
	
	echoResponse(200, $response);

});	

//-----------------Search-----------------------------------

/**
 * Search user by value
 * method POST
 * url - /search_user
 * param - string value
 * return - userid found user's id
 */
$app->post('/search_contact', 'authenticate', function () use ($app)  {
	
	// check for required params
    verifyRequiredParams(array('value'));
	
	$response = array();
	
	try{	
			$db = new DbHandlerProfile();
	        global $user_id;	
			
			// reading post params
			$value = $app->request->post('value');
	        			
			$found_userid=$db->searchUser($value);
			
			if($found_userid==NULL)throw new Exception("No user found");
			
			$response['userid'] = $found_userid;
	        $response['error'] = false;
	        $response['success'] = 1;
			$response['users'] = array();
			$response['users'][] = $db->getFriendById($user_id,$found_userid);
        
		} catch (Exception $e) {
        
	        $response['error'] = true;
	        $response['message'] = $e->getMessage()." value=".$value;
	     	$response['success'] = 0;
	}
	
	echoResponse(200, $response);

});	

//-----------------Console command------------------

//Operation numbers from WebsocketServer
define("CONSOLE_OPERATION_USER_CHANGED", 0);
define("CONSOLE_OPERATION_GROUP", 1);

define("GROUPOPERATION_ADD_USERS", 0);
define("GROUPOPERATION_SAVE", 1);
define("GROUPOPERATION_CREATE", 2);

function consoleCommand($header_json){

	$client = new WebsocketClient;
	
	$response="{'message': 'ConsoleCommand. begin'}";
	
	if($client->connect($header_json, '127.0.0.1', WEBSOCKET_SERVER_PORT,"/")){	
		
		$data = fread($client->_Socket, 1024);
		$message_array = $client->_hybi10Decode($data);//implode(",",);
		$response=$message_array["payload"];
	
	}else{
		$response="{'message':'ConsoleCommand. Connecting failed'}";
	}
	
	$client->disconnect();
	
	$json=(array)json_decode($response);
	
	return $json;

}


$app->run();

?>