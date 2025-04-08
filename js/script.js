jQuery(document).ready(function($) {
    // Table sorting
    $('.wp-list-table th').click(function() {
        var table = $(this).parents('table').eq(0);
        var rows = table.find('tr:gt(0)').toArray().sort(comparer($(this).index()));
        this.asc = !this.asc;
        if (!this.asc) {
            rows = rows.reverse();
        }
        for (var i = 0; i < rows.length; i++) {
            table.append(rows[i]);
        }
    });

    function comparer(index) {
        return function(a, b) {
            var valA = getCellValue(a, index);
            var valB = getCellValue(b, index);
            return $.isNumeric(valA) && $.isNumeric(valB) ?
                valA - valB : valA.localeCompare(valB);
        };
    }

    function getCellValue(row, index) {
        return $(row).children('td').eq(index).text();
    }

    // Updated clear logs handler with feedback
    $('#clear-logs').click(function() {
        const $button = $(this);
        
        if (confirm('Are you sure you want to clear all logs?')) {
            // Disable button and show loading state
            $button.prop('disabled', true).text('Clearing...');
            
            $.post(requestLoggerViewer.ajaxurl, {
                action: 'clear_request_logs',
                nonce: requestLoggerViewer.nonce
            }, function(response) {
                if (response.success) {
                    // Show success message briefly before reload
                    $button.text('Cleared!');
                    setTimeout(function() {
                        window.location.reload();
                    }, 500);
                } else {
                    // Show error and reset button
                    alert('Error clearing logs: ' + response.data);
                    $button.prop('disabled', false).text('Clear Logs');
                }
            }).fail(function() {
                // Handle network errors
                alert('Failed to clear logs. Please try again.');
                $button.prop('disabled', false).text('Clear Logs');
            });
        }
    });
}); 