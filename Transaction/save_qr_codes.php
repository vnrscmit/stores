<?php
session_start();
if(!isset($_SESSION['sessionadmin']))
{
    echo json_encode(['success' => false, 'message' => 'Session expired']);
    exit;
}

$username = $_SESSION['username'];
$loginid = $_SESSION['loginid'];

require_once("../include/config.php");
require_once("../include/connection.php");

// Get POST data
$arrival_id = isset($_POST['arrival_id']) ? $_POST['arrival_id'] : '';
$arrsub_id = isset($_POST['arrsub_id']) ? $_POST['arrsub_id'] : '';
$classification_id = isset($_POST['classification_id']) ? $_POST['classification_id'] : '';
$item_id = isset($_POST['item_id']) ? $_POST['item_id'] : '';
$ups_good = isset($_POST['ups_good']) ? $_POST['ups_good'] : 0;
$total_weight = isset($_POST['total_weight']) ? floatval($_POST['total_weight']) : 0;

if(!$arrival_id || !$arrsub_id) {
    $missingParams = [];
    if(!$arrival_id) $missingParams[] = 'arrival_id';
    if(!$arrsub_id) $missingParams[] = 'arrsub_id';
    $errorMsg = 'Missing required parameters: ' . implode(', ', $missingParams) . '. POST data received: ' . json_encode($_POST);
    error_log('save_qr_codes.php ERROR: ' . $errorMsg);
    echo json_encode(['success' => false, 'message' => 'Missing required parameters: ' . implode(', ', $missingParams)]);
    exit;
}

try {
    // Process each QR code
    $insertCount = 0;
    for($i = 1; $i <= intval($ups_good); $i++) {
        $qr_text = isset($_POST['qr_text_'.$i]) ? $_POST['qr_text_'.$i] : '';
        $weight = isset($_POST['qr_weight_'.$i]) ? floatval($_POST['qr_weight_'.$i]) : 0;
        
        if(!$qr_text) continue;
        
        // Insert into tbl_qr_codes
        $now = date('Y-m-d H:i:s');
        $sql_insert = "INSERT INTO tbl_qr_codes (
            arrival_id, 
            classification_id, 
            item_id, 
            arrsub_id, 
            qr_code_text, 
            weight, 
            generated_date, 
            linked_status, 
            created_by
        ) VALUES (
            '$arrival_id', 
            '$classification_id', 
            '$item_id', 
            '$arrsub_id', 
            '$qr_text', 
            $weight, 
            '$now', 
            'linked', 
            '$username'
        )";
        
        if(!mysql_query($sql_insert)) {
            throw new Exception('Error inserting QR code: ' . mysql_error());
        }
        
        $qr_id = mysql_insert_id();
        $insertCount++;
    }
    
    // Update the arrival record quantity_good with total weight
    if($total_weight > 0 && $arrsub_id > 0) {
        $sql_update = "UPDATE tblarrival_sub SET qty_good = $total_weight WHERE arrsub_id = '$arrsub_id'";
        mysql_query($sql_update) or die(mysql_error());
    }
    
    echo json_encode(['success' => true, 'message' => "$insertCount QR codes saved successfully || Total Weight Updated: $total_weight kg", 'count' => $insertCount, 'total_weight' => $total_weight]);
    
} catch(Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
