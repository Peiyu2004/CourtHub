<?php

session_start();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/db_connect.php';

// Get all customer messages
$sql = "SELECT message_id, name, email, phone, message, created_at
        FROM contact_messages
        ORDER BY created_at DESC";

$result = $conn->query($sql);

?>

<main class="site-main">

    <section class="card messages-card">

        <div class="split-row">

            <div>
                <h1>Customer Messages</h1>

                <p class="muted">
                    Messages submitted through the Contact Us form.
                </p>
            </div>

            <div class="amount-pill">
                <?= (int)$result->num_rows ?> Messages
            </div>

        </div>


        <?php if ($result->num_rows === 0): ?>

            <div class="empty-state">
                No customer messages yet.
            </div>

        <?php else: ?>

            <div class="table-wrap">

                <table class="messages-table">

                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Message</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php while ($row = $result->fetch_assoc()): ?>

                            <tr>

                                <td class="message-date">
                                    <?= htmlspecialchars(
                                        date(
                                            'd M Y, h:i A',
                                            strtotime($row['created_at'])
                                        )
                                    ) ?>
                                </td>

                                <td>
                                    <strong>
                                        <?= htmlspecialchars($row['name']) ?>
                                    </strong>
                                </td>

                                <td>
                                    <?= htmlspecialchars($row['email']) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($row['phone']) ?>
                                </td>

                                <td class="message-content">
                                    <?= nl2br(
                                        htmlspecialchars($row['message'])
                                    ) ?>
                                </td>

                            </tr>

                        <?php endwhile; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </section>

</main>

<?php

require_once __DIR__ . '/../includes/footer.php';

?>