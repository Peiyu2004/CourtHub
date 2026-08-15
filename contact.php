<?php

require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/config/functions.php';

/*
 * The form is handled before includes/header.php is pulled in, because a
 * successful send finishes with a redirect and a redirect cannot be issued
 * once the page has started being sent to the browser.
 */

// The longest value each column can hold. Checking here rather than letting
// MySQL decide matters: this server has an empty sql_mode so an over-long
// value is quietly cut short, but a default MySQL install runs with
// STRICT_TRANS_TABLES and raises "Data too long" instead - which, with mysqli
// in exception mode, would end the page with an error instead of a message.
const CONTACT_NAME_MAX    = 100;
const CONTACT_EMAIL_MAX   = 100;
const CONTACT_PHONE_MAX   = 20;
const CONTACT_MESSAGE_MAX = 2000;

$error_message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // trim() so a box holding only spaces counts as empty rather than being
    // saved as blank-looking data.
    $name    = trim($_POST["name"] ?? "");
    $email   = trim($_POST["email"] ?? "");
    $phone   = trim($_POST["phone"] ?? "");
    $message = trim($_POST["message"] ?? "");

    $errors = [];

    // Required
    if ($name === "" || $email === "" || $phone === "" || $message === "") {
        $errors[] = "Please fill in all fields.";
    }

    // Format. The form uses type="email" and a pattern, but a form can be sent
    // without a browser, so the same rules are applied again here.
    if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }
    if ($phone !== "" && !preg_match('/^[0-9+\-\s]{7,20}$/', $phone)) {
        $errors[] = "Please enter a valid phone number.";
    }

    // Length
    if (mb_strlen($name) > CONTACT_NAME_MAX) {
        $errors[] = "Name cannot be longer than " . CONTACT_NAME_MAX . " characters.";
    }
    if (mb_strlen($email) > CONTACT_EMAIL_MAX) {
        $errors[] = "Email cannot be longer than " . CONTACT_EMAIL_MAX . " characters.";
    }
    if (mb_strlen($phone) > CONTACT_PHONE_MAX) {
        $errors[] = "Phone number cannot be longer than " . CONTACT_PHONE_MAX . " characters.";
    }
    if (mb_strlen($message) > CONTACT_MESSAGE_MAX) {
        $errors[] = "Message cannot be longer than " . CONTACT_MESSAGE_MAX . " characters.";
    }

    if (!empty($errors)) {

        $error_message = implode(" ", $errors);

    } else {

        try {

            $sql = "INSERT INTO contact_messages
                    (name, email, phone, message)
                    VALUES (?, ?, ?, ?)";

            $stmt = $conn->prepare($sql);

            $stmt->bind_param(
                "ssss",
                $name,
                $email,
                $phone,
                $message
            );

            $stmt->execute();
            $stmt->close();

            $_SESSION['success_message'] =
            "Your message has been sent successfully. We will get back to you soon.";

            header("Location: " . app_url('/contact.php'));
            exit();

        } catch (mysqli_sql_exception $e) {

            // mysqli is set to throw rather than return false, so a database
            // problem arrives here. The visitor gets a plain message and the
            // driver's own text is not shown, since it can name the database.
            $error_message = "Sorry, your message could not be sent. Please try again.";

        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<main class="contact-page">

    <section class="contact-container">

        <h1>Contact Us</h1>

        <p>
            Have questions about court reservations, equipment or our services?
            Contact CourtHub Sport Center and we will be happy to assist you.
        </p>

        <div class="contact-details">

            <div class="contact-box">
                <h3>📍 Address</h3>
                <p>
                    CourtHub Sport Center<br>
                    Kuala Lumpur, Malaysia
                </p>
            </div>

            <div class="contact-box">
                <h3>📞 Phone</h3>
                <p>
                    +60 12-566 6710
                </p>
            </div>

            <div class="contact-box">
                <h3>✉ Email</h3>
                <p>
                    support@courthub.com
                </p>
            </div>

        </div>


        <h2>Send Us a Message</h2>

        <?php

        if (isset($_SESSION['success_message'])) {

            echo '
            <div class="alert alert-success">
                ' . $_SESSION['success_message'] . '
            </div>
            ';

            unset($_SESSION['success_message']);

        }

        // Anything the checks above rejected is reported here. Without this the
        // form simply did nothing on a bad entry and never said why.
        if ($error_message !== "") {

            echo '
            <div class="alert alert-error">
                ' . h($error_message) . '
            </div>
            ';

        }

        ?>

        <form class="contact-form" method="post" action="contact.php">

            <!-- maxlength matches the column sizes checked in PHP above, so the
                 browser stops an over-long value before it is ever sent. The PHP
                 checks it again, because a form can be sent without a browser. -->

            <div class="form-group">
                <label>Name</label>
                <input type="text" name="name" maxlength="<?= CONTACT_NAME_MAX ?>" required
                       value="<?= h($_POST['name'] ?? '') ?>">
            </div>


            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" maxlength="<?= CONTACT_EMAIL_MAX ?>" required
                       value="<?= h($_POST['email'] ?? '') ?>">
            </div>


            <div class="form-group">
                <label>Phone Number (e.g. 0123456789)</label>
                <input
                    type="tel"
                    name="phone"
                    inputmode="numeric"
                    pattern="[0-9]{7,20}"
                    minlength="7"
                    maxlength="<?= CONTACT_PHONE_MAX ?>"
                    oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                    required
                    value="<?= h($_POST['phone'] ?? '') ?>">
            </div>


            <div class="form-group">
                <label>Message</label>
                <textarea name="message" rows="5" maxlength="<?= CONTACT_MESSAGE_MAX ?>" required><?= h($_POST['message'] ?? '') ?></textarea>
            </div>


            <button type="submit" class="btn">
                Send Message
            </button>

        </form>

    </section>

</main>


<?php
require_once "includes/footer.php";
?>