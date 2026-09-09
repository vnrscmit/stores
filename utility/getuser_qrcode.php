<?php
	session_start();
	if(!isset($_SESSION['sessionadmin']))
	{
		echo '<option value="">Unauthorized</option>';
		exit;
	}
	
	require_once("../include/config.php");
	require_once("../include/connection.php");
	
	$action = isset($_GET['action']) ? $_GET['action'] : '';
	$classification_id = isset($_GET['classification']) ? intval($_GET['classification']) : 0;
	
	if($action == 'item' && $classification_id > 0)
	{
		$query = mysql_query("SELECT items_id, stores_item FROM tbl_stores WHERE classification_id=$classification_id ORDER BY stores_item");
		
		if($query)
		{
			echo '<option value="">--Select Item--</option>';
			while($row = @mysql_fetch_array($query))
			{
				echo '<option value="' . htmlspecialchars($row['items_id']) . '">' . htmlspecialchars($row['stores_item']) . '</option>';
			}
		}
		else
		{
			echo '<option value="">Error: ' . mysql_error() . '</option>';
		}
	}
	else
	{
		echo '<option value="">Invalid Request</option>';
	}
?>
