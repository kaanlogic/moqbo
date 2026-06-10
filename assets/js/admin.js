(function ($) {
    function syncAllDayFields(checkbox) {
        var form = checkbox.closest("form");

        if (!form) {
            return;
        }

        form.querySelectorAll(".plainday-time-row").forEach(function (row) {
            row.hidden = checkbox.checked;
        });
    }

    document.addEventListener("change", function (event) {
        if (event.target && "plainday-all-day" === event.target.id) {
            syncAllDayFields(event.target);
        }
    });

    document.addEventListener("DOMContentLoaded", function () {
        var checkbox = document.getElementById("plainday-all-day");

        if (checkbox) {
            syncAllDayFields(checkbox);
        }
    });

    $(function () {
        $(".plainday-color-field").wpColorPicker();
    });
})(jQuery);
