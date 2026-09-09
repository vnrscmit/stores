<?php
session_start();
if(!isset($_SESSION['sessionadmin']))
{
    echo '';
    exit;
}

require_once("../include/config.php");
require_once("../include/connection.php");

// Get classification ID from POST
$classification_id = isset($_POST['classification_id']) ? $_POST['classification_id'] : '';

if(!$classification_id) {
    echo '';
    exit;
}

// Fetch classification type
$sql = mysql_query("SELECT classification_type FROM tbl_classification WHERE classification_id='$classification_id'") or die('');
$row = mysql_fetch_array($sql);

if($row && isset($row['classification_type'])) {
    echo trim($row['classification_type']);
} else {
    echo '';
}
?>
