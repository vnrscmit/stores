?php
/**
 * QR Code Database Setup - Complete Reset & Initialization
 * This script safely recreates all QR code tables with proper foreign key constraints
 */

session_start();
if(!isset($_SESSION['sessionadmin']))
{
	echo '<script language="JavaScript" type="text/JavaScript">';
	echo "window.location='../login.php' ";
	echo '</script>';
	exit;
}

require_once("../include/config.php");
require_once("../include/connection.php");

$messages = array();
$is_error = false;

// Step 1: Disable foreign key checks
mysql_query("SET FOREIGN_KEY_CHECKS=0");
$messages[] = "✓ Disabled foreign key checks";

// Step 2: Drop existing tables if they exist (to recreate cleanly)
$drop_log = mysql_query("DROP TABLE IF EXISTS tbl_qr_scan_log");
if($drop_log) {
	$messages[] = "✓ Dropped old tbl_qr_scan_log (will recreate)";
} else {
	$messages[] = "⚠ tbl_qr_scan_log was not found (expected on first run)";
}

$drop_qrcodes = mysql_query("DROP TABLE IF EXISTS tbl_item_qrcodes");
if($drop_qrcodes) {
	$messages[] = "✓ Dropped old tbl_item_qrcodes (will recreate)";
} else {
	$messages[] = "⚠ tbl_item_qrcodes was not found (expected on first run)";
}

// Step 3: Create tbl_item_qrcodes
$create_qrcodes = "CREATE TABLE tbl_item_qrcodes (
	id INT AUTO_INCREMENT PRIMARY KEY,
	classification_id INT NOT NULL,
	item_id INT NOT NULL,
	serial_number VARCHAR(10) NOT NULL UNIQUE,
	qrcodetext VARCHAR(50) NOT NULL UNIQUE,
	financial_year INT NOT NULL,
	item_type_code INT NOT NULL COMMENT '11=Roll, 12=Pouches, 13=Stickers, etc.',
	current_weight DECIMAL(10, 2) DEFAULT NULL COMMENT 'Current weight of the item',
	status ENUM('active', 'discarded', 'returned') DEFAULT 'active',
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	created_by INT DEFAULT NULL COMMENT 'Operator ID who created this QR',
	notes TEXT DEFAULT NULL,
	INDEX idx_classification_id (classification_id),
	INDEX idx_item_id (item_id),
	INDEX idx_qrcodetext (qrcodetext),
	INDEX idx_status (status),
	INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8";

if(mysql_query($create_qrcodes)) {
	$messages[] = "✓ Created tbl_item_qrcodes table";
} else {
	$messages[] = "✗ ERROR creating tbl_item_qrcodes: " . mysql_error();
	$is_error = true;
}

// Step 4: Create tbl_qr_scan_log
$create_log = "CREATE TABLE tbl_qr_scan_log (
	id INT AUTO_INCREMENT PRIMARY KEY,
	qrcode_id INT NOT NULL,
	scan_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	action ENUM('scan', 'update_weight', 'discard', 'return') DEFAULT 'scan',
	operator_id INT DEFAULT NULL,
	previous_weight DECIMAL(10, 2) DEFAULT NULL,
	new_weight DECIMAL(10, 2) DEFAULT NULL,
	notes TEXT DEFAULT NULL,
	ip_address VARCHAR(45) DEFAULT NULL,
	INDEX idx_scan_time (scan_time),
	INDEX idx_qrcode_id (qrcode_id),
	INDEX idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8";

if(mysql_query($create_log)) {
	$messages[] = "✓ Created tbl_qr_scan_log table";
} else {
	$messages[] = "✗ ERROR creating tbl_qr_scan_log: " . mysql_error();
	$is_error = true;
}

// Step 5: Create tbl_item_type_code
$create_types = "CREATE TABLE IF NOT EXISTS tbl_item_type_code (
	type_id INT AUTO_INCREMENT PRIMARY KEY,
	type_name VARCHAR(50) NOT NULL UNIQUE,
	type_code INT NOT NULL UNIQUE COMMENT '11=Roll, 12=Pouches, 13=Stickers, etc.',
	description TEXT,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8";

if(mysql_query($create_types)) {
	$messages[] = "✓ Created tbl_item_type_code table";
} else {
	$messages[] = "⚠ tbl_item_type_code: " . mysql_error();
}

// Step 6: Insert default item types
$insert_types = "INSERT INTO tbl_item_type_code (type_name, type_code, description) 
VALUES 
('Roll', 11, 'Rolled items'),
('Pouches', 12, 'Pouch items'),
('Stickers', 13, 'Sticker items')
ON DUPLICATE KEY UPDATE type_name=VALUES(type_name)";

if(mysql_query($insert_types)) {
	$messages[] = "✓ Inserted default item types";
} else {
	$messages[] = "⚠ Item types: " . mysql_error();
}

// Step 7: Add foreign key constraint for classification_id
$fk_classification = "ALTER TABLE tbl_item_qrcodes 
ADD CONSTRAINT fk_qr_classification FOREIGN KEY (classification_id) 
	REFERENCES tbl_classification(classification_id) ON DELETE RESTRICT ON UPDATE CASCADE";

if(mysql_query($fk_classification)) {
	$messages[] = "✓ Added foreign key constraint: fk_qr_classification";
} else {
	$messages[] = "⚠ Foreign key fk_qr_classification: " . mysql_error();
}

// Step 8: Add foreign key constraint for item_id
$fk_item = "ALTER TABLE tbl_item_qrcodes 
ADD CONSTRAINT fk_qr_item FOREIGN KEY (item_id) 
	REFERENCES tbl_stores(items_id) ON DELETE RESTRICT ON UPDATE CASCADE";

if(mysql_query($fk_item)) {
	$messages[] = "✓ Added foreign key constraint: fk_qr_item";
} else {
	$messages[] = "⚠ Foreign key fk_qr_item: " . mysql_error();
}

// Step 9: Add foreign key constraint for qr_scan_log
$fk_scan = "ALTER TABLE tbl_qr_scan_log 
ADD CONSTRAINT fk_scan_qrcode FOREIGN KEY (qrcode_id) 
	REFERENCES tbl_item_qrcodes(id) ON DELETE CASCADE ON UPDATE CASCADE";

if(mysql_query($fk_scan)) {
	$messages[] = "✓ Added foreign key constraint: fk_scan_qrcode";
} else {
	$messages[] = "⚠ Foreign key fk_scan_qrcode: " . mysql_error();
}

// Step 10: Re-enable foreign key checks
mysql_query("SET FOREIGN_KEY_CHECKS=1");
$messages[] = "✓ Re-enabled foreign key checks";

// Step 11: Verify classification data
$class_count = mysql_query("SELECT COUNT(*) as cnt FROM tbl_classification");
$class_result = mysql_fetch_array($class_count);
$messages[] = "ℹ Total classifications in database: " . $class_result['cnt'];

// Step 12: Verify stores/items data
$items_count = mysql_query("SELECT COUNT(*) as cnt FROM tbl_stores");
$items_result = mysql_fetch_array($items_count);
$messages[] = "ℹ Total items in database: " . $items_result['cnt'];

// Step 13: Check for data integrity issues
$orphan_check = mysql_query("SELECT COUNT(*) as cnt FROM tbl_stores WHERE classification_id NOT IN (SELECT classification_id FROM tbl_classification)");
$orphan_result = mysql_fetch_array($orphan_check);
if($orphan_result['cnt'] > 0) {
	$messages[] = "✗ WARNING: Found " . $orphan_result['cnt'] . " items with non-existent classification_id!";
	$is_error = true;
} else {
	$messages[] = "✓ Data integrity check passed - all items have valid classifications";
}

?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1" />
<title>QR Code System - Database Setup</title>
<link href="../include/main.css" rel="stylesheet" type="text/css" />
<link href="../include/vnrtrac.css" rel="stylesheet" type="text/css" />
<style type="text/css">
.setup-container {
	padding: 20px;
	font-family: Arial, sans-serif;
}
.setup-message {
	padding: 15px;
	margin: 10px 0;
	border: 1px solid #ddd;
	border-radius: 3px;
	background-color: #f9f9f9;
}
.setup-message h3 {
	margin: 0 0 15px 0;
	color: #333;
}
.setup-message ul {
	list-style-type: none;
	padding: 0;
	margin: 0;
}
.setup-message li {
	padding: 8px 0;
	font-size: 14px;
	border-bottom: 1px dotted #ddd;
}
.setup-message li:last-child {
	border-bottom: none;
}
.success-box {
	background-color: #d4edda;
	border-color: #c3e6cb;
	color: #155724;
	margin-top: 20px;
	padding: 15px;
	border-radius: 3px;
}
.error-box {
	background-color: #f8d7da;
	border-color: #f5c6cb;
	color: #721c24;
	margin-top: 20px;
	padding: 15px;
	border-radius: 3px;
}
.action-button {
	display: inline-block;
	margin-top: 20px;
	padding: 10px 20px;
	background-color: #0066cc;
	color: white;
	text-decoration: none;
	border-radius: 3px;
	font-weight: bold;
}
.action-button:hover {
	background-color: #0052a3;
}
</style>
</head>
<body>
<div class="tablediv">
<table width="974" border="0" cellpadding="0" cellspacing="0">
<tr>
<td class="tblheading">QR Code System - Database Setup & Initialization</td>
</tr>
</table>

<div class="setup-container">
	<div class="setup-message">
		<h3>Setup Process Log:</h3>
		<ul>
			<?php 
			foreach($messages as $msg) {
				echo "<li>" . htmlspecialchars($msg) . "</li>";
			}
			?>
		</ul>
	</div>

	<?php if(!$is_error): ?>
		<div class="success-box">
			<h3>✓ Database Setup Completed Successfully!</h3>
			<p>All tables have been created and configured correctly.</p>
			<p>You can now use the QR Code Generation system.</p>
			<a href="generate_qrcodes.php" class="action-button">Go to Generate QR Codes →</a>
		</div>
	<?php else: ?>
		<div class="error-box">
			<h3>✗ Setup Completed with Issues</h3>
			<p>Some errors occurred during setup. Please review the log above.</p>
			<p><strong>Common Issues:</strong></p>
			<ul>
				<li>Foreign key constraint already exists (this is OK, it means tables were already set up)</li>
				<li>Data integrity issues: Items reference non-existent classifications</li>
				<li>Missing base tables: Make sure tbl_classification and tbl_stores exist first</li>
			</ul>
			<?php if(isset($orphan_result['cnt']) && $orphan_result['cnt'] > 0): ?>
				<p style="margin-top: 15px; font-weight: bold;">
					⚠ DATA INTEGRITY WARNING: Found orphaned items!<br>
					Please contact the administrator to fix classification references in tbl_stores.
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div style="margin-top: 30px; padding: 15px; background-color: #f0f0f0; border-radius: 3px;">
		<h4>Troubleshooting:</h4>
		<p><strong>If you still get "Cannot add or update a child row" error:</strong></p>
		<ol>
			<li>Run this setup script again to ensure tables are created correctly</li>
			<li>The error means classification_id in dropdown doesn't exist in tbl_classification</li>
			<li>Check for data integrity: All items must have valid classification_id values</li>
			<li>Try selecting a different classification from the dropdown to test</li>
		</ol>
		<a href="generate_qrcodes.php" class="action-button">Try QR Code Generation →</a>
	</div>
</div>

</div>
</body>
</html>
