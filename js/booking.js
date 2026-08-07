/* =====================================================================
   booking.js
   Client-side behaviour for the court booking pages.

   Loaded by:
     booking/search.php  (hour dropdowns, court picking, running total)

   The payment screens have their own file, js/payment.js.

   Every rule written here is written again in PHP. JavaScript runs on the
   user's own computer, so it can be switched off or edited - it makes the
   pages pleasant to use, but config/booking_functions.php and
   validateBookingWindow() are what actually decide whether a booking is
   accepted. Nothing below is the only copy of a check.
   ===================================================================== */

document.addEventListener('DOMContentLoaded', function () {
    setUpTimeRange();
    setUpCourtSelection();
});


/* ---------------------------------------------------------------------
   Small helpers
   --------------------------------------------------------------------- */

/** 'RM' + a number with two decimals, matching money() in PHP. */
function formatMoney(amount) {
    return 'RM' + amount.toFixed(2);
}

/** '14:00' -> 840, so two dropdown values can be compared as numbers. */
function timeToMinutes(value) {
    var parts = String(value).split(':');
    if (parts.length < 2) {
        return null;
    }
    return (parseInt(parts[0], 10) * 60) + parseInt(parts[1], 10);
}


/* ---------------------------------------------------------------------
   1. Start / end hour dropdowns (booking page)

   Keeps the end time ahead of the start time. Any end hour that is not at
   least one hour after the chosen start is disabled, so the pair can only
   ever describe a booking of an hour or more. If the current end becomes
   invalid after the start moves, it is pushed to the next legal hour.
   --------------------------------------------------------------------- */
function setUpTimeRange() {
    var start = document.querySelector('.js-start-time');
    var end = document.querySelector('.js-end-time');

    if (!start || !end) {
        return;
    }

    function syncEndOptions() {
        var earliestEnd = timeToMinutes(start.value) + 60;
        var firstAllowed = null;

        for (var i = 0; i < end.options.length; i++) {
            var option = end.options[i];
            var minutes = timeToMinutes(option.value);

            option.disabled = minutes < earliestEnd;
            if (!option.disabled && firstAllowed === null) {
                firstAllowed = option.value;
            }
        }

        // The end that was showing is now in the past relative to the new
        // start, so move it to the earliest hour that still works.
        if (timeToMinutes(end.value) < earliestEnd && firstAllowed !== null) {
            end.value = firstAllowed;
        }
    }

    start.addEventListener('change', syncEndOptions);
    syncEndOptions();
}


/* ---------------------------------------------------------------------
   2. Court selection (booking page)

   Adds up the ticked courts so the customer can see what they are about to
   be charged before leaving the page, and stops an empty selection being
   sent. The price per court is put on the form by PHP; the amount actually
   charged is worked out again on the server.
   --------------------------------------------------------------------- */
function setUpCourtSelection() {
    var form = document.querySelector('.js-court-form');

    if (!form) {
        return;
    }

    var checks = form.querySelectorAll('.js-court-check');
    var totalLabel = form.querySelector('.js-booking-total');
    var pricePerCourt = parseFloat(form.getAttribute('data-price-per-court')) || 0;

    function countSelected() {
        var selected = 0;
        for (var i = 0; i < checks.length; i++) {
            if (checks[i].checked) {
                selected++;
            }
        }
        return selected;
    }

    function updateTotal() {
        if (totalLabel) {
            totalLabel.textContent = formatMoney(countSelected() * pricePerCourt);
        }
    }

    for (var i = 0; i < checks.length; i++) {
        checks[i].addEventListener('change', updateTotal);
    }

    form.addEventListener('submit', function (event) {
        if (countSelected() === 0) {
            event.preventDefault();
            alert('Please select at least one court before continuing.');
        }
    });

    updateTotal();
}
