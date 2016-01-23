<?php

if(in_array('mod_rewrite', apache_get_modules())){
	echo "Yes";
}else echo "No";

print_r( apache_get_modules());





?>
