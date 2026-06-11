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

    function syncEventSlugField(checkbox) {
        var form = checkbox.closest("form");

        if (!form) {
            return;
        }

        var input = form.querySelector("#plainday-event-slug");
        var row = input ? input.closest("tr") : null;

        if (!row || !input) {
            return;
        }

        row.hidden = checkbox.checked;
        input.required = !checkbox.checked;
    }

    document.addEventListener("change", function (event) {
        if (event.target && "plainday-all-day" === event.target.id) {
            syncAllDayFields(event.target);
        }

        if (event.target && "plainday-auto-generate-slug" === event.target.id) {
            syncEventSlugField(event.target);
        }
    });

    document.addEventListener("DOMContentLoaded", function () {
        var checkbox = document.getElementById("plainday-all-day");
        var autoSlugCheckbox = document.getElementById("plainday-auto-generate-slug");

        if (checkbox) {
            syncAllDayFields(checkbox);
        }

        if (autoSlugCheckbox) {
            syncEventSlugField(autoSlugCheckbox);
        }
    });

    $(function () {
        $(".plainday-color-field").wpColorPicker();
    });
})(jQuery);
