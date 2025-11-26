<?php
// Filename: track_ticket.php
require_once 'db.php';
include 'layout/header.php'; // Use your existing header
$page_title = 'Track Transfer Ticket';
?>
<link rel="stylesheet" href="assets/style.css">
<div class="container mx-auto px-6 py-10">
        <div class="bg-gradient-to-r from-blue-800 via-blue-700 to-sky-500 rounded-xl shadow-md px-6 py-4 mb-8 text-white animate-fade-in">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-3">
          <div>
            <h2 class="text-lg font-semibold flex items-center space-x-2">
                <i data-lucide="map-pin" class="w-7 h-7"></i>
              <span>Track Transfer Ticket</span>
            </h2>
            <p class="text-blue-100 text-xs md:text-sm mt-1">
              Enter the transfer ticket ID and ERP code to view its current status and tracking history.
            </p>
          </div>
        </div>
        </div>

        <div class="bg-white rounded-xl shadow-2xl overflow-hidden">
        <div class="container mx-auto px-6 py-10">
            <form id="trackForm" class="flex flex-col sm:flex-row gap-2 mb-8">
                <input type="text" id="ticketSearchInput" 
                       class="w-full px-4 py-3 border-2 border-gray-300 rounded-xl text-lg
                              focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                       placeholder="Ticket ID (e.g., 00000496)...">
                
                <input type="text" id="erpSearchInput" 
                       class="w-full px-4 py-3 border-2 border-gray-300 rounded-xl text-lg
                              focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                       placeholder="ERP Code (e.g., AA050205)...">

                <button id="searchBtn" type="submit"
                        class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-6 rounded-lg text-lg
                               flex items-center justify-center gap-2 transition-all flex-shrink-0">
                    <i data-lucide="search" class="w-5 h-5"></i>
                    <span>Search</span>
                </button>
                
                <button id="refreshBtn" type="button"
                        class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-3 px-4 rounded-lg text-lg
                               flex items-center justify-center gap-2 transition-all flex-shrink-0"
                        title="Clear Search">
                    <i data-lucide="refresh-cw" class="w-5 h-5"></i>
                </button>
            </form>
            
            <div id="trackingResult" class="mt-6">
                </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
    const searchInput = document.getElementById("ticketSearchInput");
    const erpFieldContainer = document.getElementById("erpFieldContainer");
    const searchBtn = document.getElementById("searchBtn");
    const refreshBtn = document.getElementById("refreshBtn");
    const resultContainer = document.getElementById("trackingResult");
    const trackForm = document.getElementById("trackForm");
    let erpElement = document.getElementById("erpSearchInput");

    // --- Helper: Pad Ticket ID with Zeros ---
    // Turns "496" into "00000496"
    const formatTicketId = (id) => {
        if (!id) return '';
        return id.toString().trim().padStart(8, '0');
    };

    const renderEmptyState = () => {
        resultContainer.innerHTML = `
            <div class="status-box">
                <i data-lucide="scan-line" class="w-12 h-12 text-gray-400"></i>
                <p class="mt-4 text-lg font-medium text-gray-600">Enter a Ticket ID and ERP Code to see its status.</p>
            </div>
        `;
        lucide.createIcons();
    };

    const renderLoadingState = () => {
        resultContainer.innerHTML = `
            <div class="status-box">
                <i data-lucide="loader-2" class="w-12 h-12 text-blue-500 animate-spin"></i>
                <p class="mt-4 text-lg font-medium text-gray-600">Searching for ticket...</p>
            </div>
        `;
        lucide.createIcons();
    };
    
    const renderErrorState = (message) => {
        resultContainer.innerHTML = `
            <div class="status-box">
                <i data-lucide="alert-triangle" class="w-12 h-12 text-red-500"></i>
                <p class="mt-4 text-lg font-bold text-red-700">${message}</p>
            </div>
        `;
        lucide.createIcons();
    };

    // --- Smart Batch Function ---
    const checkBatchId = async () => {
        let unique_no = searchInput.value.trim();
        
        // Auto-pad if the user types a short number (e.g. "496")
        if (unique_no.length > 0 && unique_no.length < 8) {
             unique_no = formatTicketId(unique_no);
             searchInput.value = unique_no; // Update the input box for the user
        }

        if (unique_no.length < 8) { 
            resetErpField();
            return;
        }

        const spinner = document.getElementById("erpLoadingSpinner");
        spinner.style.opacity = '1';

        try {
            const response = await fetch(`get_batch_parts.php?unique_no=${encodeURIComponent(unique_no)}`);
            const data = await response.json();

            if (data.success && data.parts.length > 1) {
                let select = document.createElement('select');
                select.id = "erpSearchSelect";
                // Apply the same styling classes as the text input
                select.className = "w-full px-4 py-3 border-2 border-gray-300 rounded-xl text-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white appearance-none";
                
                select.innerHTML = `<option value="">Select Part from Batch...</option>` +
                    data.parts.map(part => {
                        return `<option value="${part.erp_code_FG}">${part.erp_code_FG} (${part.part_name})</option>`
                    }).join('');
                
                erpFieldContainer.innerHTML = ''; 
                erpFieldContainer.appendChild(select);
                erpElement = select; 
            } else {
                resetErpField();
            }
        } catch (err) {
            console.error(err);
            resetErpField();
        } finally {
            spinner.style.opacity = '0';
        }
    };

    const resetErpField = () => {
        erpFieldContainer.innerHTML = `
            <input type="text" id="erpSearchInput" 
                   class="w-full px-4 py-3 border-2 border-gray-300 rounded-xl text-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                   placeholder="ERP Code (e.g., AA050205)...">
            <div id="erpLoadingSpinner"></div>
        `;
        erpElement = document.getElementById("erpSearchInput");
    };

    const searchTicket = () => {
        // Auto-pad the ID again just to be safe
        const unique_no = formatTicketId(searchInput.value);
        const erp_code = erpElement.value.trim();

        if (!unique_no || !erp_code) {
            renderErrorState("Ticket ID and ERP Code are required.");
            return;
        }
        
        // Update visual input if needed
        searchInput.value = unique_no;

        renderLoadingState();
        searchBtn.disabled = true;

        fetch(`get_ticket_status.php?unique_no=${encodeURIComponent(unique_no)}&erp_code_fg=${encodeURIComponent(erp_code)}`)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    renderTrackingInfo(data.ticket_details, data.tracking_history);
                } else {
                    renderErrorState(data.message);
                }
            })
            .catch(err => {
                console.error(err);
                renderErrorState("A connection error occurred.");
            })
            .finally(() => {
                searchBtn.disabled = false;
                lucide.createIcons();
            });
    };

    trackForm.addEventListener("submit", (e) => {
        e.preventDefault();
        searchTicket();
    });

    refreshBtn.addEventListener("click", (e) => {
        e.preventDefault();
        searchInput.value = "";
        resetErpField();
        renderEmptyState();
        searchInput.focus();
    });
    
    searchInput.addEventListener("blur", checkBatchId);

    function renderTrackingInfo(details, history) {
        // Force 8-digit zero padding for display
        const displayId = formatTicketId(details.unique_no);

        let detailsHtml = `
            <div class="bg-gray-50 p-4 rounded-xl mb-8 border border-gray-200 grid grid-cols-2 md:grid-cols-4 gap-4">
                <div>
                    <span class="text-xs text-gray-500">Ticket ID</span>
                    <p class="font-bold text-blue-700">#${displayId}</p>
                </div>
                <div class="col-span-2">
                    <span class="text-xs text-gray-500">Part Name</span>
                    <p class="font-semibold text-gray-800 truncate" title="${details.part_name}">${details.part_name}</p>
                </div>
                 <div>
                    <span class="text-xs text-gray-500">Model</span>
                    <p class="font-semibold text-gray-800">${details.model}</p>
                </div>
                <div>
                    <span class="text-xs text-gray-500">Part No (FG)</span>
                    <p class="font-semibold text-gray-800">${details.part_no_FG}</p>
                </div>
                <div>
                    <span class="text-xs text-gray-500">ERP Code (FG)</span>
                    <p class="font-mono font-semibold text-indigo-700">${details.erp_code_FG}</p>
                </div>
                <div>
                    <span class="text-xs text-gray-500">Quantity</span>
                    <p class="font-bold text-emerald-700">${details.quantity} pcs</p>
                </div>
                <div>
                    <span class="text-xs text-gray-500">Created By</span>
                    <p class="font-semibold text-gray-800">${details.released_by}</p>
                </div>
            </div>
            <h3 class="text-xl font-bold text-gray-800 mb-6">Tracking History</h3>
            <ul class="timeline">
        `;

        let allHistory = [...history];
        const hasPrintedLog = history.some(log => log.status_message.includes('Printed'));
        
        if (!hasPrintedLog) {
            allHistory.unshift({
                status_message: 'Ticket Printed',
                status_timestamp: details.created_at,
                scanned_by: details.released_by
            });
        }
        
        allHistory.slice().reverse().forEach((item, index) => {
            let icon = 'box';
            let statusClass = (index === 0) ? 'status-current' : 'status-complete';
            
            if (item.status_message.includes('Printed')) icon = 'printer';
            if (item.status_message.includes('Warehouse')) icon = 'log-in';
            if (item.status_message.includes('Out to PNE')) icon = 'log-out';
            if (item.status_message.includes('from PNE')) icon = 'undo-2';
            if (item.status_message.includes('Customer')) icon = 'check-check';

            detailsHtml += `
                <li class="timeline-item ${statusClass}">
                    <div class="timeline-icon"><i data-lucide="${icon}" class="w-5 h-5"></i></div>
                    <div class="timeline-content">
                        <h3 class="font-bold text-lg ${index === 0 ? 'text-blue-600' : 'text-gray-800'}">
                            ${item.status_message}
                        </h3>
                        <p class="text-sm text-gray-500">
                            ${new Date(item.status_timestamp).toLocaleString('en-GB')}
                            ${item.scanned_by ? `by <strong>${item.scanned_by}</strong>` : ''}
                        </p>
                    </div>
                </li>
            `;
        });

        detailsHtml += `</ul>`;
        resultContainer.innerHTML = detailsHtml;
        lucide.createIcons();
    }
    
    renderEmptyState();
    searchInput.focus();
    
    // Auto-search URL params
    const urlParams = new URLSearchParams(window.location.search);
    const urlTicketId = urlParams.get('unique_no');
    const urlErp = urlParams.get('erp_code_fg');
    if(urlTicketId && urlErp) {
        searchInput.value = formatTicketId(urlTicketId);
        erpElement.value = urlErp;
        searchTicket();
    }
});
</script>

<?php include 'layout/footer.php'; ?>