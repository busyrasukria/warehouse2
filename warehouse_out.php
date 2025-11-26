<?php
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}
include 'layout/header.php';
$page_title = 'Warehouse Out';

// Fetch dropdown data
try {
    $types = $pdo->query("SELECT DISTINCT TYPE FROM master_trip ORDER BY TYPE")->fetchAll(PDO::FETCH_COLUMN);
    $models = $pdo->query("SELECT DISTINCT model FROM variant_listing ORDER BY model")->fetchAll(PDO::FETCH_COLUMN);
    $variants = $pdo->query("SELECT DISTINCT variant FROM variant_listing ORDER BY variant")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $models = []; $types = []; $variants = [];
}

// Fetch recent scans for the history table
$recentScans = [];
try {
    $stmt_log = $pdo->query("
        SELECT 
            l.log_id, l.scan_timestamp, l.ticket_qr, l.part_no, l.erp_code, l.unique_no,
            l.trip, l.lot_no, l.msc_code, l.mazda_id,
            mt.PART_DESCRIPTION AS part_name, mt.MODEL AS model,
            mt.TYPE AS type, mt.VARIANT AS variant,
            m.line AS prod_area
        FROM warehouse_out l
        LEFT JOIN master_trip mt ON l.master_trip_id = mt.id
        LEFT JOIN master m ON l.part_no = m.part_no_FG AND l.erp_code = m.erp_code_FG
        ORDER BY l.scan_timestamp DESC LIMIT 50
    ");
    $recentScans = $stmt_log->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* Ignore */ }
?>

<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">

<div class="container mx-auto px-6 py-10">

    <div class="bg-gradient-to-r from-indigo-600 via-purple-600 to-indigo-500 rounded-xl shadow-lg px-6 py-4 mb-6 text-white flex justify-between items-center">
        <div>
            <h2 class="text-xl md:text-2xl font-bold flex items-center space-x-3">
                <i data-lucide="scan-line" class="w-8 h-8"></i>
                <span>Warehouse Out - Scan Parts</span>
            </h2>
            <p class="text-purple-100 text-sm mt-1">
                Final checkpoint before shipment.
            </p>
        </div>
        <button id="toggleScanLogBtn" class="bg-white/20 hover:bg-white/30 text-white font-semibold py-2 px-4 rounded-lg transition-all duration-200 flex items-center gap-2">
            <i data-lucide="history" class="w-5 h-5"></i>
            <span>Hide Log</span>
        </button>
    </div>

    <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-4 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4">
            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Type</label><select id="job_type" placeholder="Select Type"><option value="">Select Type</option><?php foreach ($types as $t) echo "<option value='$t'>$t</option>"; ?></select></div>
            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Model</label><select id="job_model" placeholder="Select Model"><option value="">Select Model</option><?php foreach ($models as $m) echo "<option value='$m'>$m</option>"; ?></select></div>
            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Trip</label><select id="job_trip" placeholder="Select Trip"><option value="">Select Trip</option><option value="TRIP_1">TRIP 1</option><option value="TRIP_2">TRIP 2</option><option value="TRIP_3">TRIP 3</option><option value="TRIP_4">TRIP 4</option><option value="TRIP_5">TRIP 5</option><option value="TRIP_6">TRIP 6</option></select></div>
            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Lot No</label><input type="text" id="job_lot_no" placeholder="Enter Lot No" class="w-full px-3 py-3 border rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none transition-all"></div>
            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Variant</label><select id="job_variant" placeholder="Select Variant"><option value="">Select Variant</option><?php foreach ($variants as $v) echo "<option value='$v'>$v</option>"; ?></select></div>
            <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">MSC Code</label><select id="job_msc_code" placeholder="Select MSC Code" disabled></select></div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">

        <div class="lg:col-span-2">
            <div class="bg-white rounded-xl shadow-lg overflow-hidden border border-gray-200">
                <div class="bg-gradient-to-r from-indigo-600 via-purple-600 to-indigo-500 px-5 py-4 border-b border-gray-200 flex justify-between items-center">
                    <h2 class="text-white text-lg font-bold flex items-center gap-3">
                        <i data-lucide="qr-code" class="w-6 h-6"></i>
                        Scanner
                    </h2>
                </div>
                
                <div class="p-5 flex justify-center gap-3 bg-gray-50 border-b border-gray-100">
                   <button id="startScanBtn" class="bg-green-500 hover:bg-green-600 text-white font-bold py-3 px-6 rounded-lg shadow-lg flex items-center justify-center gap-2 transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed">
                        <i data-lucide="play-circle" class="w-5 h-5"></i>
                        <span>START SCAN </span>
                    </button>
                    
                    <button id="stopScanBtn" class="bg-red-500 hover:bg-red-600 text-white font-bold py-3 px-6 rounded-lg shadow-lg flex items-center justify-center gap-2 transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed">
                        <i data-lucide="stop-circle" class="w-5 h-5"></i>
                        <span>STOP SCAN</span>
                    </button>
                </div>

                <div class="p-5 space-y-6">
                    <div class="scan-step relative" data-step="1">
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1">1. Pallet QR</label>
                        <div class="relative">
                            <input type="text" id="scan_1_pallet" class="scan-input w-full pl-10 pr-10 py-3 border-2 border-gray-200 rounded-xl text-lg font-mono focus:border-indigo-500 focus:ring-0 transition-colors" placeholder="Scan Pallet..." disabled>
                            <i data-lucide="box" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 w-5 h-5"></i>
                            <div id="status_1" class="status-icon absolute right-3 top-1/2 -translate-y-1/2"></div>
                        </div>
                    </div>

                    <div class="scan-step relative" data-step="2">
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1">2. Transfer Ticket QR</label>
                        <div class="flex gap-2">
                            <div class="relative flex-grow">
                                <input type="text" id="scan_2_tt" class="scan-input w-full pl-10 pr-10 py-3 border-2 border-gray-200 rounded-xl text-lg font-mono focus:border-indigo-500 focus:ring-0 transition-colors" placeholder="Scan Ticket..." disabled>
                                <i data-lucide="ticket" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 w-5 h-5"></i>
                                <div id="status_2" class="status-icon absolute right-3 top-1/2 -translate-y-1/2"></div>
                            </div>
                            <button id="manualTTBtn" class="bg-gray-700 hover:bg-gray-800 text-white p-3 rounded-xl border-2 border-gray-600 transition-all shadow-md disabled:opacity-50 disabled:cursor-not-allowed" title="Manual Entry" disabled>
                                <i data-lucide="keyboard" class="w-6 h-6"></i>
                            </button>
                        </div>
                    </div>

                    <div class="scan-step relative" data-step="3">
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1">3. Mazda QR</label>
                        <div class="relative">
                            <input type="text" id="scan_3_mazda" class="scan-input w-full pl-10 pr-10 py-3 border-2 border-gray-200 rounded-xl text-lg font-mono focus:border-indigo-500 focus:ring-0 transition-colors" placeholder="Scan Mazda Label..." disabled>
                            <i data-lucide="qr-code" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 w-5 h-5"></i>
                            <div id="status_3" class="status-icon absolute right-3 top-1/2 -translate-y-1/2"></div>
                        </div>
                    </div>
                </div>
                <div id="scanResult" class="text-white bg-gradient-to-r from-indigo-600 via-purple-600 to-indigo-500 px-6 py-3 text-center font-semibold">Scan Standby</div>

            </div>
        </div>

        <div class="lg:col-span-3">
            <div id="masterTripContainer" class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
                <h2 class="text-lg font-bold text-white bg-gradient-to-r from-indigo-600 via-purple-600 to-indigo-500 border-b border-purple-700 px-6 py-4 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <i data-lucide="clipboard-check" class="w-6 h-6 text-purple-200"></i>
                        <span>Trip Plan Status</span>
                    </div>
                    
                    <div class="flex items-center gap-3">
                        <div id="tripActionsContainer" class="flex items-center gap-3 min-h-[32px]">
                        </div>
                    </div>
                </h2>
                
                <div class="overflow-x-auto custom-scrollbar" style="max-height: 70vh;">
                    <table id="scanHistoryTable" class="w-full text-sm">
                        
                        <thead class="bg-gray-50 sticky top-0 z-10"> 
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600">Model</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600">Type</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600">Part No</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600">Part Desc</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-600">Plan</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-600">Scanned</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-600">Remaining</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600">Trip Status</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600">Overall Progress</th>
                            </tr>
                        </thead>
                        
                        <tbody id="masterTripTableBody" class="bg-white divide-y divide-gray-200">
                            <tr><td colspan="9" class="p-6 text-center text-gray-500">Loading trip data...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div id="scanLogContainer" class="mt-8 animate-fade-in">
        <div class="bg-white rounded-2xl shadow-2xl border border-gray-200 overflow-hidden">
            <div class="bg-gradient-to-r from-indigo-600 via-purple-600 to-indigo-500 px-6 py-4 flex flex-col md:flex-row justify-between md:items-center gap-4">
                <h3 class="text-lg font-bold text-white flex items-center gap-2"><i data-lucide="history" class="w-5 h-5 text-purple-200"></i> Recent Warehouse Out History</h3>
                
                <div class="flex flex-wrap gap-2">
                    <div class="relative">
                         <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                         <input type="text" id="historySearchInput" placeholder="Search..." class="pl-9 pr-4 py-1.5 rounded-lg text-sm border-0 focus:ring-2 focus:ring-purple-300 w-48">
                    </div>
                    <input type="date" id="historyDateInput" class="px-3 py-1.5 rounded-lg text-sm border-0 focus:ring-2 focus:ring-purple-300 text-gray-700">
                    <select id="historyModelFilter" class="px-3 py-1.5 rounded-lg text-sm border-0 focus:ring-2 focus:ring-purple-300 text-gray-700">
                        <option value="">All Models</option>
                        <?php foreach ($models as $m) echo "<option value='$m'>$m</option>"; ?>
                    </select>
                    
                    <button id="refreshHistoryBtn" class="bg-white/20 hover:bg-white/30 text-white p-1.5 rounded-lg transition-colors" title="Refresh Table"><i data-lucide="refresh-cw" class="w-5 h-5"></i></button>
                    
                    <a href="#" id="downloadCsvBtn" class="bg-emerald-500 hover:bg-emerald-600 text-white p-1.5 rounded-lg transition-colors flex items-center gap-1 px-3" title="Download CSV">
                        <i data-lucide="download" class="w-4 h-4"></i> <span class="text-xs font-bold">CSV</span>
                    </a>
                </div>
            </div>

            <div class="overflow-x-auto custom-scrollbar" style="max-height: 100vh;">
                <table id="scanHistoryTable" class="w-full text-sm">
                    <thead class="bg-gray-100 sticky top-0">
                        <tr class="bg-blue-800 text-white">
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Date/Time</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase">TT ID</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Prod Date</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Part Name</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Part No</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase">ERP</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Model</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Area</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Line</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Qty</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Released By</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Mazda ID</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase">MSC</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Trip</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Lot No</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Action</th>
                        </tr>
                    </thead>
                    <tbody id="scanLogTableBody" class="divide-y divide-gray-200 bg-white">
    <?php if (empty($recentScans)): ?>
        <tr><td colspan="16" class="p-6 text-center text-gray-500">No scans recorded yet.</td></tr>
    <?php else: ?>
        <?php foreach ($recentScans as $row): 
            // 1. Parse QR Data (Matches your JS logic)
            $parts = explode('|', $row['ticket_qr'] ?? '');
            
            // 2. Format Date
            $scanDate = date('d/m/Y H:i:s', strtotime($row['scan_timestamp']));

            // 3. Determine Ticket ID (Prefer Database ID, fallback to QR parse)
            $tt_id = (!empty($row['unique_no']) && $row['unique_no'] !== 'NULL') 
                     ? $row['unique_no'] 
                     : ($parts[1] ?? '-');

            // 4. Extract other fields from QR if not in DB
            $prod_date = $parts[0] ?? '-';
            $erp_code = $row['erp_code'] ?? ($parts[2] ?? '-');
            $released_by = $parts[3] ?? '-';
            $qty = $parts[4] ?? 1;
        ?>
        <tr class="hover:bg-indigo-50 transition-colors" data-log-id="<?php echo $row['log_id']; ?>">
            <td class="px-4 py-3 text-gray-600"><?php echo $scanDate; ?></td>
            <td class="px-4 py-3 font-mono text-blue-600 font-bold"><?php echo htmlspecialchars($tt_id); ?></td>
            <td class="px-4 py-3 text-gray-500"><?php echo htmlspecialchars($prod_date); ?></td>
            <td class="px-4 py-3 text-gray-800 font-medium"><?php echo htmlspecialchars($row['part_name'] ?? '-'); ?></td>
            <td class="px-4 py-3 font-semibold"><?php echo htmlspecialchars($row['part_no'] ?? '-'); ?></td>
            <td class="px-4 py-3 font-mono text-indigo-700"><?php echo htmlspecialchars($erp_code); ?></td>
            <td class="px-4 py-3"><?php echo htmlspecialchars($row['model'] ?? '-'); ?></td>
            <td class="px-4 py-3"><?php echo htmlspecialchars($row['type'] ?? '-'); ?></td>
            <td class="px-4 py-3 text-gray-500"><?php echo htmlspecialchars($row['prod_area'] ?? '-'); ?></td>
            <td class="px-4 py-3 text-center font-bold text-emerald-600"><?php echo htmlspecialchars($qty); ?></td>
            <td class="px-4 py-3 text-gray-600"><?php echo htmlspecialchars($released_by); ?></td>
            <td class="px-4 py-3 font-mono text-purple-700"><?php echo htmlspecialchars($row['mazda_id'] ?? '-'); ?></td>
            <td class="px-4 py-3"><?php echo htmlspecialchars($row['msc_code'] ?? '-'); ?></td>
            <td class="px-4 py-3"><?php echo htmlspecialchars($row['trip'] ?? '-'); ?></td>
            <td class="px-4 py-3"><?php echo htmlspecialchars($row['lot_no'] ?? '-'); ?></td>
            <td class="px-4 py-3 text-center">
                <button onclick="deleteWarehouseOutScan(<?php echo $row['log_id']; ?>, this)" 
                        class="text-red-500 hover:text-red-700 p-1 hover:bg-red-50 rounded transition-colors" 
                        title="Delete Record">
                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
    <?php endif; ?>
</tbody>
                </table>
            </div>
            <div id="historyPagination" class="bg-gray-50 px-6 py-3 border-t border-gray-200 flex justify-center"></div>
        </div>
    </div>
</div>

<div id="manualEntryModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-start justify-center z-50 opacity-0 invisible transition-opacity duration-300 py-10">
    
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-3xl mx-4 transform scale-95 transition-transform duration-300 hover:scale-100 flex flex-col">
        
        <div class="bg-gradient-to-r from-gray-700 to-gray-800 px-8 py-6 rounded-t-3xl flex items-center justify-between flex-shrink-0">
            <h2 class="text-xl md:text-2xl font-bold flex items-center space-x-3 text-white">
                <i data-lucide="scan-line" class="w-8 h-8"></i>
                <span>Manual Transfer Ticket</span>
            </h2>
            <button type="button" id="closeManualModal" class="text-white/80 hover:text-red-600 text-2xl transition-colors duration-200">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="p-8 space-y-6">
            <p class="text-sm text-gray-500 border-l-4 border-blue-500 pl-4 bg-blue-50 py-2 rounded-r">
                <strong>Instructions:</strong> Enter the Ticket ID and ERP Code exactly as they appear on the label.
                <br>Pallet and Mazda steps must still be scanned physically.
            </p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Ticket ID (Unique No)</label>
                    <input type="text" id="manual_ticket_id" 
                           class="w-full px-4 py-3 border-2 border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300 shadow-sm hover:shadow-md text-gray-800 font-medium"
                           placeholder="e.g. 00000496">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">ERP Code (FG)</label>
                    <input type="text" id="manual_erp" 
                           class="w-full px-4 py-3 border-2 border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent transition-all duration-300 shadow-sm hover:shadow-md text-gray-800 font-medium"
                           placeholder="e.g. AA050205">
                </div>
            </div>
            
            <div id="manualStatus" class="text-center h-5 font-semibold text-sm text-gray-500"></div>
        </div>

        <div class="flex items-center justify-end space-x-4 px-8 pb-8 pt-4 border-t border-gray-200">
            <button type="button" id="cancelManualBtn"
                class="px-6 py-3 bg-red-500 text-white font-semibold rounded-xl hover:bg-red-600 transition-all duration-200 shadow-sm hover:shadow-md">
                Cancel
            </button>
            <button type="button" id="submitManualBtn"
                    class="px-8 py-3 bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white font-bold rounded-xl transition-all duration-200 flex items-center space-x-2 shadow-md hover:shadow-lg">
                <i data-lucide="search" class="w-5 h-5"></i>
                <span>Fetch & Submit</span>
            </button>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script src="assets/warehouse_out.js?v=<?php echo time(); ?>"></script>
<script>
    document.addEventListener("DOMContentLoaded", () => { lucide.createIcons(); });
</script>

<?php include 'layout/footer.php'; ?>