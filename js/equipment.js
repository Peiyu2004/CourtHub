/* =====================================================================
   equipment.js
   Client-side behaviour for the equipment store pages.

   Loaded by:
     shop/equipment.php         (listing: live search, filter, sort)
     shop/equipmentDetails.php  (details: star picker, quantity, review form)
     admin/equipment.php        (admin: form validation, image preview)
     admin/categories.php       (admin: category form validation)

   One external file is used rather than inline <script> blocks so the same
   behaviour is shared by all four pages and the HTML stays readable.

   Every check written here is also written again in PHP. JavaScript runs on
   the user's own computer, so it can be switched off or edited - it makes the
   page pleasant to use, but the PHP is what actually protects the database.
   ===================================================================== */

/* Everything starts once the browser has finished building the page, so the
   elements we look for definitely exist by the time we search for them. */
document.addEventListener('DOMContentLoaded', function () {
    setUpConfirmForms();
    setUpStarPicker();
    setUpCharCounter();
    setUpQuantityStepper();
    setUpReviewValidation();
    setUpStoreFilters();
    setUpEquipmentAdminForm();
    setUpImagePreview();
    setUpCategoryForm();
});


/* ---------------------------------------------------------------------
   Small helpers
   --------------------------------------------------------------------- */

/**
 * Shows a message under one form field and marks the field red.
 * The message element is created here rather than sitting in the HTML, so a
 * field with no problem has no empty tag left behind.
 */
function showFieldError(field, message) {
    field.classList.add('invalid');

    var group = field.closest('.form-group');
    if (!group) {
        return;
    }

    var error = group.querySelector('.field-error');
    if (!error) {
        error = document.createElement('div');
        error.className = 'field-error';
        group.appendChild(error);
    }
    error.textContent = message;
}

/** Clears the red border and the message from one field. */
function clearFieldError(field) {
    field.classList.remove('invalid');

    var group = field.closest('.form-group');
    if (!group) {
        return;
    }
    var error = group.querySelector('.field-error');
    if (error) {
        error.remove();
    }
}

/** Clears every error message inside one form before re-checking it. */
function clearAllErrors(form) {
    var fields = form.querySelectorAll('.invalid');
    for (var i = 0; i < fields.length; i++) {
        fields[i].classList.remove('invalid');
    }
    var messages = form.querySelectorAll('.field-error');
    for (var j = 0; j < messages.length; j++) {
        messages[j].remove();
    }
}

/** True when the text is empty or only spaces. */
function isBlank(value) {
    return value.trim().length === 0;
}


/* ---------------------------------------------------------------------
   1. Confirm before destructive actions
   Any form marked class="js-confirm" data-confirm="..." asks first.
   --------------------------------------------------------------------- */
function setUpConfirmForms() {
    var forms = document.querySelectorAll('form.js-confirm');

    for (var i = 0; i < forms.length; i++) {
        forms[i].addEventListener('submit', function (event) {
            var message = this.getAttribute('data-confirm') || 'Are you sure?';
            if (!confirm(message)) {
                // Stop the form from being sent when the user picks Cancel.
                event.preventDefault();
            }
        });
    }
}


/* ---------------------------------------------------------------------
   2. Star rating picker (product details page)
   Builds five clickable stars and keeps the number input in step with them.
   The number input is the field that actually gets posted, so the form still
   works if JavaScript never runs.
   --------------------------------------------------------------------- */
function setUpStarPicker() {
    var picker = document.getElementById('starPicker');
    var input = document.getElementById('ratingInput');

    if (!picker || !input) {
        return;
    }

    // Build the five buttons with the DOM rather than writing them in the HTML,
    // because without JavaScript they would do nothing anyway.
    for (var star = 1; star <= 5; star++) {
        var button = document.createElement('button');
        button.type = 'button';
        button.textContent = '★';
        button.setAttribute('data-value', star);
        button.setAttribute('aria-label', star + ' star');
        picker.appendChild(button);
    }

    var buttons = picker.querySelectorAll('button');

    /* Paints the first n stars gold and leaves the rest grey. */
    function paint(upTo) {
        for (var i = 0; i < buttons.length; i++) {
            var value = Number(buttons[i].getAttribute('data-value'));
            if (value <= upTo) {
                buttons[i].classList.add('is-active');
            } else {
                buttons[i].classList.remove('is-active');
            }
        }
    }

    for (var i = 0; i < buttons.length; i++) {
        // Click sets the rating for real.
        buttons[i].addEventListener('click', function () {
            var value = Number(this.getAttribute('data-value'));
            input.value = value;
            paint(value);
            clearFieldError(input);
        });

        // Hovering previews the rating without committing to it.
        buttons[i].addEventListener('mouseover', function () {
            paint(Number(this.getAttribute('data-value')));
        });
    }

    // Leaving the row puts the display back to whatever is actually selected.
    picker.addEventListener('mouseout', function () {
        paint(Number(input.value) || 0);
    });

    // Typing in the number box directly should move the stars too.
    input.addEventListener('input', function () {
        paint(Number(input.value) || 0);
    });

    // Show the existing rating when the customer is editing their own review.
    paint(Number(input.value) || 0);
}


/* ---------------------------------------------------------------------
   3. Live character counter on the review comment box
   --------------------------------------------------------------------- */
function setUpCharCounter() {
    var form = document.getElementById('reviewForm');
    var comment = document.getElementById('comment');
    var counter = document.getElementById('charCounter');

    if (!form || !comment || !counter) {
        return;
    }

    var min = Number(form.getAttribute('data-min'));
    var max = Number(form.getAttribute('data-max'));

    function update() {
        var length = comment.value.trim().length;
        counter.textContent = length + ' / ' + max + ' characters';

        // Turn the counter red when the text is too short or too long, which
        // is the same rule the PHP applies when the form is submitted.
        if (length > max || (length > 0 && length < min)) {
            counter.classList.add('over');
        } else {
            counter.classList.remove('over');
        }

        if (length >= min && length <= max) {
            clearFieldError(comment);
        }
    }

    comment.addEventListener('input', update);
    update();
}


/* ---------------------------------------------------------------------
   4. Quantity stepper and live line total (product details page)
   --------------------------------------------------------------------- */
function setUpQuantityStepper() {
    var form = document.getElementById('addToCartForm');
    if (!form) {
        return;
    }

    var input = document.getElementById('quantity');
    var down = document.getElementById('qtyDown');
    var up = document.getElementById('qtyUp');
    var total = document.getElementById('lineTotal');

    if (!input || !down || !up || !total) {
        return;
    }

    var stock = Number(form.getAttribute('data-stock'));
    var price = Number(form.getAttribute('data-price'));

    function currentQuantity() {
        var value = Number(input.value);
        // Number('') gives 0 and Number('abc') gives NaN; comparing with < 1
        // is false for NaN, so this also catches typed rubbish.
        if (!(value >= 1)) {
            return 1;
        }
        return Math.floor(value);
    }

    function update() {
        var quantity = currentQuantity();

        if (quantity > stock) {
            quantity = stock;
        }
        input.value = quantity;

        // toFixed(2) keeps the running total looking like money.
        total.textContent = 'RM' + (quantity * price).toFixed(2);

        // Grey out the buttons at the ends of the range.
        down.disabled = quantity <= 1;
        up.disabled = quantity >= stock;
    }

    down.addEventListener('click', function () {
        input.value = currentQuantity() - 1;
        update();
    });

    up.addEventListener('click', function () {
        input.value = currentQuantity() + 1;
        update();
    });

    input.addEventListener('input', update);
    update();
}


/* ---------------------------------------------------------------------
   5. Review form validation
   --------------------------------------------------------------------- */
function setUpReviewValidation() {
    var form = document.getElementById('reviewForm');
    if (!form) {
        return;
    }

    var min = Number(form.getAttribute('data-min'));
    var max = Number(form.getAttribute('data-max'));

    form.addEventListener('submit', function (event) {
        clearAllErrors(form);

        var rating = document.getElementById('ratingInput');
        var comment = document.getElementById('comment');
        var problems = 0;

        var ratingValue = Number(rating.value);
        if (!(ratingValue >= 1 && ratingValue <= 5)) {
            showFieldError(rating, 'Please choose a rating from 1 to 5 stars.');
            problems++;
        }

        var length = comment.value.trim().length;
        if (isBlank(comment.value)) {
            showFieldError(comment, 'Please write a short comment with your review.');
            problems++;
        } else if (length < min) {
            showFieldError(comment, 'Your comment must be at least ' + min + ' characters (currently ' + length + ').');
            problems++;
        } else if (length > max) {
            showFieldError(comment, 'Your comment cannot be longer than ' + max + ' characters.');
            problems++;
        }

        if (problems > 0) {
            event.preventDefault();
            alert('Please fix ' + problems + ' problem(s) before posting your review.');
        }
    });
}


/* ---------------------------------------------------------------------
   6. Live search, filter and sort on the product listing
   This filters the cards already on the page, so results appear as the
   customer types instead of waiting for the server to reload the page.
   The PHP search form still works on its own for anyone without JavaScript.
   --------------------------------------------------------------------- */
function setUpStoreFilters() {
    var grid = document.getElementById('productGrid');
    if (!grid) {
        return;
    }

    var search = document.getElementById('liveSearch');
    var category = document.getElementById('liveCategory');
    var sort = document.getElementById('liveSort');
    var count = document.getElementById('resultCount');
    var empty = document.getElementById('noResults');

    var cards = grid.querySelectorAll('.product-card');

    function applyFilters() {
        var term = search ? search.value.trim().toLowerCase() : '';
        var wantedCategory = category ? category.value : '';
        var shown = 0;

        for (var i = 0; i < cards.length; i++) {
            var card = cards[i];

            // Everything searchable was written onto the card as data-*
            // attributes by the PHP, so no extra request is needed here.
            var haystack = (
                card.getAttribute('data-name') + ' ' +
                card.getAttribute('data-brand') + ' ' +
                card.getAttribute('data-category') + ' ' +
                card.getAttribute('data-sport')
            ).toLowerCase();

            var matchesTerm = term === '' || haystack.indexOf(term) !== -1;
            var matchesCategory = wantedCategory === '' ||
                card.getAttribute('data-category') === wantedCategory;

            if (matchesTerm && matchesCategory) {
                card.classList.remove('is-hidden');
                shown++;
            } else {
                card.classList.add('is-hidden');
            }
        }

        if (count) {
            count.textContent = 'Showing ' + shown + ' of ' + cards.length + ' products';
        }
        if (empty) {
            empty.style.display = shown === 0 ? 'block' : 'none';
        }
    }

    function applySort() {
        if (!sort) {
            return;
        }

        var order = sort.value;

        // Copy the live NodeList into a normal array so it can be sorted.
        var list = [];
        for (var i = 0; i < cards.length; i++) {
            list.push(cards[i]);
        }

        list.sort(function (a, b) {
            if (order === 'price_asc') {
                return Number(a.getAttribute('data-price')) - Number(b.getAttribute('data-price'));
            }
            if (order === 'price_desc') {
                return Number(b.getAttribute('data-price')) - Number(a.getAttribute('data-price'));
            }
            if (order === 'rating_desc') {
                return Number(b.getAttribute('data-rating')) - Number(a.getAttribute('data-rating'));
            }
            return a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'));
        });

        // appendChild on an element that is already in the page moves it to the
        // end, so appending them in the new order re-orders the whole grid.
        for (var j = 0; j < list.length; j++) {
            grid.appendChild(list[j]);
        }
    }

    if (search) {
        search.addEventListener('input', applyFilters);
    }
    if (category) {
        category.addEventListener('change', applyFilters);
    }
    if (sort) {
        sort.addEventListener('change', function () {
            applySort();
            applyFilters();
        });
    }

    applyFilters();
}


/* ---------------------------------------------------------------------
   7. Admin equipment form validation (add / edit)
   --------------------------------------------------------------------- */
function setUpEquipmentAdminForm() {
    var forms = document.querySelectorAll('form.js-equipment-form');

    for (var i = 0; i < forms.length; i++) {
        forms[i].addEventListener('submit', function (event) {
            var form = this;
            clearAllErrors(form);

            var name = form.querySelector('[name="name"]');
            var price = form.querySelector('[name="price"]');
            var stock = form.querySelector('[name="stock"]');
            var categoryId = form.querySelector('[name="category_id"]');
            var problems = 0;

            if (name && isBlank(name.value)) {
                showFieldError(name, 'Equipment name is required.');
                problems++;
            }

            if (price) {
                var priceValue = Number(price.value);
                if (isBlank(price.value) || !(priceValue > 0)) {
                    showFieldError(price, 'Price must be a number greater than zero.');
                    problems++;
                }
            }

            if (stock) {
                var stockValue = Number(stock.value);
                if (isBlank(stock.value) || !(stockValue >= 0)) {
                    showFieldError(stock, 'Stock cannot be negative.');
                    problems++;
                } else if (Math.floor(stockValue) !== stockValue) {
                    showFieldError(stock, 'Stock must be a whole number.');
                    problems++;
                }
            }

            if (categoryId && isBlank(categoryId.value)) {
                showFieldError(categoryId, 'Please choose a category.');
                problems++;
            }

            if (problems > 0) {
                event.preventDefault();
                alert('Please fix ' + problems + ' problem(s) before saving.');
            }
        });
    }
}


/* ---------------------------------------------------------------------
   8. Live image preview on the admin form
   Lets the admin see whether the image path is right before saving.
   --------------------------------------------------------------------- */
function setUpImagePreview() {
    var inputs = document.querySelectorAll('.js-image-url');

    for (var i = 0; i < inputs.length; i++) {
        inputs[i].addEventListener('input', function () {
            var group = this.closest('.form-group');
            if (!group) {
                return;
            }

            var preview = group.querySelector('.image-preview');
            var path = this.value.trim();

            if (path === '') {
                if (preview) {
                    preview.remove();
                }
                return;
            }

            if (!preview) {
                preview = document.createElement('img');
                preview.className = 'image-preview';
                preview.alt = 'Image preview';
                group.appendChild(preview);
            }

            // A path saved as images/badminton.jpg is relative to the project
            // root, and this page sits one folder down inside /admin.
            preview.src = path.indexOf('http') === 0 ? path : '../' + path;
        });
    }
}


/* ---------------------------------------------------------------------
   9. Category form validation (admin/categories.php)
   Also checks the typed name against the categories already on the page, so
   a duplicate is caught before the server has to reject it.
   --------------------------------------------------------------------- */
function setUpCategoryForm() {
    var forms = document.querySelectorAll('form.js-category-form');

    for (var i = 0; i < forms.length; i++) {
        forms[i].addEventListener('submit', function (event) {
            var form = this;
            clearAllErrors(form);

            var name = form.querySelector('[name="name"]');
            var problems = 0;

            if (name && isBlank(name.value)) {
                showFieldError(name, 'Category name is required.');
                problems++;
            } else if (name) {
                var typed = name.value.trim().toLowerCase();
                var currentId = form.getAttribute('data-editing-id') || '';
                var existing = document.querySelectorAll('#categoryTable [data-category-name]');

                for (var j = 0; j < existing.length; j++) {
                    var row = existing[j];
                    var takenBy = row.getAttribute('data-category-id');
                    var takenName = row.getAttribute('data-category-name').toLowerCase();

                    // Renaming a category to its own current name is fine.
                    if (takenName === typed && takenBy !== currentId) {
                        showFieldError(name, 'A category called "' + name.value.trim() + '" already exists.');
                        problems++;
                        break;
                    }
                }
            }

            if (problems > 0) {
                event.preventDefault();
                alert('Please fix ' + problems + ' problem(s) before saving.');
            }
        });
    }
}
