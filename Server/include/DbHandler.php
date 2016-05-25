<?php
 
/**
 * Parent class to db handlers
 *
 * @author Igor Ivanov
 */
 

class DbHandler {
 
    protected $conn;
 
	function __construct() {
        $a = func_get_args();
        $i = func_num_args();
		
        if (method_exists($this,$f='__construct'.$i)) {
            if($i==0){
				$this->__construct0();
			}else{
				call_user_func_array(array($this,$f),$a);
			}	
        }
    } 
	
	protected function __construct0() {
	
		require_once dirname(__FILE__) . '/DbConnect.php';
        // opening handler with new db-connection without options
        $db = new DbConnect();
        $this->conn = $db->connect();
        
    }
	
	protected function __construct1($arg_conn) {
		// opening handler with existing db-connection (meaning that $arg_conn has options)
        $this->conn = $arg_conn;        
    }
 
    
    
}
 
?>