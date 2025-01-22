jQuery(document).ready(function ($) {
    $("#emailit_mode").change(function () {
        var mode = $(this).val();
        var data = {
            action: "emailit_load_fields",
            nonce: emailit_ajax.nonce,
            mode: mode,
        };

        $.post(emailit_ajax.ajax_url, data, function (response) {
            $("#emailit_dynamic_fields").html(response);
        });
    });

    // Trigger change event on page load to populate fields
    $("#emailit_mode").trigger("change");
});
