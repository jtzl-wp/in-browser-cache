/**
 * Dashboard JavaScript for In-Browser Cache
 *
 * This file handles the dashboard functionality for the In-Browser Cache plugin.
 * It fetches metrics data from the REST API and displays it in charts and tables.
 *
 * @package In-Browser Cache
 * @since 0.1.0
 */

jQuery(document).ready(function ($) {
    /** @type {Chart|null} Chart.js instance for the metrics chart */
    let myChart;

    /**
     * Formats a byte value into a human-readable string with appropriate units.
     *
     * @since 0.1.0
     * @param {number} bytes - The number of bytes to format
     * @param {number} [decimals=2] - The number of decimal places to include
     * @return {string} Formatted string with appropriate unit suffix
     */
    function formatBytes(bytes, decimals = 2) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const dm = decimals < 0 ? 0 : decimals;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
    }

    /**
     * Formats a hit count number into a human-readable string with appropriate units.
     *
     * @since 0.5.0
     * @param {number} count - The hit count to format
     * @return {string} Formatted string with appropriate unit suffix
     */
    function formatHitCount(count) {
        if (count === 0) return '0';
        if (count < 1000) return count.toString();
        if (count < 1000000) return (count / 1000).toFixed(1) + 'K';
        if (count < 1000000000) return (count / 1000000).toFixed(1) + 'M';
        return (count / 1000000000).toFixed(1) + 'B';
    }

    /**
     * Fetches metrics data from the REST API.
     *
     * Makes an AJAX request to the dashboard-metrics endpoint to retrieve
     * cache hits, misses, and bandwidth savings data.
     *
     * @since 0.1.0
     */
    function fetchMetrics() {
        $.ajax({
            url: jtzl_sw.api_url + 'dashboard-metrics',
            method: 'GET',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', jtzl_sw.api_nonce);
            },
            success: function (response) {
                updateDashboard(response);
            },
            error: function (err) {
                console.error('Error fetching metrics:', err);
            }
        });
    }

    /**
     * Updates the dashboard with the fetched metrics data.
     *
     * Updates the summary cards with totals and renders the chart
     * showing cache hits vs. misses over time.
     *
     * @since 0.1.0
     * @param {Object} data - The metrics data from the REST API
     * @param {Object} data.totals - Total metrics (hits, misses, bytes_saved)
     * @param {Array} data.history - Historical metrics data by date
     * @param {Array} data.top_assets - Top cached assets
     */
    function updateDashboard(data) {
        // Update summary cards
        $('#total-hits').text(data.totals.hits || 0);
        $('#total-misses').text(data.totals.misses || 0);
        $('#bytes-saved').text(formatBytes(data.totals.bytes_saved || 0));

        // Prepare chart data
        const labels = data.history.map(item => item.metric_date);
        const hitsData = data.history.map(item => item.hits);
        const missesData = data.history.map(item => item.misses);

        const chartData = {
            labels: labels,
            datasets: [
                {
                    label: 'Cache Hits',
                    data: hitsData,
                    backgroundColor: 'rgba(54, 162, 235, 0.5)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                },
                {
                    label: 'Cache Misses',
                    data: missesData,
                    backgroundColor: 'rgba(255, 99, 132, 0.5)',
                    borderColor: 'rgba(255, 99, 132, 1)',
                    borderWidth: 1
                }
            ]
        };

        // Render chart
        const ctx = document.getElementById('hits-misses-chart').getContext('2d');
        if (myChart) {
            myChart.destroy();
        }
        myChart = new Chart(ctx, {
            type: 'bar',
            data: chartData,
            options: {
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        // Update top assets table
        updateTopAssetsTable(data.top_assets || []);
    }

    /**
     * Updates the top assets table with frequency-based data.
     *
     * @since 0.5.0
     * @param {Array} topAssets - Array of top cached assets sorted by hit count
     */
    function updateTopAssetsTable(topAssets) {
        const tbody = $('#top-assets-tbody');
        tbody.empty();

        if (!topAssets || topAssets.length === 0) {
            tbody.append(`
                <tr>
                    <td colspan="5" style="text-align: center; padding: 20px;">
                        No frequency data available yet. Assets will appear here as they are cached by visitors.
                    </td>
                </tr>
            `);
            return;
        }

        topAssets.forEach(asset => {
            const assetUrl = asset.asset_url || '';
            const assetType = asset.asset_type || 'unknown';
            const assetSize = parseInt(asset.asset_size) || 0;
            const hitCount = parseInt(asset.hit_count) || 0;
            const lastAccessed = asset.last_accessed || '';

            // Format the URL for display (truncate if too long)
            const displayUrl = assetUrl.length > 60 ? 
                assetUrl.substring(0, 57) + '...' : 
                assetUrl;

            // Format the last accessed date
            const formattedDate = lastAccessed ? 
                new Date(lastAccessed).toLocaleString() : 
                'Unknown';

            tbody.append(`
                <tr>
                    <td title="${assetUrl}">${displayUrl}</td>
                    <td>${assetType}</td>
                    <td>${formatBytes(assetSize)}</td>
                    <td><strong>${formatHitCount(hitCount)} hits</strong></td>
                    <td>${formattedDate}</td>
                </tr>
            `);
        });
    }

    // Initial fetch
    fetchMetrics();

    // Refresh button
    $('#refresh-data-btn').on('click', fetchMetrics);
});