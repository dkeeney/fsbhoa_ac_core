(function($) {
    'use strict';

    /**
     * Global function to open the summary modal
     */
    if (typeof window.showCardholderSummaryModal !== 'function') {
        window.showCardholderSummaryModal = function(cardholderId) {
            var $overlay = $('#fsbhoa-summary-modal-overlay');
            var $body = $('#fsbhoa-summary-modal-body');

            if (!$overlay.length || !cardholderId) {
                return;
            }

            $body.html('<p style="text-align: center; color: #666; padding: 30px 0;"><span class="dashicons dashicons-update spin"></span> Loading summary card...</p>');
            $overlay.css('display', 'flex');

            $.ajax({
                url: fsbhoa_summary_vars.ajax_url,
                method: 'POST',
                data: {
                    action: 'fsbhoa_get_cardholder_summary',
                    cardholder_id: parseInt(cardholderId, 10),
                    nonce: fsbhoa_summary_vars.nonce
                },
                success: function(response) {
                    if (response.success && response.data && response.data.html) {
                        $body.html(response.data.html);
                    } else {
                        var msg = (response.data && response.data.message) ? response.data.message : 'Unable to load summary card.';
                        $body.html('<p style="color: #d63638; text-align: center; padding: 20px 0;">' + msg + '</p>');
                    }
                },
                error: function() {
                    $body.html('<p style="color: #d63638; text-align: center; padding: 20px 0;">Server communication error.</p>');
                }
            });
        };
    }

    $(document).ready(function() {
        $(document).on('click', '#fsbhoa-summary-close-btn, #fsbhoa-summary-modal-overlay', function(e) {
            if (e.target === this) {
                $('#fsbhoa-summary-modal-overlay').hide();
            }
        });

        $(document).on('keyup', function(e) {
            if (e.key === 'Escape' || e.keyCode === 27) {
                $('#fsbhoa-summary-modal-overlay').hide();
            }
        });

        $(document).on('click', '#fsbhoa-summary-print-btn', function() {
            window.print();
        });

        // Universal click delegation
        $(document).on('click', '.js-view-cardholder-summary', function(e) {
            console.log('CLICK DETECTED ON SUMMARY BTN:', jQuery(this).data('id'));
            e.preventDefault();
            e.stopPropagation();
            var $el = $(this).closest('.js-view-cardholder-summary');
            var chId = $el.data('id') || $el.attr('data-id');
            if (chId && typeof window.showCardholderSummaryModal === 'function') {
                window.showCardholderSummaryModal( parseInt(chId, 10) );
            }
        });
    });
})(jQuery);
