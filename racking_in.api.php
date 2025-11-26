<?php
header('Content-Type: application/json');
require_once 'db.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

function send_json($data) { echo json_encode($data); exit; }

// Helper: Parse Tag ID to find Source Table and Original ID
function parse_tag_id($tag_id) {
    $tag_id = strtoupper(trim($tag_id));
    
    // 1. MARZ Logic: Starts with MZ, contains S (e.g., MZ789S3003)
    if (preg_match('/^MZ(\d+)S(\w+)$/', $tag_id, $matches)) {
        return ['type' => 'MARZ', 'table' => 'receiving_log_marz', 'id' => $matches[1], 'ref_no' => $matches[2]];
    }
    // 2. MAZDA Logic: Starts with M, contains S (e.g., M456S2002)
    elseif (preg_match('/^M(\d+)S(\w+)$/', $tag_id, $matches)) {
        return ['type' => 'MAZDA', 'table' => 'receiving_log_mazda', 'id' => $matches[1], 'ref_no' => $matches[2]];
    }
    // 3. YTEC Logic: Starts with R, contains J (e.g., R123J1001)
    elseif (preg_match('/^R(\d+)J(\w+)$/', $tag_id, $matches)) {
        return ['type' => 'YTEC', 'table' => 'receiving_log_ytec', 'id' => $matches[1], 'ref_no' => $matches[2]];
    }
    
    return null;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $_POST['action'] ?? $input['action'] ?? '';

try {
    // --- DELETE SCAN LOGIC ---
    if ($action === 'delete_scan') {
        $log_id = $input['log_id'] ?? '';
        $password = $input['password'] ?? '';
        $admin_password = "Admin404"; // Your specific password

        if (empty($log_id)) {
            send_json(['success' => false, 'message' => "Invalid ID."]);
        }

        if ($password !== $admin_password) {
            send_json(['success' => false, 'message' => "Incorrect Password."]);
        }

        // Delete from racking_in table
        $stmt = $pdo->prepare("DELETE FROM racking_in WHERE id = ?");
        $stmt->execute([$log_id]);

        if ($stmt->rowCount() > 0) {
            send_json(['success' => true, 'message' => "Record deleted successfully."]);
        } else {
            send_json(['success' => false, 'message' => "Record not found or already deleted."]);
        }
    }
    
    // --- FETCH / SCAN / MANUAL LOGIC ---
    if ($action === 'fetch_details' || $action === 'submit_scan' || $action === 'submit_manual') {
        
        // Use 'qr_data' for scan, 'ticket_id' for manual fetch
        $raw_id = strtoupper(trim($input['qr_data'] ?? $input['ticket_id'] ?? ''));
        
        if (empty($raw_id)) send_json(['success' => false, 'message' => "ID is required."]);

        // 1. Parse the ID
        $parsed = parse_tag_id($raw_id);
        if (!$parsed) {
            send_json(['success' => false, 'message' => "Invalid Tag Format. Must be YTEC (R..J..), Mazda (M..S..), or Marz (MZ..S..)."]);
        }

        // 2. Check Receiving Table
        $table = $parsed['table'];
        $rec_id = $parsed['id'];
        
        // Fetch data from the specific receiving log
        $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE id = ?");
        $stmt->execute([$rec_id]);
        $recData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$recData) {
            send_json(['success' => false, 'message' => "Ticket not found in Receiving ({$parsed['type']}). Cannot Rack In."]);
        }

        // 3. Prepare Data Structure
        // Note: Assuming receiving_log has columns: part_no, erp_code, part_name, seq_no, scan_time
        $response_data = [
            'unique_no' => $raw_id,
            'receiving_date' => date('Y-m-d', strtotime($recData['scan_time'])), // Date from receiving log
            'part_name' => $recData['part_name'],
            'part_no_fg' => $recData['part_no'],
            'erp_code' => $recData['erp_code'],
            'seq_no' => $recData['seq_no'],
            'rack_in' => $recData['qty'] // Assuming 'qty' in receiving is what goes to 'rack_in'
        ];

        // IF JUST FETCHING (Manual Modal)
        if ($action === 'fetch_details') {
            // Format for display
            $response_data['receiving_date_fmt'] = date('d/m/Y', strtotime($response_data['receiving_date']));
            send_json(['success' => true, 'data' => $response_data]);
        }

        // IF SUBMITTING (Scan or Manual Save)
        $location = strtoupper(trim($input['racking_location'] ?? 'MANUAL'));
        
        // Check Duplicate in Racking
        $check = $pdo->prepare("SELECT id FROM racking_in WHERE ID_CODE = ?");
        $check->execute([$raw_id]);
        if ($check->rowCount() > 0) {
            send_json(['success' => false, 'message' => "Ticket ($raw_id) already scanned into Rack."]);
        }

        // Insert
        $sql_ins = "INSERT INTO racking_in (ID_CODE, RECEIVING_DATE, DATE_IN, PART_NAME, PART_NO, ERP_CODE, SEQ_NO, RACK_IN, RACKING_LOCATION) 
                    VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?)";
        $stmt_ins = $pdo->prepare($sql_ins);
        $stmt_ins->execute([
            $raw_id,
            $response_data['receiving_date'],
            $response_data['part_name'],
            $response_data['part_no_fg'],
            $response_data['erp_code'],
            $response_data['seq_no'],
            $response_data['rack_in'],
            $location
        ]);

        // Return formatted row for table
        $newId = $pdo->lastInsertId();
        $formatted_row = [
            'log_id' => $newId,
            'scan_time' => date('d/m/Y H:i:s'),
            'unique_no' => $raw_id,
            'receiving_date' => date('d/m/Y', strtotime($response_data['receiving_date'])),
            'part_name' => $response_data['part_name'],
            'part_no' => $response_data['part_no_fg'],
            'erp_code' => $response_data['erp_code'],
            'seq_no' => $response_data['seq_no'],
            'rack_in' => $response_data['rack_in'],
            'racking_location' => $location
        ];

        send_json(['success' => true, 'message' => "Successfully Racked at $location", 'scanData' => $formatted_row]);
    }

    send_json(['success' => false, 'message' => 'Invalid Action']);

} catch (Exception $e) {
    send_json(['success' => false, 'message' => "Server Error: " . $e->getMessage()]);
}
?>