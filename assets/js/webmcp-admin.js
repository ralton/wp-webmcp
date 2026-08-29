/**
 * WP-WebMCP Admin
 * Admin UI JavaScript for tool management and form scanning.
 *
 * @package WP_WebMCP
 */

(function ($) {
    'use strict';

    const Admin = window.WP_WebMCP_Admin || {};

    // Tab switching.
    $('.wp-webmcp-tabs .nav-tab').on('click', function (e) {
        e.preventDefault();
        const target = $(this).data('tab');

        $('.wp-webmcp-tabs .nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');

        $('.wp-webmcp-tab-content').hide();
        $('#tab-' + target).show();
    });

    // Add tool.
    $('#wp-webmcp-add-tool').on('click', function () {
        const name = $('#tool_name').val().trim();
        const description = $('#tool_description').val().trim();
        const type = $('#tool_type').val();
        let schema = {};

        try {
            schema = JSON.parse($('#tool_schema').val() || '{}');
        } catch (e) {
            alert('Invalid JSON Schema. Please check your syntax.');
            return;
        }

        if (!name || !description) {
            alert('Tool name and description are required.');
            return;
        }

        $.ajax({
            url: Admin.restUrl + '/tools',
            method: 'POST',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', Admin.nonce);
                xhr.setRequestHeader('Content-Type', 'application/json');
            },
            data: JSON.stringify({
                name: name,
                description: description,
                type: type,
                schema: schema,
                enabled: true,
            }),
            success: function () {
                alert('Tool added! Reload to see it in the list.');
                location.reload();
            },
            error: function (xhr) {
                const msg = xhr.responseJSON && xhr.responseJSON.message
                    ? xhr.responseJSON.message
                    : 'Failed to add tool.';
                alert(msg);
            },
        });
    });

    // Toggle tool.
    $(document).on('click', '.wp-webmcp-toggle-tool', function () {
        const toolName = $(this).data('tool');
        const button = $(this);
        const row = button.closest('tr');
        const isEnabled = button.text() === 'Disable';

        $.ajax({
            url: Admin.restUrl + '/tools/' + toolName,
            method: 'PUT',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', Admin.nonce);
                xhr.setRequestHeader('Content-Type', 'application/json');
            },
            data: JSON.stringify({ enabled: !isEnabled }),
            success: function () {
                if (isEnabled) {
                    button.text('Enable');
                    row.find('td:nth-child(4)').html('<span style="color:#ccc;">○ Disabled</span>');
                } else {
                    button.text('Disable');
                    row.find('td:nth-child(4)').html('<span style="color:green;">✓ Enabled</span>');
                }
            },
            error: function () {
                alert('Failed to toggle tool.');
            },
        });
    });

    // Delete tool.
    $(document).on('click', '.wp-webmcp-delete-tool', function () {
        const toolName = $(this).data('tool');
        if (!confirm('Delete tool "' + toolName + '"?')) return;

        $.ajax({
            url: Admin.restUrl + '/tools/' + toolName,
            method: 'DELETE',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', Admin.nonce);
            },
            success: function () {
                location.reload();
            },
            error: function () {
                alert('Failed to delete tool.');
            },
        });
    });

    // Scan site for forms.
    $('#wp-webmcp-scan').on('click', function () {
        const button = $(this);
        const resultsDiv = $('#wp-webmcp-scan-results');

        button.prop('disabled', true).text('Scanning...');
        resultsDiv.html('<p>Scanning published pages for forms...</p>');

        $.ajax({
            url: Admin.restUrl + '/scan',
            method: 'GET',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', Admin.nonce);
            },
            success: function (response) {
                if (response.length === 0) {
                    resultsDiv.html('<p>No forms found on published pages.</p>');
                    return;
                }

                let html = '<table class="wp-list-table widefat striped"><thead><tr><th>Page</th><th>URL</th><th>Forms</th><th>Type</th></tr></thead><tbody>';
                response.forEach(function (page) {
                    html += '<tr><td>' + page.title + '</td><td><a href="' + page.url + '" target="_blank">' + page.url + '</a></td><td>' + page.form_count + '</td><td>' + page.post_type + '</td></tr>';
                });
                html += '</tbody></table>';
                resultsDiv.html(html);
            },
            error: function () {
                resultsDiv.html('<p style="color:red;">Scan failed. Check your permissions.</p>');
            },
            complete: function () {
                button.prop('disabled', false).text('Scan Site');
            },
        });
    });
})(jQuery);
