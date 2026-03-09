define([
    'jquery',
    'Magento_Ui/js/modal/alert'
], function ($, alert) {
    'use strict';

    return function (config) {
        $('#clear_cache_button').on('click', function () {
            var $button = $(this);

            $button.prop('disabled', true);

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    form_key: window.FORM_KEY
                },
                showLoader: true
            }).done(function (response) {
                alert({
                    title: response.status ? 'Success' : 'Error',
                    content: response.message
                });
            }).fail(function () {
                alert({
                    title: 'Error',
                    content: 'An error occurred while clearing the cache.'
                });
            }).always(function () {
                $button.prop('disabled', false);
            });
        });
    };
}); 