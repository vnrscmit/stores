<?php
session_start();
if(!isset($_SESSION['sessionadmin']))
{
    echo json_encode(['success' => false, 'message' => 'Session expired']);
    exit;
}

$username = $_SESSION['username'];
$created_by = $username; // Use username as created_by for matching
$sessionid = session_id();

require_once("../include/config.php");
require_once("../include/connection.php");

// Get POST data
$classification_id = isset($_POST['classification_id']) ? $_POST['classification_id'] : '';
$item_id = isset($_POST['item_id']) ? $_POST['item_id'] : '';
$ups_good = isset($_POST['ups_good']) ? $_POST['ups_good'] : 0;
$financial_year = isset($_POST['financial_year']) ? $_POST['financial_year'] : '';
$type_code = isset($_POST['type_code']) ? $_POST['type_code'] : '11';
$total_weight = isset($_POST['total_weight']) ? floatval($_POST['total_weight']) : 0;

error_log("save_qr_temp.php - Input: class_id=$classification_id, item_id=$item_id, ups_good=$ups_good, total_weight=$total_weight");

// Validate required parameters with detailed error messages
$missingParams = [];
if(!$classification_id) $missingParams[] = 'classification_id';
if(!$item_id) $missingParams[] = 'item_id';

if(count($missingParams) > 0) {
    $errorMsg = 'Missing required parameters: ' . implode(', ', $missingParams) . '. POST data received: ' . json_encode($_POST);
    error_log('save_qr_temp.php ERROR: ' . $errorMsg);
    echo json_encode(['success' => false, 'message' => 'Missing required parameters: ' . implode(', ', $missingParams)]);
    exit;
}

try {
    // Delete any existing draft QR codes for this item with same created_by user (cleanup old drafts)
    $delete_query = "DELETE FROM tbl_qr_codes WHERE classification_id='$classification_id' AND item_id='$item_id' AND linked_status='draft' AND created_by='$created_by' AND arrival_id=0";
    mysql_query($delete_query) or die(mysql_error());
    error_log("Deleted old draft QR codes for item_id=$item_id, created_by=$created_by");
    
    // Process each QR code
    $insertCount = 0;
    $qrCodes = array();
    
    for($i = 1; $i <= intval($ups_good); $i++) {
        $qr_text = isset($_POST['qr_text_'.$i]) ? trim($_POST['qr_text_'.$i]) : '';
        $weight = isset($_POST['qr_weight_'.$i]) ? floatval($_POST['qr_weight_'.$i]) : 0;
        
        if(!$qr_text) {
            error_log("WARNING: QR text missing for index $i");
            continue;
        }
        
        // Insert into tbl_qr_codes with draft status and created_by user
        // Use arrival_id=0 and arrsub_id=0 for draft (will be updated when arrival is posted)
        $now = date('Y-m-d H:i:s');
        $sql_insert = "INSERT INTO tbl_qr_codes (
            arrival_id,
            arrsub_id,
            classification_id, 
            item_id, 
            qr_code_text, 
            weight,
            created_by,
            linked_status,
            generated_date
        ) VALUES (
            0,
            0,
            '$classification_id', 
            '$item_id', 
            '$qr_text', 
            '$weight',
            '$created_by',
            'draft',
            '$now'
        )";
        
        if(!mysql_query($sql_insert)) {
            throw new Exception('Error inserting QR code: ' . mysql_error());
        }
        
        $insertCount++;
        $qrCodes[] = $qr_text;
        error_log("QR code inserted: $qr_text, weight=$weight");
    }
    
    if($insertCount == 0) {
        throw new Exception('No QR codes were inserted');
    }
    
    // Return success response with detailed info
    $response = [
        'success' => true, 
        'message' => "✓ $insertCount QR codes saved successfully (Draft status - will link to arrival when posted)", 
        'count' => $insertCount,
        'created_by' => $created_by,
        'total_weight' => floatval($total_weight),
        'qr_codes' => $qrCodes,
        'timestamp' => time()
    ];
    
    error_log("save_qr_temp.php - SUCCESS: Inserted $insertCount QR codes, total_weight=$total_weight");
    
    echo json_encode($response);
    
} catch(Exception $e) {
    $errorMsg = $e->getMessage();
    error_log("save_qr_temp.php - ERROR: " . $errorMsg);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $errorMsg]);
}
?>
