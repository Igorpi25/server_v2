<?php
 
/**
 * Handling database connection
 */
class DbConnect {
 
    public $conn;
 
    function __construct() {        
    }
 
    /**
     * Establishing database connection without options (usual way)
     * @return database connection handler
     */
    public function connect() {
        include_once dirname(__FILE__) . '/Config.php';
         
        // Connecting to mysql database
        $this->conn = new mysqli(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME);
 
		// Check for database connection error
        if (mysqli_connect_errno()) {
            echo "Failed to connect to MySQL: " . mysqli_connect_error();
        } 
 	
		//Set timezone
		$this->setTimezone($this->conn);
        
        // returning connection resource
        return $this->conn;    
	}
	
	public static function setTimezone($conn){
		//Setting timezone to PHP
        date_default_timezone_set(TIMEZONE);
		
		//Setting time zone to MySQL
		$now = new DateTime();
		$mins = $now->getOffset()/60; 	
		$sgn = ($mins<0 ? -1 : 1);
		$mins = abs($mins);
		$hrs = floor($mins/60);
		$mins -=$hrs*60; 	
		$offset = sprintf('%+d:%02d', $hrs*$sgn, $mins); 
		$stmt = $conn->prepare("SET time_zone='$offset'");
			
		$stmt->execute();
		$stmt->close();
	}
	
}
 
?>