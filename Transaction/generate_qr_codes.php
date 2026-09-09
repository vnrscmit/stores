<?php
session_start();
if(!isset($_SESSION['sessionadmin']))
{
    echo '<script language="JavaScript" type="text/JavaScript">';
    echo "window.location='../login.php' ";
    echo '</script>';
}
else
{
    $year1=$_SESSION['ayear1'];
    $year2=$_SESSION['ayear2'];
    $username= $_SESSION['username'];
    $yearid_id=$_SESSION['yearid_id'];
    $role=$_SESSION['role'];
    $loginid=$_SESSION['loginid'];
    $logid=$_SESSION['logid'];
}

require_once("../include/config.php");
require_once("../include/connection.php");

// Get classifications that have items
$classifications = array();
$query = "SELECT DISTINCT c.classification_id, c.classification 
          FROM tbl_classification c
          INNER JOIN tbl_stores s ON c.classification_id = s.classification_id
          ORDER BY c.classification";
$result = mysql_query($query, $link);
if ($result) {
    while ($row = mysql_fetch_assoc($result)) {
        $classifications[] = $row;
    }
}

// Handle AJAX request to get items by classification
if (isset($_GET['action']) && $_GET['action'] == 'get_items' && isset($_GET['classification_id'])) {
    $classification_id = intval($_GET['classification_id']);
    $query = "SELECT items_id, stores_item FROM tbl_stores WHERE classification_id = $classification_id AND actstatus='Active' ORDER BY stores_item";
    $result = mysql_query($query, $link);
    $items = array();
    if ($result) {
        while ($row = mysql_fetch_assoc($result)) {
            $items[] = $row;
        }
    }
    header('Content-Type: application/json');
    echo json_encode($items);
    exit;
}

// ============= PRINT HANDLER CODE =============
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['print_mode']) && $_POST['print_mode'] == 'true') {
    // Generate print-ready HTML with QR codes
    header('Content-Type: text/html; charset=utf-8');
    
    $financialYear = isset($_POST['financial_year']) ? $_POST['financial_year'] : '';
    $typeCode = isset($_POST['type_code']) ? $_POST['type_code'] : '11';
    $itemName = isset($_POST['item_name']) ? $_POST['item_name'] : '';
    $classification = isset($_POST['classification']) ? $_POST['classification'] : '';
    $uom = isset($_POST['uom']) ? $_POST['uom'] : '';
    
    // Gather QR codes from POST data
    $qrCodes = array();
    $qrWeights = array();
    $counter = 1;
    while(isset($_POST['qr_text_' . $counter])) {
        $qrCodes[] = $_POST['qr_text_' . $counter];
        $weight = isset($_POST['qr_weight_' . $counter]) ? $_POST['qr_weight_' . $counter] : '';
        $qrWeights[] = $weight;
        $counter++;
    }
    
    // Generate A4 print pages with QR slip grid
    ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>QR Code Print - <?php echo $classification; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            background: white;
        }
        
        .print-controls {
            text-align: center;
            padding: 15px;
            background: #f0f0f0;
            margin-bottom: 20px;
            border-bottom: 1px solid #ccc;
        }
        
        .print-btn {
            padding: 10px 30px;
            background: #4ea1e1;
            color: white;
            border: none;
            cursor: pointer;
            font-weight: bold;
            font-size: 16px;
            border-radius: 3px;
            margin-right: 10px;
        }
        
        .print-btn:hover {
            background: #2c6a9e;
        }
        
        .close-btn {
            padding: 10px 30px;
            background: #6c757d;
            color: white;
            border: none;
            cursor: pointer;
            font-weight: bold;
            font-size: 16px;
            border-radius: 3px;
        }
        
        .close-btn:hover {
            background: #5a6268;
        }
        
        .a4-page {
            width: 210mm;
            height: 297mm;
            margin: 20px auto;
            padding: 2mm;
            background: white;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            page-break-after: always;
        }
        
        .slip-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            grid-template-rows: repeat(6, 1fr);
            gap: 5mm;
            height: 100%;
        }

        .qr-slip {
            border: 2px solid #000;
            padding: 0;
            display: grid;
            grid-template-columns: 15mm 1fr 90px;
            grid-template-rows: repeat(3, 14mm);
            page-break-inside: avoid;
            background: white;
            height: 42mm;
            box-sizing: border-box;
            overflow: hidden;
        }

        .slip-row {
            display: contents;
        }

        .slip-label {
            font-weight: bold;
            color: #000;
            font-size: 10pt;
            padding: 1.5mm 2mm;
            white-space: nowrap;
            border-bottom: 1px solid #000;
            background: white;
            display: flex;
            align-items: center;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .slip-value {
            color: #000;
            font-size: 10pt;
            padding: 1.5mm 2.5mm;
            word-wrap: break-word;
            word-break: break-word;
            border-bottom: 1px solid #000;
            border-left: 1px solid #000;
            display: flex;
            align-items: center;
            font-weight: normal;
            overflow: hidden;
            line-height: 1.2;
        }

        .slip-label:nth-last-of-type(1) {
            border-bottom: none;
        }

        .slip-weight {
            border-bottom: none;
        }

        .slip-classification {
            display: none;
        }

        .slip-item {
            font-size: 11pt;
            color: #000;
            font-weight: normal;
        }

        .slip-details {
            display: contents;
        }

        .slip-qr-column {
            display: flex;
            flex-direction: column;
            grid-column: 3;
            grid-row: 1 / 4;
            padding: 1mm 0.5mm;
            gap: 0.2mm;
            align-items: center;
            justify-content: flex-start;
            border-left: 1px solid #000;
            overflow: hidden;
        }

        .slip-qr-image {
            width: 75px;
            height: 75px;
            border: 1px solid #333;
            flex-shrink: 0;
        }

        .slip-qr-text {
            font-family: 'Courier New', monospace;
            font-size: 8pt;
            font-weight: bold;
            color: #000;
            word-break: break-all;
            text-align: center;
            line-height: 1.8;
            width: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        
        .info-header {
            margin-bottom: 10mm;
            padding: 5mm;
            background: #f0f7ff;
            border: 1px solid #4ea1e1;
        }
        
        .info-header h3 {
            font-size: 12pt;
            margin-bottom: 3mm;
            color: #003333;
        }
        
        .info-row {
            display: flex;
            gap: 20mm;
            margin: 2mm 0;
            font-size: 9pt;
        }
        
        .info-label {
            font-weight: bold;
            width: 30mm;
        }
        
        @media print {
            .print-controls {
                display: none;
            }
            
            .info-header {
                display: none !important;
            }
            
            .a4-page {
                margin: 0;
                padding: 2mm;
                box-shadow: none;
                page-break-after: always;
            }
            
            .a4-page:last-child {
                page-break-after: avoid;
            }
            
            body {
                margin: 0;
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <div class="print-controls">
        <button class="print-btn" onclick="window.print()">🖨 Print</button>
        <button class="close-btn" onclick="window.close()">Close</button>
    </div>
    
    <?php
    // Split QR codes into pages (12 slips per A4 page = 2x6 grid)
    $pages = array_chunk($qrCodes, 12);
    $pageNum = 1;
    
    foreach($pages as $pageItems):
        $pageWeights = array_slice($qrWeights, ($pageNum-1)*12, 12);
    ?>
    <div class="a4-page">
        <div class="info-header">
            <h3><?php echo htmlspecialchars($classification) . ' - ' . htmlspecialchars($itemName); ?></h3>
            <div class="info-row">
                <div><span class="info-label">Type:</span> <?php echo htmlspecialchars($classification); ?></div>
                <div><span class="info-label">UoM:</span> <?php echo htmlspecialchars($uom); ?></div>
            </div>
        </div>
        
        <div class="slip-grid">
            <?php
            foreach($pageItems as $idx => $qrText):
                $weight = isset($pageWeights[$idx]) ? $pageWeights[$idx] : '';
                $srNo = substr($qrText, -5);
            ?>
            <div class="qr-slip">
                <div class="slip-row">
                    <div class="slip-label">Name:</div>
                    <div class="slip-value"><?php echo htmlspecialchars($classification); ?></div>
                </div>
                <div class="slip-row">
                    <div class="slip-label">Item:</div>
                    <div class="slip-value slip-item"><?php echo htmlspecialchars($itemName); ?></div>
                </div>
                <div class="slip-row">
                    <div class="slip-label">Wt.:</div>
                    <div class="slip-value slip-weight"><?php echo htmlspecialchars($weight ? $weight . ' kg' : ''); ?></div>
                </div>
                <div class="slip-qr-column">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=85x85&data=<?php echo urlencode($qrText); ?>" alt="QR Code" class="slip-qr-image" />
                    <div class="slip-qr-text"><?php echo htmlspecialchars($qrText); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
        $pageNum++;
    endforeach;
    ?>
    
</body>
</html>
    <?php
    exit;
}
// ============= END PRINT HANDLER CODE =============

// Get parameters from URL
$arrival_id = isset($_GET['arrival_id']) ? intval($_GET['arrival_id']) : 0;
$arrsub_id = isset($_GET['arrsub_id']) ? intval($_GET['arrsub_id']) : 0;
$classification_id = isset($_GET['classification_id']) ? intval($_GET['classification_id']) : 0;
$item_id = isset($_GET['item_id']) ? intval($_GET['item_id']) : 0;
$ups_good = isset($_GET['ups_good']) ? intval($_GET['ups_good']) : 0;

// Debug logging
error_log("generate_qr_codes.php - URL Parameters received: arrival_id=$arrival_id, arrsub_id=$arrsub_id, classification_id=$classification_id, item_id=$item_id, ups_good=$ups_good");

// Determine if we're in preview mode (after arrival saved) or form mode (before arrival saved)
$isFormMode = ($arrival_id == 0 || $arrival_id == '');

// Validate required parameters
// Classification, Item, and UPS Good are always required
if($classification_id == 0 || $item_id == 0 || $ups_good == 0) {
    error_log("ERROR: Invalid parameters in generate_qr_codes.php - GET params: " . json_encode($_GET));
    die('Error: Missing required parameters. Please reload the page and try again.');
}

// Fetch arrival details (if in preview mode)
$row_arrival = array();
// Convert year ID from format "25-26" to "2526"
$financial_year = str_replace('-', '', $yearid_id); // Convert 25-26 to 2526
if(!$isFormMode) {
    $sql_arrival = mysql_query("SELECT * FROM tblarrival WHERE arrival_id='$arrival_id'") or die(mysql_error());
    $row_arrival = mysql_fetch_array($sql_arrival);
    // Use financial year from arrival and convert format
    if(isset($row_arrival['financial_year'])) {
        $financial_year = str_replace('-', '', $row_arrival['financial_year']);
    }
    
    // If arrsub_id is 0 but arrival_id exists, fetch it from database
    // Find the arrsub_id for this arrival and item combination
    if($arrsub_id == 0 || $arrsub_id == '') {
        $sql_arrsub = mysql_query("SELECT arrsub_id FROM tblarrival_sub WHERE arrival_id='$arrival_id' AND item_id='$item_id' LIMIT 1") or die(mysql_error());
        if(mysql_num_rows($sql_arrsub) > 0) {
            $row_arrsub = mysql_fetch_array($sql_arrsub);
            $arrsub_id = $row_arrsub['arrsub_id'];
            error_log("DEBUG: Fetched arrsub_id=$arrsub_id from database for arrival_id=$arrival_id, item_id=$item_id");
        } else {
            error_log("WARNING: Could not find arrsub_id in database for arrival_id=$arrival_id, item_id=$item_id. This is unusual.");
        }
    }
}

// Fetch plantcode from tbl_parameters (default to company id=41)
$plantcode_query = "SELECT plantcode FROM tbl_parameters WHERE id=41 LIMIT 1";
$plantcode_result = mysql_query($plantcode_query, $link);
$plantcode = 'DEF';
if ($plantcode_result && $pr = mysql_fetch_assoc($plantcode_result)) {
    $plantcode = isset($pr['plantcode']) && !empty($pr['plantcode']) ? trim($pr['plantcode']) : 'DEF';
}

// Fetch classification details  
$sql_class = mysql_query("SELECT * FROM tbl_classification WHERE classification_id='$classification_id'") or die(mysql_error());
$row_class = mysql_fetch_array($sql_class);

// Get type code based on classification_type
$classification_type = isset($row_class['classification_type']) ? trim($row_class['classification_type']) : '';
$type_code = '11'; // Default to Roll
if($classification_type == 'Pouch' || $classification_type == 'Pouches') {
    $type_code = '12';
} elseif($classification_type == 'Sticker' || $classification_type == 'Stickers') {
    $type_code = '13';
}

// Fetch item details
$sql_item = mysql_query("SELECT * FROM tbl_stores WHERE items_id='$item_id'") or die(mysql_error());
$row_item = mysql_fetch_array($sql_item);

// Get next serial number from database (for continuing serial sequence)
// Build QR code prefix: plantcode + yearcode (4 digits) + type_code (2 digits)
// Example: "D" + "2526" + "11" = "D252611"
// Series continues GLOBALLY for all items of same TYPE within this year
$plantcode_query = "SELECT plantcode FROM tbl_parameters WHERE id=41 LIMIT 1";
$plantcode_result = mysql_query($plantcode_query, $link);
$plantcode = 'DEF';
if ($plantcode_result && $pr = mysql_fetch_assoc($plantcode_result)) {
    $plantcode = isset($pr['plantcode']) && !empty($pr['plantcode']) ? trim($pr['plantcode']) : 'DEF';
}

$qr_prefix = $plantcode . $financial_year . $type_code; // e.g., "D252611" for rolls in year 2526 with plantcode D

// Query: Find MAX serial for this TYPE (with correct plantcode prefix)
$sql_same_type = "SELECT MAX(CAST(RIGHT(qr_code_text, 5) AS UNSIGNED)) as last_serial 
                   FROM tbl_qr_codes 
                   WHERE qr_code_text LIKE '$qr_prefix%'";
$res_type = mysql_query($sql_same_type) or die(mysql_error());
$row_type = mysql_fetch_array($res_type);
$last_serial_value = isset($row_type['last_serial']) && $row_type['last_serial'] !== null ? intval($row_type['last_serial']) : 0;

// Calculate next serial: if we found previous codes, increment; otherwise start at 1
$next_serial = $last_serial_value > 0 ? $last_serial_value + 1 : 1;
$serial_start = $next_serial;

error_log("DEBUG: PREFIX=$qr_prefix, last_serial=$last_serial_value, next=$next_serial");

// Fetch SLOC details (if in preview mode and arrsub_id is provided)
$row_sloc = array();
$sloc_display = "N/A";
if(!$isFormMode && $arrsub_id > 0) {
    $sql_sloc = mysql_query("SELECT * FROM tblarr_sloc WHERE arr_tr_id='$arrival_id' AND arr_id='$arrsub_id'") or die(mysql_error());
    $row_sloc = mysql_fetch_array($sql_sloc);

    // Get warehouse, bin, subbin names
    if($row_sloc) {
        $sql_wh = mysql_query("SELECT perticulars FROM tbl_warehouse WHERE whid='".$row_sloc['whid']."'") or die(mysql_error());
        $row_wh = mysql_fetch_array($sql_wh);

        $sql_bin = mysql_query("SELECT binname FROM tbl_bin WHERE binid='".$row_sloc['binid']."' AND whid='".$row_sloc['whid']."'") or die(mysql_error());
        $row_bin = mysql_fetch_array($sql_bin);

        $sql_subbin = mysql_query("SELECT sname FROM tbl_subbin WHERE sid='".$row_sloc['subbin']."' AND binid='".$row_sloc['binid']."' AND whid='".$row_sloc['whid']."'") or die(mysql_error());
        $row_subbin = mysql_fetch_array($sql_subbin);

        $sloc_display = $row_wh['perticulars'] . " / " . $row_bin['binname'] . " / " . $row_subbin['sname'];
    }
}

?>
    
    // Output only the print view HTML for new window
    ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Generate QR Codes</title>
    <link href="../include/main.css" rel="stylesheet" type="text/css" />
    <link href="../include/vnrtrac.css" rel="stylesheet" type="text/css" />
    <style>
        .qr-container { max-width: 1200px; margin: 20px auto; padding: 20px; }
        .info-section { background: #f0f7ff; border: 1px solid #4ea1e1; padding: 15px; margin: 15px 0; }
        .info-row { display: flex; gap: 20px; margin: 8px 0; }
        .info-label { font-weight: bold; width: 150px; }
        .info-value { color: #333; flex: 1; }
        .button-group { text-align: center; margin: 20px 0; }
        .btn { padding: 10px 20px; margin: 5px; cursor: pointer; background: #4ea1e1; color: white; border: none; border-radius: 3px; font-size: 14px; }
        .btn:hover { background: #2c6a9e; }
        .btn-save { background: #28a745; }
        .btn-save:hover { background: #218838; }
        .btn-cancel { background: #6c757d; }
        .btn-cancel:hover { background: #5a6268; }
        .btn-print { background: #ff9800; }
        .btn-print:hover { background: #e68900; }
        
        /* Table Styles */
        .qr-table-section { margin-top: 30px; display: none; }
        .qr-table-section.visible { display: block; }
        .qr-table { width: 100%; border-collapse: collapse; border: 1px solid #4ea1e1; margin-top: 10px; }
        .qr-table thead { background: #4ea1e1; color: white; }
        .qr-table th { padding: 12px; text-align: left; border: 1px solid #4ea1e1; font-weight: bold; }
        .qr-table td { padding: 12px; border: 1px solid #4ea1e1; }
        .qr-table tbody tr:nth-child(even) { background: #f9f9f9; }
        .qr-table tbody tr:hover { background: #f0f7ff; }
        .qr-text-cell { font-family: monospace; font-weight: bold; color: #0066cc; font-size: 13px; }
        
        @media print {
            .button-group { display: none; }
            .qr-container { margin: 0; padding: 0; }
        }
    </style>
</head>
<body>

<div class="qr-container">
    <h2>Generate QR Codes</h2>
    
    <!-- Auto-filled Information Section -->
    <div class="info-section">
        <h3>Arrival Details</h3>
        <div class="info-row">
            <div class="info-label">Arrival ID:</div>
            <div class="info-value"><?php echo $arrival_id; ?></div>
        </div>
        <div class="info-row">
            <div class="info-label">Classification:</div>
            <div class="info-value"><?php echo $row_class['classification']; ?></div>
        </div>
        <div class="info-row">
            <div class="info-label">Item:</div>
            <div class="info-value"><?php echo $row_item['stores_item']; ?></div>
        </div>
        <div class="info-row">
            <div class="info-label">UoM:</div>
            <div class="info-value"><?php echo $row_item['uom']; ?></div>
        </div>
        <div class="info-row">
            <div class="info-label">Quantity (Good UPS):</div>
            <div class="info-value"><?php echo $ups_good; ?></div>
        </div>
    </div>

    <form method="POST" id="qrForm">
        <input type="hidden" name="arrival_id" value="<?php echo $arrival_id; ?>">
        <input type="hidden" name="arrsub_id" value="<?php echo $arrsub_id; ?>">
        <input type="hidden" name="classification_id" value="<?php echo $classification_id; ?>">
        <input type="hidden" name="item_id" value="<?php echo $item_id; ?>">
        <input type="hidden" name="ups_good" value="<?php echo $ups_good; ?>">
        <input type="hidden" name="financial_year" value="<?php echo $financial_year; ?>">
        <input type="hidden" name="type_code" value="<?php echo $type_code; ?>">
        <input type="hidden" name="serial_start" value="<?php echo $serial_start; ?>">
        <input type="hidden" name="classification" value="<?php echo $row_class['classification']; ?>">
        <input type="hidden" name="item_name" value="<?php echo $row_item['stores_item']; ?>">
        <input type="hidden" name="uom" value="<?php echo $row_item['uom']; ?>">
        <input type="hidden" name="plantcode" value="<?php echo $plantcode; ?>">>

        <div class="button-group">
            <button type="button" class="btn" onclick="generateQRCodes()">Generate QR Codes (<?php echo intval($ups_good); ?> codes)</button>
        </div>

        <!-- QR Table will be populated here -->
        <div id="qrTableSection" class="qr-table-section">
            <h3>Generated QR Codes</h3>
            <table class="qr-table">
                <thead>
                    <tr>
                        <th width="10%">SR No</th>
                        <th width="30%">QR Code Text</th>
                        <th width="30%">Item Details</th>
                        <th width="10%">Weight (kg)</th>
                    </tr>
                </thead>
                <tbody id="qrTableBody">
                </tbody>
            </table>
        </div>

        <div class="button-group" id="saveButtonGroup" style="display: none;">
            <button type="button" class="btn btn-print" onclick="submitPrint()">🖨 Print QR Codes</button>
            <button type="submit" class="btn btn-save">Save All QR Codes</button>
            <button type="button" class="btn btn-cancel" onclick="window.history.back();">Cancel</button>
        </div>
    </form>
</div>

<script>
// ============= PRINT FUNCTION =============
function submitPrint() {
    // Validation: Check if any weights are entered
    var allInputs = document.querySelectorAll('input[name^="qr_weight_"]');
    var hasWeight = false;
    allInputs.forEach(function(input) {
        if (input.value && parseFloat(input.value) > 0) {
            hasWeight = true;
        }
    });

    if (!hasWeight) {
        alert('❌ Please enter at least one weight before printing!');
        return false;
    }
    
    var form = document.getElementById('qrForm');    
    var formData = new FormData(form);    
    formData.append('print_mode', 'true');        
    
    var xhr = new XMLHttpRequest();    
    xhr.open('POST', '', true);    
    xhr.responseType = 'blob';        
    
    xhr.onload = function() {        
        if (xhr.status === 200) {            
            var blob = xhr.response;            
            var blobUrl = window.URL.createObjectURL(blob);            
            var printWindow = window.open(blobUrl, 'printWindow', 'width=1000,height=800,scrollbars=yes,resizable=yes');            
            if (printWindow) {                
                printWindow.focus();            
            } else {
                alert('Please allow popups for this site');
            }        
        } else {            
            alert('Error generating print page');        
        }    
    };        
    
    xhr.onerror = function() {
        alert('Error in print request');
    };
    
    xhr.send(formData);    
    return false;
}
// ============= END PRINT FUNCTION =============

function generateQRCodes() {
    const upsCount = parseInt(document.querySelector('input[name="ups_good"]').value);
    const financialYear = document.querySelector('input[name="financial_year"]').value;
    const typeCode = document.querySelector('input[name="type_code"]').value;
    const plantcode = document.querySelector('input[name="plantcode"]').value;
    const itemDetails = '<?php echo $row_item["stores_item"] . " (" . $row_item["uom"] . ")"; ?>';
    const slocDisplay = '<?php echo $sloc_display; ?>';
    const serialStart = <?php echo $serial_start; ?>;
    
    const qrTableBody = document.getElementById('qrTableBody');
    const qrTableSection = document.getElementById('qrTableSection');
    const saveButtonGroup = document.getElementById('saveButtonGroup');
    
    qrTableBody.innerHTML = '';

    for (let i = 1; i <= upsCount; i++) {
        // Generate QR Code Text: plantcode + yearcode (4 digits) + type_code (2 digits) + serial_number (5 digits)
        // Example: VSPL + 2526 (year) + 11 (Roll) + 00001 (serial) = VSPL252611000001
        const currentSerial = serialStart + i - 1;
        const serialNumber = String(currentSerial).padStart(5, '0');
        const qrText = plantcode + financialYear + typeCode + serialNumber;
        
        const row = document.createElement('tr');
        row.innerHTML = `
            <td style="text-align: center; font-weight: bold;">${serialNumber}</td>
            <td class="qr-text-cell">${qrText}</td>
            <td>${itemDetails}</td>
            <td><input type="text" name="qr_weight_${i}" style="width: 80%; padding: 5px; border: 1px solid #ccc;" placeholder="0.00"></td>
        `;
        qrTableBody.appendChild(row);

        // Create hidden input for QR text
        const hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'qr_text_' + i;
        hiddenInput.value = qrText;
        document.getElementById('qrForm').insertBefore(hiddenInput, qrTableSection);
    }
    
    // Show table and save buttons
    qrTableSection.classList.add('visible');
    saveButtonGroup.style.display = 'block';
}

// Form submission
document.getElementById('qrForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    // Check if ALL weights are entered
    var allInputs = document.querySelectorAll('input[name^="qr_weight_"]');
    var totalInputs = allInputs.length;
    var filledInputs = 0;
    var totalWeight = 0;
    
    allInputs.forEach(function(input) {
        if (input.value && parseFloat(input.value) > 0) {
            filledInputs++;
            totalWeight += parseFloat(input.value);
        }
    });

    if (filledInputs !== totalInputs) {
        alert('Please enter weight for ALL items before submitting!');
        return false;
    }
    
    const formData = new FormData(this);
    
    // Add total weight
    formData.append('total_weight', totalWeight);
    
    // Validate required parameters before submit
    const arrivalId = document.querySelector('input[name="arrival_id"]').value;
    const arrsubId = document.querySelector('input[name="arrsub_id"]').value;
    const classificationId = document.querySelector('input[name="classification_id"]').value;
    const itemId = document.querySelector('input[name="item_id"]').value;
    
    console.log('%c📋 QR Form Submission Debug:', 'color:purple;font-weight:bold;font-size:12px;');
    console.log('Extracted values:', {
        arrival_id: arrivalId,
        arrsub_id: arrsubId,
        classification_id: classificationId,
        item_id: itemId,
        ups_good: document.querySelector('input[name="ups_good"]').value
    });
    
    // Determine submit URL and required parameters
    let submitUrl = 'save_qr_codes.php'; // Default: preview mode
    let requiredParams = {classification_id: classificationId, item_id: itemId}; // Always required
    
    // If arrsub_id is 0 or empty, we MUST use form mode (temp save)
    // Preview mode (preview + save QR linking) requires arrsub_id
    if(arrivalId == 0 || arrivalId == '' || arrsubId == 0 || arrsubId == '') {
        submitUrl = 'save_qr_temp.php'; // Form mode - save QR codes without linking to arrival
        console.log('%c⚙️ Using FORM MODE (save_qr_temp.php) - no arrsub matching', 'color:orange;');
    } else {
        // Preview mode - add arrival IDs to required params
        requiredParams.arrival_id = arrivalId;
        requiredParams.arrsub_id = arrsubId;
        console.log('%c✅ Using PREVIEW MODE (save_qr_codes.php) - with arrsub linking', 'color:green;');
    }
    
    console.log('Submit URL:', submitUrl);
    console.log('Required Parameters:', requiredParams);
    
    // Check for missing required parameters
    const missingParams = [];
    Object.keys(requiredParams).forEach(param => {
        if(!requiredParams[param] || requiredParams[param] == 0) {
            missingParams.push(param);
        }
    });
    
    if(missingParams.length > 0) {
        alert('❌ Missing required parameters: ' + missingParams.join(', ') + '\n\nPlease check and try again.');
        console.error('Missing parameters:', missingParams);
        return false;
    }
    
    fetch(submitUrl, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if(data.success) {
            // Strategy 1: Try window.opener callback (Direct method)
            var callbackSuccess = false;
            try {
                if(window.opener && !window.opener.closed) {
                    if(typeof window.opener.totalWeightCallback === 'function') {
                        window.opener.totalWeightCallback(totalWeight);
                        callbackSuccess = true;
                    }
                }
            } catch(error) {
            }
            
            // Strategy 2: Fallback - Use sessionStorage (For popup communication)
            if(!callbackSuccess) {
                try {
                    sessionStorage.setItem('qr_total_weight', totalWeight);
                    sessionStorage.setItem('qr_weight_timestamp', new Date().getTime());
                } catch(error) {
                }
            }
            
            // Strategy 3: Show confirmation and close popup
            if(callbackSuccess) {
                // If callback worked, close immediately
                setTimeout(function() {
                    window.close();
                }, 300);
            } else {
                // If callback failed, show message and ask user to close
                alert('QR codes saved successfully!\n\nTotal Weight: ' + totalWeight.toFixed(2) + ' kg\n\nThe parent window should auto-fill. If not, please close this window and check the form.');
                setTimeout(function() {
                    window.close();
                }, 2000);
            }
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        alert('❌ Error saving QR codes: ' + error.message);
    });
});
</script>

</body>
</html>
