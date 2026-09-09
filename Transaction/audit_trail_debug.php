<?php
session_start();
if(!isset($_SESSION['sessionadmin']))
{
    echo '<script language="JavaScript" type="text/JavaScript">';
    echo "window.location='../login.php' ";
    echo '</script>';
}
require_once("../include/config.php");
require_once("../include/connection.php");

?>
<!DOCTYPE html>
<html>
<head>
<title>Audit Trail Debug</title>
<link href="../include/main.css" rel="stylesheet" type="text/css" />
<style>
.debug-box { background: #f0f0f0; padding: 15px; margin: 10px 0; border: 1px solid #999; }
.debug-title { background: #003366; color: white; padding: 10px; font-weight: bold; margin: 15px 0 10px 0; }
.table-debug { width: 100%; border-collapse: collapse; margin: 10px 0; }
.table-debug th { background: #4ea1e1; color: white; padding: 8px; text-align: left; }
.table-debug td { padding: 8px; border: 1px solid #ddd; }
.success { color: green; font-weight: bold; }
.error { color: red; font-weight: bold; }
</style>
</head>
<body>

<div style="margin: 20px; max-width: 1000px;">

<h2>🔍 Audit Trail Debugging</h2>

<div class="debug-title">1. ALL RECORDS in tbl_qr_scan_log (Last 50)</div>
<table class="table-debug">
<thead>
<tr>
<th>ID</th>
<th>QRCode ID</th>
<th>Action</th>
<th>Operator ID</th>
<th>Notes</th>
<th>Timestamp</th>
</tr>
</thead>
<tbody>
<?php
$query = "SELECT id, qrcode_id, action, operator_id, notes, scan_time FROM tbl_qr_scan_log ORDER BY scan_time DESC LIMIT 50";
$result = mysql_query($query, $link);
$count = mysql_num_rows($result);

if($count == 0) {
    echo "<tr><td colspan='6' style='text-align: center; padding: 20px;' class='error'>No records found in tbl_qr_scan_log</td></tr>";
} else {
    while($row = mysql_fetch_assoc($result)) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . $row['qrcode_id'] . "</td>";
        echo "<td><strong>" . $row['action'] . "</strong></td>";
        echo "<td>" . $row['operator_id'] . "</td>";
        echo "<td>" . htmlspecialchars(substr($row['notes'], 0, 100)) . "...</td>";
        echo "<td>" . $row['scan_time'] . "</td>";
        echo "</tr>";
    }
}
?>
</tbody>
</table>

<div class="debug-box">
<strong>Total Records Found:</strong> <span class="<?php echo $count > 0 ? 'success' : 'error'; ?>"><?php echo $count; ?></span>
</div>

<div class="debug-title">2. COUNT by Action Type</div>
<table class="table-debug">
<thead>
<tr>
<th>Action</th>
<th>Count</th>
</tr>
</thead>
<tbody>
<?php
$actions = array('scan', 'update_weight', 'discard', 'return');
foreach($actions as $action) {
    $count_query = "SELECT COUNT(*) as cnt FROM tbl_qr_scan_log WHERE action = '$action'";
    $count_result = mysql_query($count_query, $link);
    $count_row = mysql_fetch_assoc($count_result);
    echo "<tr>";
    echo "<td><strong>$action</strong></td>";
    echo "<td>" . $count_row['cnt'] . "</td>";
    echo "</tr>";
}
?>
</tbody>
</table>

<div class="debug-title">3. LINKING Actions (update_weight with 'Linked' in notes)</div>
<table class="table-debug">
<thead>
<tr>
<th>ID</th>
<th>QR ID</th>
<th>Notes</th>
<th>Timestamp</th>
</tr>
</thead>
<tbody>
<?php
$link_query = "SELECT id, qrcode_id, notes, scan_time FROM tbl_qr_scan_log 
               WHERE action = 'update_weight' AND notes LIKE '%Linked%'
               ORDER BY scan_time DESC LIMIT 20";
$link_result = mysql_query($link_query, $link);
$link_count = mysql_num_rows($link_result);

if($link_count == 0) {
    echo "<tr><td colspan='4' style='text-align: center; padding: 15px;' class='error'>No linking records found</td></tr>";
} else {
    while($row = mysql_fetch_assoc($link_result)) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . $row['qrcode_id'] . "</td>";
        echo "<td>" . htmlspecialchars($row['notes']) . "</td>";
        echo "<td>" . $row['scan_time'] . "</td>";
        echo "</tr>";
    }
}
?>
</tbody>
</table>

<div class="debug-box">
<strong>Linking Actions Found:</strong> <span class="<?php echo $link_count > 0 ? 'success' : 'error'; ?>"><?php echo $link_count; ?></span>
</div>

<div class="debug-title">4. Linked QR Codes in tbl_item_qrcodes</div>
<table class="table-debug">
<thead>
<tr>
<th>QR Code</th>
<th>Arrival ID</th>
<th>Weight</th>
<th>Status</th>
<th>Linked On</th>
</tr>
</thead>
<tbody>
<?php
$qr_query = "SELECT qrcodetext, arrival_id, arrival_weight, status, updated_at 
             FROM tbl_item_qrcodes 
             WHERE arrival_id IS NOT NULL AND arrival_id > 0
             ORDER BY updated_at DESC LIMIT 20";
$qr_result = mysql_query($qr_query, $link);
$qr_count = mysql_num_rows($qr_result);

if($qr_count == 0) {
    echo "<tr><td colspan='5' style='text-align: center; padding: 15px;' class='error'>No linked QR codes found</td></tr>";
} else {
    while($row = mysql_fetch_assoc($qr_result)) {
        echo "<tr>";
        echo "<td><strong>" . $row['qrcodetext'] . "</strong></td>";
        echo "<td>" . $row['arrival_id'] . "</td>";
        echo "<td>" . $row['arrival_weight'] . " kg</td>";
        echo "<td>" . $row['status'] . "</td>";
        echo "<td>" . $row['updated_at'] . "</td>";
        echo "</tr>";
    }
}
?>
</tbody>
</table>

<div class="debug-box">
<strong>Linked QR Codes Found:</strong> <span class="<?php echo $qr_count > 0 ? 'success' : 'error'; ?>"><?php echo $qr_count; ?></span>
</div>

<div class="debug-title">5. Recommendations</div>
<div class="debug-box">
<?php
if($qr_count > 0 && $link_count == 0) {
    echo "<span class='error'>⚠ QR codes ARE linked but audit trail is EMPTY!</span><br/>";
    echo "This means linking happened BEFORE we fixed the logging code.<br/>";
    echo "Solution: Create a NEW arrival and link fresh QR codes to generate audit entries.";
} else if($qr_count > 0 && $link_count > 0) {
    echo "<span class='success'>✓ Everything working! QRs linked with audit trail.</span>";
} else if($qr_count == 0) {
    echo "<span class='error'>✗ No QR codes have been linked yet.</span><br/>";
    echo "Next steps:<br/>";
    echo "1. Generate QR codes (Transaction → Arrival → Utility → Generate QRCode)<br/>";
    echo "2. Create a new Arrival receipt<br/>";
    echo "3. Link the QR codes during arrival submission<br/>";
    echo "4. Refresh this page to see the audit trail";
}
?>
</div>

<div style="margin-top: 30px;">
    <a href="qr_linking_report.php" style="padding: 10px 20px; background: #4ea1e1; color: white; text-decoration: none; border-radius: 3px;">← Back to QR Linking Report</a>
</div>

</div>

</body>
</html>
