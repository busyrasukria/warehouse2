<?php
require_once 'db.php';
session_start();

// Set header to return JSON
header('Content-Type: application/json');

// Check if form submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// Validate fields
$required = [
    'selected_parts_json',
    'released_by',
    'custom_date',
    'model',
    'num_copies'
];

foreach ($required as $f) {
    if (empty($_POST[$f])) {
        echo json_encode(['success' => false, 'message' => "Missing required field: $f"]);
        exit;
    }
}

// Clean common input
$parts_data = json_decode($_POST['selected_parts_json'], true);
$released_by_ids_string = trim($_POST['released_by']);
$custom_date = trim($_POST['custom_date']);
$model = trim($_POST['model']);
$num_copies = (int)$_POST['num_copies'];
if ($num_copies <= 0) $num_copies = 1;
$custom_display_quantity = (int)($_POST['quantity'] ?? 0);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($parts_data) || empty($parts_data)) {
    echo json_encode(['success' => false, 'message' => 'Invalid part data or no parts selected.']);
    exit;
}

try {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $custom_date)) {
        throw new Exception("Invalid date format. Expected Y-m-d.");
    }

    $today = date('Y-m-d');
    $time_part = ($custom_date == $today) ? date('H:i:s') : '08:01:00';
    $date_with_time = $custom_date . ' ' . $time_part;

    // ==========================================================
    // === Manpower Name Logic (Fetches names ONCE) ===
    // ==========================================================
    $released_by_names = '-';
    $runner_ids = [];

    if (!empty($released_by_ids_string)) {
        $runner_ids = array_filter(array_map('trim', explode(',', $released_by_ids_string)));
    }

    if (!empty($runner_ids)) {
        $placeholders = str_repeat('?,', count($runner_ids) - 1) . '?';
        $sql = "SELECT emp_id, name, nickname FROM manpower WHERE emp_id IN ($placeholders) ORDER BY FIELD(emp_id, $placeholders)";
        $stmt_names = $pdo->prepare($sql);
        $execute_params = array_merge($runner_ids, $runner_ids);
        $stmt_names->execute($execute_params);

        $runners_info = $stmt_names->fetchAll(PDO::FETCH_ASSOC);
        $runner_map = [];
        foreach ($runners_info as $row) {
            $runner_map[$row['emp_id']] = $row;
        }

        $names_array = [];
         foreach ($runner_ids as $id) {
            if (isset($runner_map[$id])) {
                $runner = $runner_map[$id];
                 if (!empty($runner['nickname'])) {
                     $names_array[] = $runner['nickname'];
                 } else {
                     $names_array[] = $runner['name'];
                 }
            } else {
                 $names_array[] = $id; 
            }
         }

        if (!empty($names_array)) {
            $released_by_names = implode(' / ', $names_array);
        }
    }
    // ==========================================================
    // === END: Manpower Name Logic ===
    // ==========================================================

    $pdo->beginTransaction();

    $inserted_ticket_ids = [];
    $is_batch = (count($parts_data) > 1);

    $stmt = $pdo->prepare("
        INSERT INTO transfer_tickets (
            unique_no, erp_code_FG, part_no_FG, part_name, model, prod_area, quantity, released_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt_get_id = $pdo->prepare("SELECT unique_no FROM transfer_tickets WHERE ticket_id = ?");

    // === MAIN PRINT LOOP ===
    for ($i = 1; $i <= $num_copies; $i++) {
        
        $shared_unique_no_for_this_copy = null;
        $is_first_part_of_this_copy = true;

        foreach ($parts_data as $part) {
            
            if (empty($part['erp']) || empty($part['partNo'])) {
                 throw new Exception("Incomplete part data received for batch.");
            }

            $erp_code_FG = trim($part['erp']);
            $part_no_FG = trim($part['partNo']);
            $part_name = trim($part['name']);
            $prod_area = trim($part['line'] ?? '');
            
            $quantity_to_save = ($custom_display_quantity > 0) ? $custom_display_quantity : max(1, (int)$part['stdQty']);
            
            $id_to_insert = null;
            
            if ($is_first_part_of_this_copy) {
                $id_to_insert = null; 
                $is_first_part_of_this_copy = false;
            } else {
                $id_to_insert = $shared_unique_no_for_this_copy;
            }

            $stmt->execute([
                $id_to_insert,
                $erp_code_FG,
                $part_no_FG,
                $part_name,
                $model,
                $prod_area,
                $quantity_to_save,
                $released_by_names,
                $date_with_time
            ]);
            
            $newly_inserted_db_id = $pdo->lastInsertId();
            $inserted_ticket_ids[] = $newly_inserted_db_id;

            // --- START: NEW TRACKING LOG CODE ---
            try {
                $stmt_log_status = $pdo->prepare(
                    "INSERT INTO ticket_status_log (ticket_id, status_code, status_message, scanned_by) 
                    VALUES (?, ?, ?, ?)"
                );
                $stmt_log_status->execute([
                    $newly_inserted_db_id,
                    'PRINTED',
                    'Ticket Printed',
                    $released_by_names // Log who "created" it
                ]);
            } catch (PDOException $e) {
                // Log this error, but don't stop the print process
                error_log("Failed to log initial ticket status: " . $e->getMessage());
            }
            // --- END: NEW TRACKING LOG CODE ---
            
            if ($id_to_insert === null) {
                $stmt_get_id->execute([$newly_inserted_db_id]);
                $shared_unique_no_for_this_copy = $stmt_get_id->fetchColumn();
            }
        }
    } // --- END of $num_copies loop ---

    $pdo->commit();

    // 5. Send SUCCESS JSON response
    $ids_string = implode(',', $inserted_ticket_ids);
    echo json_encode([
        'success' => true,
        'ticket_ids' => $ids_string,
        'model' => $model
    ]);
    exit;

} catch (PDOException $e) {
     if ($pdo->inTransaction()) {
       $pdo->rollBack();
    }
     error_log("Database Error in print_ticket.php: " . $e->getMessage());
     echo json_encode(['success' => false, 'message' => 'Database error saving ticket. Please contact admin.']);
     exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
       $pdo->rollBack();
    }
    error_log("Error in print_ticket.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
?>