<?php

session_start();

require_once __DIR__ . '/config/db_connect.php';
require_once "includes/header.php";


if ($_SERVER["REQUEST_METHOD"] == "POST") {


    $name = $_POST["name"] ?? "";
    $email = $_POST["email"] ?? "";
    $phone = $_POST["phone"] ?? "";
    $message = $_POST["message"] ?? "";


    // Check empty fields
    if ($name == "" || $email == "" || $phone == "" || $message == "") {

        $error_message = "Please fill in all fields.";

    } 
    else {


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


        if ($stmt->execute()) {


            $_SESSION['success_message'] = 
            "Your message has been sent successfully. We will get back to you soon.";


            header("Location: contact.php");
            exit();


        } else {

            $error_message = "Failed to send message.";

        }


        $stmt->close();

    }

}

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

        ?>

        <form class="contact-form" method="post" action="contact.php">

            <div class="form-group">
                <label>Your Name</label>
                <input type="text" name="name" required>
            </div>


            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" required>
            </div>


            <div class="form-group">
                <label>Phone Number</label>
                <input type="tel" name="phone" required>
            </div>


            <div class="form-group">
                <label>Your Message</label>
                <textarea name="message" rows="5" required></textarea>
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