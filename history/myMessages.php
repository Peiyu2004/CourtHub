<?php

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';

requireLogin();

$user_id = (int)$_SESSION['user_id'];

$messages = [];

// Get customer's own messages
$stmt = $conn->prepare(
    "SELECT message_id, message, status, created_at
     FROM contact_messages
     WHERE user_id = ?
     ORDER BY created_at DESC"
);

$stmt->bind_param("i", $user_id);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $messages[] = $row;
}

$stmt->close();


$page_title = 'My Messages';
$extra_css = ['shop'];

require_once __DIR__ . '/../includes/header.php';

?>


<section class="page-hero">

    <h1>My History</h1>

    <p class="muted">
        Your court reservations, equipment orders and enquiries.
    </p>

    <?php renderHistoryTabs('messages'); ?>

</section>



<section class="card">

    <h2>My Messages</h2>

    <p class="muted">
        View your enquiries and track their progress.
    </p>

</section>

<section class="card">

    <?php if (empty($messages)): ?>

        <div class="empty-state">
            You have not sent any messages yet.
        </div>


        <a href="<?= h(app_url('/contact.php')) ?>" class="btn">
            Contact Us
        </a>


    <?php else: ?>


        <?php foreach ($messages as $msg): ?>

            <div class="message-box">

                <div class="message-content-row">

                    <p class="message-text">
                        <?= nl2br(h($msg['message'])) ?>
                    </p>


                    <?php if ($msg['status'] === 'New'): ?>

                        <span class="status-pill status-new">
                            New
                        </span>

                    <?php elseif ($msg['status'] === 'In Progress'): ?>

                        <span class="status-pill status-progress">
                            In Progress
                        </span>

                    <?php else: ?>

                        <span class="status-pill status-completed">
                            Completed
                        </span>

                    <?php endif; ?>

                </div>


                <p class="muted">
                    Sent on
                    <?= h(date(
                        'd M Y, h:i A',
                        strtotime($msg['created_at'])
                    )) ?>
                </p>

            </div>

        <?php endforeach; ?>


    <?php endif; ?>


</section>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>