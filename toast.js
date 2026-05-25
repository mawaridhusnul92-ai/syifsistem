/**
 * toast.js - SYIFA ERP ASYNC ENGINE
 * Versi: 1.0 (Grand Master - Enterprise Approval Style)
 * Deskripsi: Mengelola notifikasi non-blocking dan aksi persetujuan asinkron.
 */

// 1. CREATE GLOBAL CONTAINER ON LOAD
document.addEventListener("DOMContentLoaded", function() {
    if (!document.getElementById("toast-container")) {
        const container = document.createElement("div");
        container.id = "toast-container";
        container.className = "toast-container position-fixed top-0 end-0 p-3";
        container.style.zIndex = "999999";
        document.body.appendChild(container);
    }
});

/**
 * Global Toast System
 * @param {Object} options { type: 'success'|'danger'|'warning'|'info', title: string, message: string }
 */
function showToast({ type = 'success', title = 'Pesan Sistem', message = '' }) {
    const container = document.getElementById("toast-container");
    const id = "toast-" + Date.now();
    const bgClass = type === 'success' ? 'bg-success' : (type === 'danger' ? 'bg-danger' : (type === 'warning' ? 'bg-warning' : 'bg-info'));
    
    const toastHTML = `
        <div id="${id}" class="toast align-items-center text-white ${bgClass} border-0 shadow-lg animate__animated animate__fadeInRight" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body p-3">
                    <strong class="d-block mb-1 text-uppercase" style="font-size:10px; letter-spacing:1px;">${title}</strong>
                    <div class="small fw-bold">${message}</div>
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto shadow-none" data-bs-dismiss="toast"></button>
            </div>
        </div>`;

    container.insertAdjacentHTML('beforeend', toastHTML);
    const toastEl = document.getElementById(id);
    const bsToast = new bootstrap.Toast(toastEl, { delay: 4000 });
    bsToast.show();

    // Cleanup DOM after hidden
    toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
}

/**
 * Enterprise Async Approval Action
 * @param {Object} data { action: string, id: int, ... }
 * @param {string} url Backend endpoint
 * @param {HTMLElement} btnElement The clicked button
 */
async function approveAction(data, url, btnElement) {
    // A. Visual State: Processing
    const originalContent = btnElement.innerHTML;
    const originalClass = btnElement.className;
    btnElement.disabled = true;
    btnElement.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span> Prosessing...`;

    try {
        const formData = new FormData();
        for (const key in data) formData.append(key, data[key]);

        const response = await fetch(url, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        const result = await response.json();

        if (result.status === 'success') {
            // B. Visual State: Success
            btnElement.className = "btn btn-success rounded-pill px-3 shadow-sm disabled fw-bold animate__animated animate__pulse";
            btnElement.innerHTML = `<i class="fas fa-check-circle me-1"></i> Approved`;

            // C. Highlight Row
            const row = btnElement.closest('tr');
            if (row) {
                row.style.transition = "all 0.5s ease";
                row.style.backgroundColor = "rgba(25, 135, 84, 0.08)";
                // Optional: hilangkan tombol hapus/edit jika sudah approved
                const actionGroup = btnElement.closest('.btn-group');
                if(actionGroup) {
                    const otherBtns = actionGroup.querySelectorAll('button:not(.btn-success), a');
                    otherBtns.forEach(b => b.style.display = 'none');
                }
            }

            showToast({
                type: 'success',
                title: 'Transaksi Disetujui',
                message: result.message
            });
            
            // UX Delight Sound (Optional - soft beep)
            // playApprovalSound();

        } else {
            // C. Visual State: Error
            throw new Exception(result.message);
        }
    } catch (error) {
        btnElement.disabled = false;
        btnElement.innerHTML = originalContent;
        showToast({
            type: 'danger',
            title: 'Gagal Proses',
            message: error.message || 'Koneksi server terputus.'
        });
    }
}