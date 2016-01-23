<?php
 
/**
 * Handling database connection
 */
class DbConnect {
 
    private $conn;
 
    function __construct() {        
    }
 
    /**
     * Establishing database connection
     * @return database connection handler
     */
    function connect() {
        include_once dirname(__FILE__) . '/Config.php';
 
 	//Setting timezone to PHP
        date_default_timezone_set(TIMEZONE);
        
        // Connecting to mysql database
        $this->conn = new mysqli(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME);
 
 	
 	//Setting time zone to MySQL
 	$now = new DateTime();
 	$mins = $now->getOffset()/60; 	
 	$sgn = ($mins<0 ? -1 : 1);
 	$mins = abs($mins);
 	$hrs = floor($mins/60);
 	$mins -=$hrs*60; 	
 	$offset = sprintf('%+d:%02d', $hrs*$sgn, $mins); 
 	$stmt = $this->conn->prepare("SET time_zone='$offset'");
        
 	$stmt->execute();
 	$stmt->close();
 	
 	//echo "offset=".$offset."\n";
 
 
        // Check for database connection error
        if (mysqli_connect_errno()) {
            echo "Failed to connect to MySQL: " . mysqli_connect_error();
        }
 
        // returing connection resource
        return $this->conn;
    }
 
}
 
?>