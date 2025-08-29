/**
 * Admin interface JavaScript for In-Browser Cache
 * 
 * Handles admin interactions like clearing all user caches and CDN configuration.
 */

document.addEventListener('DOMContentLoaded', function () {
    // Handle clear all caches button
    const clearCacheForm = document.querySelector('form input[name="clear_all_caches"]');
    if (clearCacheForm) {
        const form = clearCacheForm.closest('form');
        const submitButton = form.querySelector('input[type="submit"]');

        form.addEventListener('submit', function (e) {
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

    // Handle delete error logs button
    const deleteLogsForm = document.querySelector('form input[name="cleanup_error_logs"]');
    if (deleteLogsForm) {
        const form = deleteLogsForm.closest('form');
        const submitButton = form.querySelector('input[type="submit"]');

        form.addEventListener('submit', function (e) {
            if (!confirm('This will permanently delete all CDN error logs. This action cannot be undone. Are you sure?')) {
                e.preventDefault();
                return;
            }

            // Show loading state but allow form to submit normally
            submitButton.disabled = true;
            submitButton.value = 'Deleting Logs...';

            // Don't prevent default - let the form submit normally
        });
    }

    // Initialize CDN settings functionality
    initializeCDNSettings();
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

    // Create paragraph element and set text content safely
    const paragraph = document.createElement('p');
    paragraph.textContent = message;
    notice.appendChild(paragraph);
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

/**

 * Initialize CDN settings functionality
 */
function initializeCDNSettings() {
    const cdnEnabledCheckbox = document.getElementById('cdn-enabled-checkbox');
    const cdnBaseUrlInput = document.getElementById('cdn-base-url');
    const testCdnButton = document.getElementById('test-cdn-btn');
    const cdnTestResult = document.getElementById('cdn-test-result');

    if (!cdnEnabledCheckbox || !cdnBaseUrlInput || !testCdnButton) {
        return; // CDN settings not present on this page
    }

    // Toggle CDN settings visibility based on enabled checkbox
    function toggleCDNSettings() {
        const cdnRows = document.querySelectorAll('.cdn-setting-row');
        const isEnabled = cdnEnabledCheckbox.checked;

        cdnRows.forEach(row => {
            row.style.display = isEnabled ? '' : 'none';
        });
    }

    // Initial toggle
    toggleCDNSettings();

    // Handle checkbox change
    cdnEnabledCheckbox.addEventListener('change', toggleCDNSettings);

    // Real-time URL validation
    cdnBaseUrlInput.addEventListener('input', function () {
        const url = this.value.trim();
        const isValid = validateCDNUrl(url);

        // Enable/disable test button based on URL validity
        testCdnButton.disabled = !isValid;

        // Clear previous test results when URL changes
        if (cdnTestResult) {
            cdnTestResult.style.display = 'none';
        }

        // Show inline validation feedback
        showUrlValidationFeedback(this, isValid, url);
    });

    // Handle CDN connectivity test
    testCdnButton.addEventListener('click', function (e) {
        e.preventDefault();
        testCDNConnectivity();
    });

    // Trigger initial validation
    if (cdnBaseUrlInput.value.trim()) {
        cdnBaseUrlInput.dispatchEvent(new Event('input'));
    }
}

/**
 * Validate CDN URL format
 */
function validateCDNUrl(url) {
    if (!url) {
        return false;
    }

    try {
        const urlObj = new URL(url);

        // Must be HTTPS
        if (urlObj.protocol !== 'https:') {
            return false;
        }

        // Must have a hostname
        if (!urlObj.hostname) {
            return false;
        }

        // Prevent localhost and private IPs (basic check)
        if (urlObj.hostname === 'localhost' ||
            urlObj.hostname === '127.0.0.1' ||
            urlObj.hostname.startsWith('192.168.') ||
            urlObj.hostname.startsWith('10.') ||
            urlObj.hostname.startsWith('172.')) {
            return false;
        }

        return true;
    } catch (e) {
        return false;
    }
}

/**
 * Show URL validation feedback
 */
function showUrlValidationFeedback(input, isValid, url) {
    // Remove existing feedback
    const existingFeedback = input.parentNode.querySelector('.cdn-url-feedback');
    if (existingFeedback) {
        existingFeedback.remove();
    }

    if (!url) {
        return; // No feedback for empty URL
    }

    // Create feedback element
    const feedback = document.createElement('div');
    feedback.className = 'cdn-url-feedback';
    feedback.style.marginTop = '5px';
    feedback.style.fontSize = '12px';

    if (isValid) {
        feedback.style.color = '#00a32a';
        feedback.textContent = '✓ Valid CDN URL format';
    } else {
        feedback.style.color = '#d63638';

        if (!url.startsWith('https://')) {
            feedback.textContent = '✗ CDN URL must use HTTPS';
        } else {
            feedback.textContent = '✗ Invalid URL format';
        }
    }

    // Insert feedback after the input
    input.parentNode.insertBefore(feedback, input.nextSibling);
}

/**
 * Test CDN connectivity
 */
function testCDNConnectivity() {
    const testButton = document.getElementById('test-cdn-btn');
    const cdnUrl = document.getElementById('cdn-base-url').value.trim();
    const resultContainer = document.getElementById('cdn-test-result');

    if (!cdnUrl || !validateCDNUrl(cdnUrl)) {
        showAdminNotice(jtzl_sw_admin.strings.invalid_url, 'error');
        return;
    }

    // Update button state
    const originalText = testButton.textContent;
    testButton.disabled = true;
    testButton.textContent = jtzl_sw_admin.strings.testing_cdn;

    // Show loading state in result container
    if (resultContainer) {
        resultContainer.style.display = 'block';
        resultContainer.innerHTML = '<div class="cdn-test-status cdn-test-loading"><span class="cdn-test-message">Testing CDN connectivity...</span></div>';
    }

    // Prepare AJAX request
    const formData = new FormData();
    formData.append('action', 'test_cdn_connectivity');
    formData.append('cdn_url', cdnUrl);
    formData.append('_ajax_nonce', jtzl_sw_admin.cdn_test_nonce);

    fetch(jtzl_sw_admin.ajax_url, {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showCDNTestResult('success', data.data.message, data.data.response_time);
                showAdminNotice(jtzl_sw_admin.strings.cdn_test_success, 'success');
            } else {
                showCDNTestResult('error', data.data.message, data.data.response_time);
                showAdminNotice(jtzl_sw_admin.strings.cdn_test_failed + ': ' + data.data.message, 'error');
            }
        })
        .catch(error => {
            console.error('CDN test error:', error);
            showCDNTestResult('error', jtzl_sw_admin.strings.network_error, 0);
            showAdminNotice(jtzl_sw_admin.strings.network_error, 'error');
        })
        .finally(() => {
            // Restore button state
            testButton.disabled = false;
            testButton.textContent = originalText;
        });
}

/**
 * Show CDN test result
 */
function showCDNTestResult(status, message, responseTime) {
    const resultContainer = document.getElementById('cdn-test-result');
    if (!resultContainer) {
        return;
    }

    // Clear existing content
    resultContainer.innerHTML = '';

    // Create status container
    const statusDiv = document.createElement('div');
    statusDiv.className = `cdn-test-status cdn-test-${status}`;

    // Create message span and set text content safely
    const messageSpan = document.createElement('span');
    messageSpan.className = 'cdn-test-message';
    messageSpan.textContent = message;
    statusDiv.appendChild(messageSpan);

    // Add response time if available
    if (responseTime > 0) {
        const timeSpan = document.createElement('span');
        timeSpan.className = 'cdn-test-time';
        timeSpan.textContent = ` (${responseTime}ms)`;
        statusDiv.appendChild(timeSpan);
    }

    resultContainer.appendChild(statusDiv);
    resultContainer.style.display = 'block';
}
