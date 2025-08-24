/**
 * Admin interface JavaScript for In-Browser Cache
 *
 * Handles admin interactions like clearing all user caches.
 */

document.addEventListener('DOMContentLoaded', function() {
    // Handle clear all caches button
    const clearCacheForm = document.querySelector('form input[name="clear_all_caches"]');
    if (clearCacheForm) {
        const form = clearCacheForm.closest('form');
        const submitButton = form.querySelector('input[type="submit"]');

        form.addEventListener('submit', function(e) {
            e.preventDefault();

            if (!confirm('This will clear caches for ALL website visitors. Are you sure?')) {
                return;
            }

            // Disable button and show loading state
            submitButton.disabled = true;
            submitButton.value = 'Clearing Caches...';

            // Prepare AJAX request
            const formData = new FormData();
            formData.append('action', 'jtzl_clear_all_user_caches');
            formData.append('_ajax_nonce', jtzl_sw_admin.nonce);

            fetch(jtzl_sw_admin.ajax_url, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success message
                    showAdminNotice('Cache version incremented to ' + data.data.version + '. All user caches will be cleared automatically within a few minutes.', 'success');
                } else {
                    showAdminNotice('Failed to clear caches: ' + (data.data || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAdminNotice('Failed to clear caches: Network error', 'error');
            })
            .finally(() => {
                // Re-enable button
                submitButton.disabled = false;
                submitButton.value = 'Clear All User Caches';
            });
        });
    }
});

/**
 * Show admin notice
 */
function showAdminNotice(message, type = 'info') {
    // Remove existing notices
    const existingNotices = document.querySelectorAll('.jtzl-sw-notice');
    existingNotices.forEach(notice => notice.remove());

    // Create new notice
    const notice = document.createElement('div');
    notice.className = `notice notice-${type} is-dismissible jtzl-sw-notice`;
    notice.innerHTML = `<p>${message}</p>`;

    // Insert after h1
    const h1 = document.querySelector('.wrap h1');
    if (h1) {
        h1.parentNode.insertBefore(notice, h1.nextSibling);
    }

    // Auto-dismiss after 5 seconds for success messages
    if (type === 'success') {
        setTimeout(() => {
            notice.style.opacity = '0';
            setTimeout(() => notice.remove(), 300);
        }, 5000);
    }
}
