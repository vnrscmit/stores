<?php 
  include("config.php");
  include("mysqli_compat.php");
    // Connect to Host //
	set_time_limit(0);
	error_reporting(E_ERROR | E_PARSE);
    $link = mysql_connect($db_host, $db_user, $db_pass) or die ('Not connected : ' . mysql_error());
    mysql_select_db($db_name, $link) or die ('Can\'t use $db_name : ' . mysql_error());
?>