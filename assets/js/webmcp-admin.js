/**
 * WP-WebMCP Admin
 * Admin UI interactions for tool management and form scanning.
 *
 * @package WP_WebMCP
 */
(function ($) {
    'use strict';

    const Admin = window.WP_WebMCP_Admin || {};

    $('.wp-webmcp-tabs .nav-tab').on('click', function (e) {
        e.preventDefault();
        const target = $(this).data('tab');
        $('.wp-webmcp-tabs .nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        $('.wp-webmcp-tab-content').hide();
        $('#tab-' + target).show();
    });

    $(document).on('click', '.wp-webmcp-toggle-tool', function () {
        const toolName = $(this).data('tool');
        const button = $(this);
        const row = button.closest('tr');
        const isEnabled = button.text() === 'Disable';
        $.ajax({
            url: Admin.restUrl + '/tools/' + encodeURIComponent(toolName),
            method: 'PUT',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', Admin.nonce);
                xhr.setRequestHeader('Content-Type', 'application/json');
            },
            data: JSON.stringify({ enabled: !isEnabled }),
            success: function () {
                button.text(isEnabled ? 'Enable' : 'Disable');
                row.find('td:nth-child(4)').text(isEnabled ? '○ Disabled' : '✓ Enabled');
            },
            error: function () { alert('Failed to toggle tool.'); },
        });
    });

    $(document).on('click', '.wp-webmcp-delete-tool', function () {
        const toolName = $(this).data('tool');
        if (!confirm('Delete tool "' + toolName + '"?')) return;
        $.ajax({
            url: Admin.restUrl + '/tools/' + encodeURIComponent(toolName),
            method: 'DELETE',
            beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', Admin.nonce); },
            success: function () { location.reload(); },
            error: function () { alert('Failed to delete tool.'); },
        });
    });

    function isSafeHttpUrl(value) {
        try {
            const url = new URL(value, window.location.origin);
            return url.protocol === 'http:' || url.protocol === 'https:';
        } catch (error) {
            return false;
        }
    }

    function renderScanResults(resultsDiv, response) {
        resultsDiv.empty();
        if (!Array.isArray(response) || response.length === 0) {
            resultsDiv.append($('<p>').text('No forms found on published pages.'));
            return;
        }
        const table = $('<table>', { class: 'wp-list-table widefat striped' });
        const body = $('<tbody>');
        table.append($('<thead>').append($('<tr>')
            .append($('<th>').text('Page'))
            .append($('<th>').text('URL'))
            .append($('<th>').text('Forms'))
            .append($('<th>').text('Type'))));
        response.forEach(function (page) {
            const row = $('<tr>');
            row.append($('<td>').text(page.title));
            const urlCell = $('<td>');
            if (isSafeHttpUrl(page.url)) {
                urlCell.append($('<a>', { target: '_blank', rel: 'noopener noreferrer' }).attr('href', page.url).text(page.url));
            } else {
                urlCell.text('Unavailable');
            }
            row.append(urlCell);
            row.append($('<td>').text(page.form_count));
            row.append($('<td>').text(page.post_type));
            body.append(row);
        });
        table.append(body);
        resultsDiv.append(table);
    }

    $('#wp-webmcp-scan').on('click', function () {
        const button = $(this);
        const resultsDiv = $('#wp-webmcp-scan-results');
        button.prop('disabled', true).text('Scanning...');
        resultsDiv.empty().append($('<p>').text('Scanning published pages for forms...'));
        $.ajax({
            url: Admin.restUrl + '/scan', method: 'GET',
            beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', Admin.nonce); },
            success: function (response) { renderScanResults(resultsDiv, response); },
            error: function () { resultsDiv.empty().append($('<p>').css('color', 'red').text('Scan failed. Check your permissions.')); },
            complete: function () { button.prop('disabled', false).text('Scan Site'); },
        });
    });
})(jQuery);
