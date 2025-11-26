<?php
require_once 'db.php';
require_once 'phpqrcode/qrlib.php';
session_start();

/**
 * Generates the HTML for ONE ticket.
 *
 * @param array $ticket Ticket data from transfer_tickets table
 * @param array $part Master part data from master table
 * @param string $runner_display The formatted string of runner names
 * @param bool $is_preview If true, will show "PREVIEW"
 * @return string HTML content for one ticket
 */
function generateTicketHTML($ticket, $part, $runner_display, $is_preview = false) {
    $qr_dir = 'qr_codes/';
    if (!is_dir($qr_dir)) {
        mkdir($qr_dir, 0777, true);
    }

    // --- 1. Prepare Data ---
    $ticket_date = '-';
    $ticket_shift = 'N/A';
    if (isset($ticket['created_at']) && strtotime($ticket['created_at']) !== false) {
        $ticket_datetime = strtotime($ticket['created_at']);
        $ticket_date = date('d/m/Y', $ticket_datetime); // Format for QR code
        $hour = date('H', $ticket_datetime);
        $ticket_shift = ($hour >= 8 && $hour < 20) ? 'Day' : 'Night';
    }

    $ticket_line = $part['line'] ?? $ticket['prod_area'] ?? '';
    $location = $part['location'] ?? '';
    $location_words = explode(' ', $location);
    $abbreviated_location = $location_words[0] ?? '';

    $erp_code_b = $part['erp_code_B'] ?? '';
    $part_no_b = $part['part_no_B'] ?? '';
    $erp_code_fg = $ticket['erp_code_FG'] ?? '';
    $part_no_fg = $ticket['part_no_FG'] ?? '';

    $unique_id_display = $is_preview ? "PREVIEW" : ($ticket['unique_no'] ?? 'N/A');
    $qr_file_src = 'logo.png';

// --- 2. Generate QR Code --- NEW QR DATA FORMAT: date|ticket_id|erp_code_fg|released_by|qty

$qr_data = "{$ticket_date}|{$ticket['unique_no']}|{$erp_code_fg}|{$runner_display}|{$ticket['quantity']}";

    // Check if we have all the required data for the new QR code
if (!$is_preview && $ticket_date !== '-' && !empty($ticket['unique_no']) && !empty($erp_code_fg) && !empty($runner_display) && isset($ticket['quantity'])) {
        // If we have data, TRY to generate the QR code
$qr_file = $qr_dir . "ticket_" . preg_replace('/[^a-zA-Z0-9_-]/', '_', $ticket['unique_no'] . '_' . $ticket['ticket_id']) . ".png";
try {
QRcode::png($qr_data, $qr_file, QR_ECLEVEL_L, 3, 1);
$qr_file_src = $qr_file;
} catch (Exception $e) {
 error_log("QR Code generation failed: " . $e->getMessage());
           
}
 } elseif (!$is_preview) {
        // If we are NOT in preview mode AND data was missing, log an error
error_log("Missing data for QR code generation: date={$ticket_date}, id={$ticket['unique_no']}, erp_fg={$erp_code_fg}, runner={$runner_display}, qty=" . ($ticket['quantity'] ?? 'NULL'));

}



    $display_part_name = htmlspecialchars($ticket['part_name'] ?? 'N/A');
    $display_model = htmlspecialchars($ticket['model'] ?? 'N/A');
    $display_runner = htmlspecialchars($runner_display ?? '-');
    $display_line = htmlspecialchars($ticket_line);
    $display_location = htmlspecialchars($abbreviated_location);
    $display_quantity = htmlspecialchars($ticket['quantity'] ?? '0');
    $display_erp_b = htmlspecialchars($erp_code_b ?: '-');
    $display_part_b = htmlspecialchars($part_no_b ?: '-');
    $display_erp_fg = htmlspecialchars($erp_code_fg ?: '-');
    $display_part_fg = htmlspecialchars($part_no_fg ?: '-');
    $display_date = date('d/m/Y', strtotime($ticket['created_at']));


    // --- 4. Generate UPDATED HTML Structure ---
    // Part Desc centered, ERP/Part No stacked
    $html = "
    <div class='ticket'>
        <div class='ticket-header'>
            <img src='logo.png' alt='Logo'>
            <h1>TRANSFER TICKET</h1>
            <span>ID: {$unique_id_display}</span>
        </div>
        <div class='ticket-body'>
            <div class='ticket-main-grid'>
                <div class='grid-row row-1'>
                    <div class='grid-cell'><span class='label'>Date</span>{$display_date}</div>
                    <div class='grid-cell'><span class='label'>Shift</span>{$ticket_shift}</div>
                    <div class='grid-cell'><span class='label'>Line</span>{$display_line}</div>
                    <div class='grid-cell'><span class='label'>Location + Ticket ID</span>{$display_location}-<br>{$unique_id_display}</div>
                </div>
                
                <div class='grid-row row-2'>
                    <div class='grid-cell cell-part-desc'><span class='label'>Part Name</span>{$display_part_name}</div>
                    <div class='grid-cell cell-stacked'> 
                        <div class='sub-cell'><span class='label'>ERP Code Bfr</span><br>{$display_erp_b}</div>
                        <div class='sub-cell no-border'><span class='label'>ERP Code FG</span><br>{$display_erp_fg}</div>
                    </div>
                </div>

                <div class='grid-row row-3'>
                    <div class='grid-cell cell-stacked'>
                        <div class='sub-cell'><span class='label'>Part No Bfr</span><br>{$display_part_b}</div>
                        <div class='sub-cell no-border'><span class='label'>Part No FG</span><br>{$display_part_fg}</div>
                    </div>
                    <div class='grid-cell'><span class='label'>Model</span>{$display_model}</div>
                    <div class='grid-cell'><span class='label'>Release By</span>{$display_runner}</div>
                </div>
            </div>
            <div class='ticket-side-panel'>
                <div class='qr-cell'>
                    " . ($is_preview ? "<span>PREVIEW</span>" : "<img src='{$qr_file_src}' alt='QR Code'>") . "
                </div>
                <div class='qty-cell'>
                    {$display_quantity}
                </div>
            </div>
        </div>
        <div class='ticket-footer'>
        </div>
    </div>
    ";
    
    return $html;
}

// --- PHP Logic for getting data (NO CHANGES) ---
// (The rest of the PHP code remains exactly the same as the previous version)
$print_content = '';
$ticket_quantity = 0; // Total number of tickets
$is_preview = false;
$auto_print = false;
$ticket_model = $_POST['model'] ?? $_GET['model'] ?? '';
$form_data = []; 

try {
    // CASE 1: PREVIEW
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['selected_parts_json'])) {
        $is_preview = true;
        $_SESSION['ticket_data'] = $_POST;
        $form_data = $_POST;

        $parts_data = json_decode($_POST['selected_parts_json'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($parts_data) || empty($parts_data)) {
            throw new Exception("Error decoding part data or no parts selected.");
        }

        $ticket_quantity = count($parts_data) * (int)($_POST['num_copies'] ?? 1);
        $print_content = '';

        $runner_ids_string = $_POST['released_by'] ?? '';
        $runner_display = "-"; 
        if (!empty($runner_ids_string)) {
            $runner_ids = array_filter(array_map('trim', explode(',', $runner_ids_string))); 
            if (!empty($runner_ids)) {
                 $id_placeholders = str_repeat('?,', count($runner_ids) - 1) . '?';
                 $sql = "SELECT emp_id, name, nickname FROM manpower WHERE emp_id IN ($id_placeholders) ORDER BY FIELD(emp_id, $id_placeholders)";
                 $stmt_names = $pdo->prepare($sql);
                 $execute_params = array_merge($runner_ids, $runner_ids);
                 $stmt_names->execute($execute_params);
                 $runners_info = $stmt_names->fetchAll(PDO::FETCH_ASSOC);
                 $names_array = [];
                 $runner_map = [];
                 foreach ($runners_info as $info) { $runner_map[$info['emp_id']] = $info; }
                 foreach ($runner_ids as $id) {
                     if (isset($runner_map[$id])) {
                         $runner = $runner_map[$id];
                         $names_array[] = !empty($runner['nickname']) ? $runner['nickname'] : $runner['name'];
                     } else { $names_array[] = $id; }
                 }
                if (!empty($names_array)) { $runner_display = implode(' / ', $names_array); }
            }
        }

        $custom_quantity = (int)($_POST['quantity'] ?? 0);

        for ($i = 0; $i < (int)($_POST['num_copies'] ?? 1); $i++) {
            foreach ($parts_data as $part_data) {
                if (empty($part_data['erp']) || empty($part_data['partNo']) || empty($part_data['name']) || empty($part_data['model']) || !isset($part_data['stdQty'])) {
                    error_log("Incomplete part data in JSON: " . print_r($part_data, true)); continue; 
                }
                $ticket = [ /* ... ticket data ... */
                    'unique_no' => 'PREVIEW', 'created_at' => $_POST['custom_date'] . ' ' . date('H:i:s'), 
                    'erp_code_FG' => $part_data['erp'], 'part_no_FG' => $part_data['partNo'],
                    'part_name' => $part_data['name'], 'model' => $part_data['model'],
                    'prod_area' => $part_data['line'] ?? '',
                    'quantity' => ($custom_quantity > 0) ? $custom_quantity : (int)$part_data['stdQty'],
                    'released_by' => $runner_display
                ];
                $stmt_part = $pdo->prepare("SELECT `line`, `location`, `erp_code_B`, `part_no_B` FROM master WHERE erp_code_FG = ?");
                $stmt_part->execute([$part_data['erp']]);
                $master_part_data = $stmt_part->fetch() ?: ['line'=>null, 'location'=>null, 'erp_code_B'=>null, 'part_no_B'=>null];
                $part_data_for_html = array_merge($part_data, $master_part_data); 
                $print_content .= generateTicketHTML($ticket, $part_data_for_html, $runner_display, true);
            }
        }
    // CASE 2: FINAL PRINT
    } elseif (isset($_GET['ticket_ids'])) { 
        $ticket_id_list = array_filter(array_map('intval', explode(',', $_GET['ticket_ids']))); 
        $auto_print = isset($_GET['print']);
        $print_content = '';
        $ticket_quantity = count($ticket_id_list);
        if ($ticket_quantity === 0) { throw new Exception("No valid ticket IDs provided."); }

        $ids_placeholders = str_repeat('?,', count($ticket_id_list) - 1) . '?';
        $stmt = $pdo->prepare("SELECT * FROM transfer_tickets WHERE ticket_id IN ($ids_placeholders) ORDER BY ticket_id ASC");
        $stmt->execute($ticket_id_list);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($tickets)) { throw new Exception("Tickets not found for the provided IDs."); }

        $erp_codes = array_unique(array_column($tickets, 'erp_code_FG'));
        $parts_map = [];
        if (!empty($erp_codes)) {
            $erp_placeholders = str_repeat('?,', count($erp_codes) - 1) . '?';
            $stmt_parts = $pdo->prepare("SELECT * FROM master WHERE erp_code_FG IN ($erp_placeholders)");
            $stmt_parts->execute($erp_codes);
            while($part_row = $stmt_parts->fetch(PDO::FETCH_ASSOC)) { $parts_map[$part_row['erp_code_FG']] = $part_row; }
        }

        foreach ($tickets as $ticket) {
            $runner_display = $ticket['released_by'];
            $part = $parts_map[$ticket['erp_code_FG']] ?? []; 
            $print_content .= generateTicketHTML($ticket, $part, $runner_display, false);
            $ticket_model = $ticket['model']; 
        }
    // CASE 3: SINGLE TICKET VIEW/REPRINT
    } elseif (isset($_GET['ticket_id'])) { 
        $ticket_id = (int)$_GET['ticket_id'];
         if ($ticket_id <= 0) { throw new Exception("Invalid Ticket ID provided."); }
        $auto_print = isset($_GET['print']);
        $stmt = $pdo->prepare("SELECT * FROM transfer_tickets WHERE ticket_id = ?");
        $stmt->execute([$ticket_id]);
        $ticket = $stmt->fetch();
        if (!$ticket) throw new Exception("Ticket not found");
        $stmt_part = $pdo->prepare("SELECT * FROM master WHERE erp_code_FG = ?");
        $stmt_part->execute([$ticket['erp_code_FG']]);
        $part = $stmt_part->fetch() ?: []; 
        $runner_display = $ticket['released_by'];
        $print_content = generateTicketHTML($ticket, $part, $runner_display, false);
        $ticket_quantity = 1; 
        $ticket_model = $ticket['model'];
    } else {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') { throw new Exception("Invalid form submission. `selected_parts_json` missing."); }
        die('Invalid access. Please provide ticket_id or ticket_ids.');
    }
} catch (Exception $e) {
    die("Error generating ticket: " . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Print Preview (<?php echo $is_preview ? 'CONFIRMATION' : 'FINAL'; ?>)</title>
<style>
    body { background-color: #525659; font-family: Arial, sans-serif; }
    .ticket {
        border: 1px dashed #999; background: #fff; width: 90mm; height: 60mm;
        margin: 4mm; padding: 2mm; display: inline-block; overflow: hidden;
        page-break-inside: avoid; display: flex; flex-direction: column; font-size: 7pt;
        box-sizing: border-box; 
    }
    .ticket-header { 
        display: flex; justify-content: space-between; align-items: center; 
        border-bottom: 1px solid black; padding-bottom: 1mm; flex-shrink: 0; 
    }
    .ticket-header img { height: 7mm; width: auto; }
    .ticket-header h1 { font-size: 10pt; font-weight: bold; margin: 0 10px; padding: 0; white-space: nowrap; flex-grow: 1; text-align: center;}
    .ticket-header span { font-size: 8pt; font-weight: bold; white-space: nowrap; } 
    
    .ticket-body { flex-grow: 1; display: grid; grid-template-columns: 2.7fr 1fr; border-bottom: 1px solid black; min-height: 0; overflow: hidden; }
    .ticket-main-grid { display: grid; grid-template-rows: auto 1fr auto; border-right: 1px solid black; min-width: 0; }
    .grid-row { display: grid; }
    .grid-row:not(:last-child) { border-bottom: 1px solid black; }
    .grid-cell { 
        padding: 0; border-right: 1px solid black; display: flex; flex-direction: column; 
        justify-content: center; align-items: center; font-weight: bold; text-align: center; 
        line-height: 1.1; word-break: break-word; overflow: hidden; position: relative;
    }
    .grid-cell:last-child { border-right: none; }
    
    .row-1 .grid-cell,
    .row-3 .grid-cell:not(:first-child) {
        padding: 0.5mm 1mm;
    }
    /* Part Desc specific padding and alignment */
    .cell-part-desc {
        padding: 1mm 1mm; /* More padding */
        justify-content: center; /* Center vertically */
        align-items: center; /* Center horizontally */
        font-size: 8pt; 
    }
    .cell-part-desc .label {
        margin-bottom: 0.5mm; /* More space below label */
    }


    .grid-cell .label { font-size: 6pt; font-weight: normal; color: #333; margin-bottom: 0.2mm; white-space: nowrap;}
    
    .sub-cell {
        width: 100%;
        padding: 0.5mm 1mm; 
        text-align: center;
        box-sizing: border-box; 
        border-bottom: 1px solid #ccc;
    }
    .sub-cell.no-border {
        border-bottom: none;
    }
    /* Style for stacked cells */
    .cell-stacked {
        justify-content: space-around; /* Distribute space */
        padding: 0; /* Remove parent padding */
    }
    .cell-stacked .sub-cell {
         line-height: 1.1; /* Adjust line height within sub-cells */
    }
     .cell-stacked .sub-cell span.label + br + * { 
        font-size: 7pt; /* Slightly smaller font for values */
        font-weight: bold;
     }


    .row-1 { grid-template-columns: 1.2fr 0.8fr 1fr 1.2fr; font-size: 7.5pt; } 
    .row-2 { grid-template-columns: 2fr 1.2fr; } 
    .row-3 { grid-template-columns: 1.5fr 1fr 1.2fr; font-size: 7.5pt;} 
    
    .ticket-side-panel { display: grid; grid-template-rows: 1fr auto; }
    .qr-cell { display: flex; justify-content: center; align-items: center; padding: 1mm; border-bottom: 1px solid black; }
    .qr-cell img { width: 100%; max-width: 18mm; height: auto; display: block; } 
    .qr-cell span { font-size: 10pt; font-weight: bold; color: #999; }
    .qty-cell { display: flex; justify-content: center; align-items: center; font-size: 22pt; font-weight: bold; padding: 0.5mm 0; } 
    .ticket-footer { padding: 0.5mm 1mm 0 1mm; text-align: right; font-size: 7pt; font-weight: bold; flex-shrink: 0; min-height: 1.5em; /* Ensure footer has height */ } 

    
    /* --- *** PRINT STYLES FOR THERMAL PRINTER *** --- */
    @media print {
        @page {
            size: 90mm 60mm; 
            margin: 0; 
        }
        body {
            margin: 0;
            background-color: #fff;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .print-controls { display: none; }
        .print-container { margin: 0; padding: 0; width: auto; }
        .ticket {
            border: 1px solid #000; 
            margin: 0; 
            width: 90mm; 
            height: 60mm; 
            overflow: hidden;
            box-sizing: border-box; 
        }
        html, body { overflow: hidden; }
    }
    /* --- *** END PRINT STYLES *** --- */


    .print-controls { max-width: 1000px; margin: 20px auto; padding: 15px; background: #fff; border-radius: 8px; text-align: center; }
    .print-button, .cancel-button {
        display: inline-block; text-decoration: none;
        color: white; padding: 12px 25px; border: none;
        border-radius: 5px; font-size: 1.2em; cursor: pointer; margin: 5px 10px;
    }
    .print-button { background-color: #007bff; }
    .cancel-button { background-color: #dc3545; }
    .back-button { background-color: #6c757d; }
</style>
</head>
<body>

    <div class="print-controls">
        <?php if ($is_preview): ?>
            <h2>Confirm Ticket Details</h2>
            <p>You are about to print <?php echo $ticket_quantity; ?> ticket(s). Click "Confirm & Print" to save and print.</p>

            <form method="POST" action="print_ticket.php" style="display: inline;">
                <?php foreach ($form_data as $key => $value): ?>
                    <input type="hidden" name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($value); ?>">
                <?php endforeach; ?>

                <a href="tt.php?model=<?php echo urlencode($ticket_model); ?>" class="cancel-button">
                    &#10006; Cancel
                </a>
                <button type="submit" class="print-button">
                    &#128424; Confirm & Print
                </button>
            </form>

        <?php else: // This is the FINAL print view ?>
            <h2>Print Preview</h2>
            <p>Showing <?php echo $ticket_quantity; ?> ticket(s). Click to print.</p>
            <button class="print-button" onclick="window.print()">
                &#128424; Print All Tickets
            </button>
             <a href="tt.php?model=<?php echo urlencode($ticket_model); ?>&status=viewed" class="back-button">
                &#8617; Back to Form
            </a>
        <?php endif; ?>
    </div>

    <div class="print-container">
        <?php echo $print_content; ?>
    </div>


<script>
window.addEventListener('load', function() {
    <?php if ($auto_print): ?>
    // This runs only on the *second* load (from tt.js)
    // Add a small delay to allow content (especially QR images) to load
    setTimeout(function() {
        window.print();
    }, 500); // 500ms delay
    <?php endif; ?>
});
</script>
</body>
</html>