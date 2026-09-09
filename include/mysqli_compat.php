<?php
/**
 * MySQLi Compatibility Layer for mysql_* functions
 * Provides backwards compatibility for legacy mysql_* code
 */

global $GLOBALS_mysqli_link;

// Override mysql_connect to use mysqli
function mysql_connect($server, $username, $password) {
    global $GLOBALS_mysqli_link;
    $GLOBALS_mysqli_link = mysqli_connect($server, $username, $password);
    if (!$GLOBALS_mysqli_link) {
        return false;
    }
    return $GLOBALS_mysqli_link;
}

// Override mysql_select_db to use mysqli
function mysql_select_db($database_name, $link = null) {
    global $GLOBALS_mysqli_link;
    if ($link === null) {
        $link = $GLOBALS_mysqli_link;
    }
    return mysqli_select_db($link, $database_name);
}

// Override mysql_query to use mysqli
function mysql_query($query, $link = null) {
    global $GLOBALS_mysqli_link;
    if ($link === null) {
        $link = $GLOBALS_mysqli_link;
    }
    return mysqli_query($link, $query);
}

// Override mysql_fetch_array to use mysqli
function mysql_fetch_array($result, $result_type = MYSQLI_BOTH) {
    return mysqli_fetch_array($result, $result_type);
}

// Override mysql_fetch_assoc to use mysqli
function mysql_fetch_assoc($result) {
    return mysqli_fetch_assoc($result);
}

// Override mysql_fetch_row to use mysqli
function mysql_fetch_row($result) {
    return mysqli_fetch_row($result);
}

// Override mysql_num_rows to use mysqli
function mysql_num_rows($result) {
    return mysqli_num_rows($result);
}

// Override mysql_affected_rows to use mysqli
function mysql_affected_rows($link = null) {
    global $GLOBALS_mysqli_link;
    if ($link === null) {
        $link = $GLOBALS_mysqli_link;
    }
    return mysqli_affected_rows($link);
}

// Override mysql_error to use mysqli
function mysql_error($link = null) {
    global $GLOBALS_mysqli_link;
    if ($link === null) {
        $link = $GLOBALS_mysqli_link;
    }
    return mysqli_error($link);
}

// Override mysql_errno to use mysqli
function mysql_errno($link = null) {
    global $GLOBALS_mysqli_link;
    if ($link === null) {
        $link = $GLOBALS_mysqli_link;
    }
    return mysqli_errno($link);
}

// Override mysql_insert_id to use mysqli
function mysql_insert_id($link = null) {
    global $GLOBALS_mysqli_link;
    if ($link === null) {
        $link = $GLOBALS_mysqli_link;
    }
    return mysqli_insert_id($link);
}

// Override mysql_real_escape_string to use mysqli
function mysql_real_escape_string($unescaped_string, $link = null) {
    global $GLOBALS_mysqli_link;
    if ($link === null) {
        $link = $GLOBALS_mysqli_link;
    }
    return mysqli_real_escape_string($link, $unescaped_string);
}

// Override mysql_result to use mysqli
function mysql_result($result, $row = 0, $field = 0) {
    if (!is_object($result) && !is_array($result)) {
        return false;
    }
    
    // If field is numeric, get the column by index
    if (is_numeric($field)) {
        mysqli_data_seek($result, $row);
        $row_data = mysqli_fetch_row($result);
        if ($row_data) {
            return $row_data[$field];
        }
    } else {
        // If field is string, get the column by name
        mysqli_data_seek($result, $row);
        $row_data = mysqli_fetch_assoc($result);
        if ($row_data && isset($row_data[$field])) {
            return $row_data[$field];
        }
    }
    return false;
}

// Override mysql_data_seek to use mysqli
function mysql_data_seek($result, $row_number) {
    return mysqli_data_seek($result, $row_number);
}

// Override mysql_free_result to use mysqli
function mysql_free_result($result) {
    return mysqli_free_result($result);
}

// Override mysql_fetch_lengths to use mysqli
function mysql_fetch_lengths($result) {
    // Get current row's field lengths - need to fetch a row first
    $row = mysqli_fetch_row($result);
    if (!$row) {
        return false;
    }
    // Return the lengths of the current row
    return array_map('strlen', $row);
}

// Override mysql_list_tables to use mysqli
function mysql_list_tables($database_name, $link = null) {
    global $GLOBALS_mysqli_link;
    if ($link === null) {
        $link = $GLOBALS_mysqli_link;
    }
    
    $result = mysqli_query($link, "SHOW TABLES FROM $database_name");
    if (!$result) {
        return false;
    }
    
    // Convert results to array format similar to mysql_list_tables
    $tables = array();
    while ($row = mysqli_fetch_row($result)) {
        $tables[] = $row[0];
    }
    return $tables;
}?>
