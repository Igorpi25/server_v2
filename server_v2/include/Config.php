<?php
/**
 * Database configuration
 */

//���� ����������� � ��������. ������� ����� �������� ������ ������ 

define('DB_USERNAME', 'chatdemo_user');//MySql user login
define('DB_PASSWORD', 'jdh65Akb');//Set password for mysql user
define('DB_HOST', 'localhost');//You should set here your  own host name. Ususally used 'localhost'
define('DB_NAME', 'chatdemo');//date-base name used in mysql
 
define('USER_CREATED_SUCCESSFULLY', 0);
define('USER_CREATE_FAILED', 1);
define('USER_ALREADY_EXISTED', 2);
define('TIMEZONE', 'UTC');

//Avatars constants
define("size_full",600);
define("size_avatar",300);
define("size_icon",120);
	
define("path_images",'/v2/images');
	
define("path_fulls", path_images.'/fulls/');
define("path_avatars", path_images.'/avatars/');
define("path_icons", path_images.'/icons/');

define("URL_HOME","http://igorpi25.ru");//set here your domen home url

?>
