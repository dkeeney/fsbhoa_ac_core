// assets/js/fsbhoa-test-suite.js
jQuery(document).ready(function($) {
    const runButton = $('#run-test-suite');
    const resultsDiv = $('#test-results');

    runButton.on('click', function() {
        runButton.prop('disabled', true).text('Running...');
        resultsDiv.html('<p>Starting test suite...</p>');

        // Steps come from the server (core plus any extension plugins), in order.
        fsbhoa_test_vars.steps.reduce(
            (chain, step, index) => chain.then(() => runTestStep(step.id, `${index + 1}. ${step.label}`)),
            Promise.resolve()
        )
            .then(() => {
                logResult('--- Test Suite Complete ---', 'success');
                runButton.prop('disabled', false).text('Run Full Test Suite');
            })
            .catch((errorMsg) => {
                logResult(`--- TEST HALTED: ${errorMsg} ---`, 'error');
                runButton.prop('disabled', false).text('Run Full Test Suite');
            });
    });

    /**
     * Sets up the handler for the new custom event test button.
     */
    function setupCustomTestButton() {
        var $button = $('#run-custom-test-btn');
        var $cardNumberInput = $('#custom-card-number');
        var $serialNumberInput = $('#custom-serial-number');
        var $doorNumberInput = $('#custom-door-number');

        // Exit if the button doesn't exist on this page
        if ($button.length === 0) {
            return;
        }

        $button.on('click', function() {
            var cardNumber = $cardNumberInput.val();
            var serialNumber = $serialNumberInput.val();
            var doorNumber = $doorNumberInput.val();

            if (!cardNumber || !serialNumber) {
                alert('Please enter both a Card Number and a Controller Serial Number.');
                return;
            }

            var payload = {
                card_number: parseInt(cardNumber, 10),
                serial_number: parseInt(serialNumber, 10),
                door_number: parseInt(doorNumber, 10)
            };

            // AJAX call to a WordPress action that will trigger the Go service
            $.ajax({
                url: fsbhoa_test_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'trigger_custom_event',
                    nonce: fsbhoa_test_vars.nonce,
                    payload: JSON.stringify(payload)
                },
                beforeSend: function() {
                    $button.text('Running...').prop('disabled', true);
                },
                success: function(response) {
                    alert('Test event sent successfully! Check the real-time monitor.');
                },
                error: function(xhr) {
                    alert('Error: ' + xhr.responseText);
                },
                complete: function() {
                    $button.text('Run Custom Test').prop('disabled', false);
                }
            });
        });
    }
    setupCustomTestButton();



    function runTestStep(step, message) {
        logResult(message, 'running');
        // Add a delay to allow services to process
        return new Promise((resolve, reject) => {
            setTimeout(() => {
                $.post(fsbhoa_test_vars.ajax_url, {
                    action: 'fsbhoa_run_regression_test',
                    nonce: fsbhoa_test_vars.nonce,
                    test_step: step
                })
                .done(function(response) {
                    if (response.success) {
                        logResult(response.data, 'success');
                        resolve();
                    } else {
                        logResult(response.data, 'error');
                        reject(response.data);
                    }
                })
                .fail(function() {
                    const errorMsg = 'AJAX request failed for step: ' + step;
                    logResult(errorMsg, 'error');
                    reject(errorMsg);
                });
            }, 2000); // 2-second delay
        });
    }

    function logResult(message, status) {
        let color = '#333'; // Default
        if (status === 'success') color = 'green';
        if (status === 'error') color = 'red';
        if (status === 'running') message = `<i>${message}</i>`;
        
        resultsDiv.append(`<div style="color:${color}; margin-bottom:5px;">${message}</div>`);
        resultsDiv.scrollTop(resultsDiv[0].scrollHeight);
    }



    // Handler for Controller Hardware Audit
    jQuery(document).ready(function($) {
        $xdocument = $(document);
        $xdocument.on('click', '#run-hardware-audit-btn', function() {
            const $btn = $(this);
            const resultsDiv = $('#test-results');
    
            $btn.prop('disabled', true).text('Auditing Controllers...');
            resultsDiv.html('<div style="color:#333; margin-bottom:5px;"><i>Starting Hardware Audit across all controllers...</i></div>');

            $.post(fsbhoa_test_vars.ajax_url, {
                action: 'fsbhoa_run_hardware_audit',
                nonce: fsbhoa_test_vars.nonce
            })
            .done(function(response) {
                if (!response.success) {
                    resultsDiv.append('<div style="color:red; margin-bottom:5px;">Audit Failed: ' + response.data + '</div>');
                    return;
                }
    
                resultsDiv.empty();
                resultsDiv.append('<div style="color:#333; font-weight:bold; margin-bottom:10px;">=== UHPPOTE CONTROLLER AUDIT REPORT ===</div>');

                response.data.forEach(function(report) {
                    logResult('[' + report.status + '] ' + report.name + ' (' + report.device_id + ') - ' + report.doors_configured + ' Door(s)');
                    logResult('  Cards DB: ' + report.total_db + ' | Cards HW: ' + report.total_hw);
    
                    if (report.missing_from_hw > 0 || report.unexpected_on_hw > 0) {
                        logResult('  Missing from Board: ' + report.missing_from_hw + ' | Unexpected on Board: ' + report.unexpected_on_hw);
                    }
    
                    logResult('  Unassigned (No access): ' + report.unassigned_count + ' cards');
    
                    // Report malformed profile values (0, 1, blanks)
                    if (report.bad_syntax_count > 0) {
                    logResult('  [!] Cards with invalid profile syntax (0/1/blank instead of Y/N): ' + report.bad_syntax_count);
                    }
    
                    // Report referenced profiles missing from hardware
                    if (report.missing_profiles.length > 0) {
                        logResult('  [!] Referenced profiles missing on board: ' + report.missing_profiles.join(', '));
                        logResult('  [!] Affected cards: ' + Object.keys(report.cards_with_missing_profiles).length);
                    } else {
                        logResult('  All referenced profiles are valid and present.');
                    }
    
                    // Output live profile schedule summaries
                    $.each(report.profiles, function(pid, schedule) {
                        logResult('  [Profile ' + pid + ']: ' + schedule);
                    });
                });
    
                resultsDiv.append('<div style="color:green; font-weight:bold; margin-top:15px;">--- Hardware Audit Complete ---</div>');
                resultsDiv.scrollTop(resultsDiv[0].scrollHeight);
            })
            .fail(function(xhr) {
                resultsDiv.append('<div style="color:red; margin-bottom:5px;">AJAX error while executing hardware audit: ' + xhr.responseText + '</div>');
            })
            .always(function() {
                $btn.prop('disabled', false).text('Run Controller Audit (All Boards)');
            });
        });
    });
});

