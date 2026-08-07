/* =====================================================================
   payment.js
   Client-side behaviour for the simulated payment provider screens.

   Loaded by:
     payment/card.php     (card face preview, last confirmation)
     payment/tng.php      (last confirmation)
     payment/success.php  (countdown back to the merchant)

   Split out of booking.js once the same screens started taking payment for
   equipment orders as well as court bookings - none of this belongs to the
   booking module any more.

   Every rule written here is written again in PHP. JavaScript runs on the
   user's own computer, so it can be switched off or edited - it makes the
   screens pleasant to use, but config/payment_functions.php is what decides
   whether a payment is accepted. Nothing below is the only copy of a check.
   ===================================================================== */

document.addEventListener("DOMContentLoaded", function () {
    setUpCardPreview();
    setUpPaymentConfirm();
    setUpPaymentReturn();
});


/* ---------------------------------------------------------------------
   Small helpers
   --------------------------------------------------------------------- */

/* ---------------------------------------------------------------------
   1. Card face preview (card payment page)

   Mirrors what is being typed onto the decorative card, and groups the card
   number into fours as it goes so long runs of digits stay readable.

   This is presentation only. Nothing here decides whether the card is
   acceptable - PHP checks the fields are filled in, and that is the whole
   rule. With JavaScript off the card face just keeps its placeholders and
   the form still submits normally.
   --------------------------------------------------------------------- */
function setUpCardPreview() {
    var form = document.querySelector('.js-card-form');

    if (!form) {
        return;
    }

    var numberInput = form.querySelector('#card_number');
    var nameInput = form.querySelector('#card_name');
    var expiryInput = form.querySelector('#card_expiry');

    var numberFace = document.querySelector('.js-card-face-number');
    var nameFace = document.querySelector('.js-card-face-name');
    var expiryFace = document.querySelector('.js-card-face-expiry');

    /** Everything that is not a digit, removed. */
    function digitsOnly(value) {
        return String(value).replace(/\D/g, '');
    }

    /** '4111111111111111' -> '4111 1111 1111 1111', padded with bullets. */
    function faceNumber(digits) {
        var text = '';
        for (var i = 0; i < 16; i++) {
            if (i > 0 && i % 4 === 0) {
                text += ' ';
            }
            text += i < digits.length ? digits.charAt(i) : '•';
        }
        return text;
    }

    if (numberInput) {
        numberInput.addEventListener('input', function () {
            var digits = digitsOnly(this.value).substring(0, 16);

            // Re-grouped as it is typed. The caret lands at the end, which is
            // where it already is for anyone typing the number in order.
            this.value = digits.replace(/(.{4})/g, '$1 ').trim();

            if (numberFace) {
                numberFace.textContent = faceNumber(digits);
            }
        });
    }

    if (nameInput) {
        nameInput.addEventListener('input', function () {
            if (nameFace) {
                nameFace.textContent = this.value.trim() === '' ? 'YOUR NAME' : this.value;
            }
        });
    }

    if (expiryInput) {
        expiryInput.addEventListener('input', function () {
            var digits = digitsOnly(this.value).substring(0, 4);

            // The slash is put in for the customer once the month is complete.
            this.value = digits.length > 2
                ? digits.substring(0, 2) + '/' + digits.substring(2)
                : digits;

            if (expiryFace) {
                expiryFace.textContent = this.value === '' ? 'MM/YY' : this.value;
            }
        });
    }

    // A rejected submit redraws the page with the name and expiry still filled
    // in, so the card face is brought up to date before anything is typed.
    if (nameInput && nameFace && nameInput.value.trim() !== '') {
        nameFace.textContent = nameInput.value;
    }
    if (expiryInput && expiryFace && expiryInput.value.trim() !== '') {
        expiryFace.textContent = expiryInput.value;
    }
}


/* ---------------------------------------------------------------------
   2. Last confirmation before paying (payment page)

   The booking cannot be changed afterwards, so this asks once more. The
   page already says so in a warning box and in a tick box that PHP checks,
   which is what makes the rule stick - this is only the final nudge.
   --------------------------------------------------------------------- */
function setUpPaymentConfirm() {
    var form = document.querySelector('.js-payment-form');

    if (!form) {
        return;
    }

    form.addEventListener('submit', function (event) {
        var message = 'Confirm payment?\n\n'
            + 'This booking cannot be changed or cancelled once payment is made.';

        if (!confirm(message)) {
            event.preventDefault();
        }
    });
}


/* ---------------------------------------------------------------------
   3. Returning to the merchant (payment received page)

   Counts down on screen and then sends the browser back to CourtHub.

   This is the visible half of the return only. The page also carries a
   <meta http-equiv="refresh"> for the same delay, so the customer is still
   handed back when JavaScript is off - they simply do not watch the number
   change. Both are set to the same number of seconds, so whichever runs is
   the one that moves the page.

   replace() rather than href, so the provider's receipt is not left in the
   history for the Back button to return to.
   --------------------------------------------------------------------- */
function setUpPaymentReturn() {
    var notice = document.querySelector('.js-payment-return');

    if (!notice) {
        return;
    }

    var counter = notice.querySelector('.js-return-countdown');
    var returnUrl = notice.getAttribute('data-return-url');
    var seconds = parseInt(notice.getAttribute('data-seconds'), 10);

    if (!returnUrl || isNaN(seconds)) {
        return;
    }

    var timer = setInterval(function () {
        seconds--;

        if (counter) {
            counter.textContent = seconds > 0 ? seconds : 0;
        }

        if (seconds <= 0) {
            clearInterval(timer);
            window.location.replace(returnUrl);
        }
    }, 1000);
}
