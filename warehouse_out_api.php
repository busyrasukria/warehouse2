<?php
header('Content-Type: application/json');
require_once 'db.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$action = $_GET['action'] ?? '';

// --- CONSTANTS ---
const PNE_REQUIRED_PARTS = [
    'AA021298', 'AA051297', 'AA031299', 'AC020059', 
    'AC050058', 'AC030060', 'AD020140', 'AD050142' 
];
const ALLOWED_TRIPS = ['TRIP_1', 'TRIP_2', 'TRIP_3', 'TRIP_4', 'TRIP_5', 'TRIP_6'];

// --- 1. DAILY TRIP RESET LOGIC (Server-Side) ---
function check_and_reset_daily_trips($pdo) {
    $today = date('Y-m-d');
    
    try {
        // Check the last reset date from DB
        $stmt = $pdo->query("SELECT last_reset_date FROM daily_reset_status WHERE id = 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $last_reset = $row['last_reset_date'] ?? '';

        if ($last_reset !== $today) {
            $pdo->beginTransaction();

            // 1. Reset all ACTUAL columns to 0
            $sql = "UPDATE master_trip SET 
                    ACTUAL_TRIP_1 = 0, ACTUAL_TRIP_2 = 0, ACTUAL_TRIP_3 = 0, 
                    ACTUAL_TRIP_4 = 0, ACTUAL_TRIP_5 = 0, ACTUAL_TRIP_6 = 0";
            $pdo->exec($sql);
            
            // 2. Update the reset status date
            $sql_status = "INSERT INTO daily_reset_status (id, last_reset_date) VALUES (1, :today)
                           ON DUPLICATE KEY UPDATE last_reset_date = :today";
            $stmt_status = $pdo->prepare($sql_status);
            $stmt_status->execute(['today' => $today]);

            $pdo->commit();
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // Silently log error so we don't break the app flow
        error_log("Daily Reset Failed: " . $e->getMessage());
    }
}

// Run reset check on every API call
check_and_reset_daily_trips($pdo);


function send_json($data) {
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

// --- HELPER: FIFO CHECK ---
function check_fifo_violation($pdo, $current_ticket_id, $erp_code, $current_prod_date, $is_pne_part) {
    $current_date_only = date('Y-m-d', strtotime($current_prod_date));

    // Check if there is an OLDER ticket (different ID) with SAME ERP that isn't scanned out yet
    $sql = "SELECT t.unique_no, wi.prod_date 
            FROM transfer_tickets t
            JOIN warehouse_in wi ON t.ticket_id = wi.transfer_ticket_id
            WHERE t.erp_code_FG = ? 
            AND DATE(wi.prod_date) < ? 
            AND t.ticket_id != ? 
            AND NOT EXISTS (
                SELECT 1 FROM warehouse_out wo 
                WHERE wo.unique_no = t.unique_no AND wo.erp_code = t.erp_code_FG
            )";

    if ($is_pne_part) {
        $sql .= " AND EXISTS (SELECT 1 FROM pne_warehouse_in pwi WHERE pwi.transfer_ticket_id = t.ticket_id)";
    }

    $sql .= " ORDER BY wi.prod_date ASC LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$erp_code, $current_date_only, $current_ticket_id]);
    $older_ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($older_ticket) {
        $older_date_fmt = date('d/m/Y', strtotime($older_ticket['prod_date']));
        $current_date_fmt = date('d/m/Y', strtotime($current_prod_date));
        throw new Exception("FIFO VIOLATION:\nTicket #{$older_ticket['unique_no']} ($older_date_fmt) is older than current ($current_date_fmt).\nPlease scan older ticket first.");
    }
}

// --- HELPER: VALIDATE TICKET ---
function validate_ticket_process($pdo, $unique_no, $erp_code) {
    // A. Find Ticket using BOTH unique_no AND erp_code
    $stmt = $pdo->prepare("SELECT ticket_id, released_by FROM transfer_tickets WHERE unique_no = ? AND erp_code_FG = ?");
    $stmt->execute([$unique_no, $erp_code]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        throw new Exception("Ticket #$unique_no (ERP: $erp_code) not found.");
    }
    $db_id = $ticket['ticket_id'];

    // B. Check Warehouse In
    $stmt_in = $pdo->prepare("SELECT prod_date FROM warehouse_in WHERE transfer_ticket_id = ?");
    $stmt_in->execute([$db_id]);
    $wh_in_row = $stmt_in->fetch(PDO::FETCH_ASSOC);
    
    if (!$wh_in_row) {
        throw new Exception("Ticket #$unique_no has NOT been scanned into Warehouse In.");
    }
    $current_prod_date = $wh_in_row['prod_date'];

    // C. Check PNE Flow
    $is_pne_part = in_array($erp_code, PNE_REQUIRED_PARTS);
    if ($is_pne_part) {
        $stmt_pne_out = $pdo->prepare("SELECT 1 FROM warehouse_out_pne WHERE transfer_ticket_id = ?");
        $stmt_pne_out->execute([$db_id]);
        if (!$stmt_pne_out->fetch()) throw new Exception("Ticket #$unique_no requires PNE.\nMissing 'Warehouse Out to PNE'.");
        
        $stmt_pne_in = $pdo->prepare("SELECT 1 FROM pne_warehouse_in WHERE transfer_ticket_id = ?");
        $stmt_pne_in->execute([$db_id]);
        if (!$stmt_pne_in->fetch()) throw new Exception("Ticket #$unique_no is at PNE.\nMissing 'PNE In'.");
    }

    // D. Check Duplicate Scan (Must match BOTH ID and ERP)
    // This allows same ID with different ERP to pass
    $stmt_dup = $pdo->prepare("SELECT 1 FROM warehouse_out WHERE unique_no = ? AND erp_code = ?");
    $stmt_dup->execute([$unique_no, $erp_code]); 
    if ($stmt_dup->fetch()) {
        throw new Exception("Ticket #$unique_no (ERP: $erp_code) already scanned out.");
    }
    
    // E. FIFO CHECK
    check_fifo_violation($pdo, $db_id, $erp_code, $current_prod_date, $is_pne_part);

    return $ticket;
}

// --- API ACTIONS ---
switch ($action) {
    case 'validate_ticket':
        try {
            $unique_no = $_POST['unique_no'] ?? '';
            $erp_code = $_POST['erp_code'] ?? '';
            if (!$unique_no || !$erp_code) send_json(['success' => false, 'message' => 'Missing QR data.']);
            
            validate_ticket_process($pdo, $unique_no, $erp_code);
            send_json(['success' => true, 'message' => 'Ticket Validated. Proceed.']);
        } catch (Exception $e) {
            send_json(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'submit_scan':
        try {
            $job = json_decode($_POST['job'] ?? '{}', true);
            $scan = json_decode($_POST['scan'] ?? '{}', true);
            
            // Parse Ticket QR: Date|ID|ERP|Release|Qty
            $tt_qr = $scan['scan_tt'] ?? '';
            $tt_parts = explode('|', $tt_qr);

            if (count($tt_parts) < 3) send_json(['success' => false, 'message' => "Invalid Ticket QR."]);
            
            $tt_id = $tt_parts[1]; // 00000528
            $tt_erp = $tt_parts[2]; // AD040157
            
            // Validate again before inserting
            $ticket_data = validate_ticket_process($pdo, $tt_id, $tt_erp);
            $db_ticket_id = $ticket_data['ticket_id'];

            // Pallet Parsing
            $pallet_parts = explode('|', $scan['scan_pallet'] ?? '');
            // Mazda Parsing
            $mazda_parts = explode('|', $scan['scan_mazda'] ?? '');

            $master_sql = "SELECT * FROM master_trip WHERE PART_NO = ? AND ERP_CODE = ? AND MODEL = ? AND `TYPE` = ? AND VARIANT = ?";
            $stmt_m = $pdo->prepare($master_sql);
            $stmt_m->execute([$pallet_parts[0], $pallet_parts[1], $job['model'], $job['type'], $job['variant']]);
            $master_part = $stmt_m->fetch(PDO::FETCH_ASSOC);

            if (!$master_part) send_json(['success' => false, 'message' => "Part not found in Trip Plan."]);

            $trip_col = $job['trip'];
            $actual_col = 'ACTUAL_' . $trip_col;
            
            // Trip Limit Check
            if ($master_part[$trip_col] > 0 && $master_part[$actual_col] >= $master_part[$trip_col]) {
                send_json(['success' => false, 'message' => "Trip Limit Reached for this part."]);
            }

            $pdo->beginTransaction();
            
            // 1. Update Trip Count
            $pdo->prepare("UPDATE master_trip SET $actual_col = COALESCE($actual_col, 0) + 1 WHERE id = ?")->execute([$master_part['id']]);
            
            // 2. Insert into Warehouse Out (Saving unique_no AND erp_code)
            $sql_ins = "INSERT INTO warehouse_out 
                        (master_trip_id, part_no, erp_code, trip, lot_no, msc_code, mazda_id, pallet_qr, ticket_qr, mazda_qr, unique_no) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql_ins)->execute([
                $master_part['id'], 
                $pallet_parts[0], 
                $pallet_parts[1], 
                $job['trip'],
                $mazda_parts[1], // Lot No from Mazda QR
                $mazda_parts[0], // MSC Code from Mazda QR
                ($mazda_parts[3] ?? 'N/A'), 
                $scan['scan_pallet'], 
                $scan['scan_tt'], 
                $scan['scan_mazda'],
                $tt_id // Save the ticket ID specifically
            ]);
            
            // 3. Log Status
            $scanned_by = $tt_parts[3] ?? 'Scanner';
            $pdo->prepare("INSERT INTO ticket_status_log (ticket_id, status_code, status_message, scanned_by) VALUES (?, 'CUSTOMER_OUT', 'Scanned Out to Customer', ?)")->execute([$db_ticket_id, $scanned_by]);
            
            $pdo->commit();

            // Fetch updated master row to send back to frontend
            $stmt_get = $pdo->prepare("SELECT * FROM master_trip WHERE id = ?");
            $stmt_get->execute([$master_part['id']]);
            
            send_json([
                'success' => true, 
                'message' => "Scan Successful!", 
                'updatedRow' => $stmt_get->fetch(PDO::FETCH_ASSOC)
            ]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            send_json(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'get_msc':
        try {
            $model = $_POST['model'] ?? ''; $variant = $_POST['variant'] ?? '';
            $stmt = $pdo->prepare("SELECT DISTINCT msc_code FROM variant_listing WHERE model = ? AND variant = ? ORDER BY msc_code");
            $stmt->execute([$model, $variant]);
            send_json(['success' => true, 'parts' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
        } catch (Exception $e) { send_json(['success' => false, 'parts' => []]); }
        break;

    case 'get_trip_plan':
        try {
            $type = $_POST['type'] ?? null; $model = $_POST['model'] ?? null; $variant = $_POST['variant'] ?? null; $trip = $_POST['trip'] ?? null;
            $params = []; $sql = "SELECT * FROM master_trip"; $where = [];
            if ($type) { $where[] = "TYPE = ?"; $params[] = $type; }
            if ($model) { $where[] = "MODEL = ?"; $params[] = $model; }
            if ($variant) { $where[] = "VARIANT = ?"; $params[] = $variant; }
            if ($trip && in_array($trip, ALLOWED_TRIPS)) { $where[] = "$trip > 0"; } else { send_json(['success' => true, 'parts' => []]); }
            if (count($where) > 0) { $sql .= " WHERE " . implode(" AND ", $where); }
            $sql .= " ORDER BY MODEL, TYPE, PART_NO";
            $stmt = $pdo->prepare($sql); $stmt->execute($params);
            send_json(['success' => true, 'parts' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (PDOException $e) {
            send_json(['success' => false, 'message' => $e->getMessage(), 'parts' => []]);
        }
        break;

    case 'get_scan_log':
         $sql = "SELECT l.*, mt.PART_DESCRIPTION as part_name, mt.MODEL as model, mt.TYPE as type, m.line as prod_area 
                 FROM warehouse_out l 
                 LEFT JOIN master_trip mt ON l.master_trip_id = mt.id 
                 LEFT JOIN master m ON l.part_no = m.part_no_FG AND l.erp_code = m.erp_code_FG 
                 ORDER BY l.scan_timestamp DESC LIMIT 50";
         $logs = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
         foreach($logs as &$log) {
             $parts = explode('|', $log['ticket_qr']);
             $log['scan_timestamp_formatted'] = date('d/m/Y H:i:s', strtotime($log['scan_timestamp']));
             // Fallback to QR parsing if unique_no in DB is empty
             $log['tt_id'] = !empty($log['unique_no']) ? $log['unique_no'] : ($parts[1] ?? '-');
             $log['prod_date_formatted'] = $parts[0] ?? '-';
             $log['released_by'] = $parts[3] ?? '-';
             $log['quantity'] = $parts[4] ?? 1;
             $log['part_no_fg'] = $log['part_no']; 
             $log['erp_code_fg'] = $log['erp_code'];
         }
         send_json(['success'=>true, 'logs'=>$logs]);
         break;

    case 'reset_entire_trip':
        try {
            $type = $_POST['type'] ?? ''; $model = $_POST['model'] ?? ''; 
            $var = $_POST['variant'] ?? ''; $trip = $_POST['trip'] ?? '';
            $col = "ACTUAL_".$trip;
            $pdo->prepare("UPDATE master_trip SET $col = 0 WHERE TYPE=? AND MODEL=? AND VARIANT=?")->execute([$type, $model, $var]);
            send_json(['success'=>true]);
        } catch (Exception $e) { send_json(['success'=>false, 'message'=>$e->getMessage()]); }
        break;
        
    default:
        send_json(['success' => false, 'message' => 'Invalid action.']);
        break;
}
?>