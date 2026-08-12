<?php

session_start();

// Admin pages use the wider container - see includes/header.php.
$wide_layout = true;
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/db_connect.php';

// UPDATE MESSAGE STATUS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message_id'], $_POST['status'])) {

    $message_id = (int) $_POST['message_id'];
    $status = $_POST['status'];

    $allowed_statuses = ['New', 'In Progress', 'Completed'];

    if (in_array($status, $allowed_statuses, true)) {

        $stmt = $conn->prepare(
            "UPDATE contact_messages 
             SET status = ? 
             WHERE message_id = ?"
        );

        $stmt->bind_param("si", $status, $message_id);
        $stmt->execute();
        $stmt->close();
    }

    // Reload page after updating
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}


// GET ALL CUSTOMER MESSAGES
$sql = "SELECT message_id, name, email, phone, message, status, created_at
        FROM contact_messages
        ORDER BY created_at DESC";

$result = $conn->query($sql);

// Get selected filter
$filter = $_GET['filter'] ?? 'All';

$allowedFilters = ['All', 'New', 'In Progress', 'Completed'];

if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'All';
}


// Get customer messages based on filter
if ($filter === 'All') {

    $sql = "SELECT message_id, name, email, phone, message, status, created_at
            FROM contact_messages
            ORDER BY created_at DESC";

    $result = $conn->query($sql);

} else {

    $stmt = $conn->prepare(
        "SELECT message_id, name, email, phone, message, status, created_at
         FROM contact_messages
         WHERE status = ?
         ORDER BY created_at DESC"
    );

    $stmt->bind_param("s", $filter);
    $stmt->execute();

    $result = $stmt->get_result();
}


// Load header AFTER processing POST
// Admin pages use the wider container - see includes/header.php.
$wide_layout = true;
require_once __DIR__ . '/../includes/header.php';

?>

<section class="card">
    <h1>Admin Dashboard</h1>
    <p class="muted">Court reservation revenue and management shortcuts.</p>
</section>

<div class="dashboard-layout">

    <!-- Persistent Admin Sidebar -->
    <?php include __DIR__ . '/../includes/sideBar.php'; ?>


    <!-- Main Content -->
    <main class="dashboard-main-content">
        
        <section class="card">

            <div class="split-row">

                <div>
                    <h1>Customer Messages</h1>

                    <p class="muted">
                        Messages submitted through the Contact Us form.
                    </p>
                </div>

                <div class="message-filter">

                    <form method="GET">

                        <select
                            name="filter"
                            class="status-select"
                            onchange="this.form.submit()"
                        >

                            <option
                                value="All"
                                <?= $filter === 'All' ? 'selected' : '' ?>
                            >
                                All Messages
                            </option>

                            <option
                                value="New"
                                <?= $filter === 'New' ? 'selected' : '' ?>
                            >
                                New
                            </option>

                            <option
                                value="In Progress"
                                <?= $filter === 'In Progress' ? 'selected' : '' ?>
                            >
                                In Progress
                            </option>

                            <option
                                value="Completed"
                                <?= $filter === 'Completed' ? 'selected' : '' ?>
                            >
                                Completed
                            </option>

                        </select>

                    </form>

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
                                <th>Status</th>
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


                                    <!-- Status Column -->
                                    <td>

                                        <form method="POST" class="status-form">

                                            <input
                                                type="hidden"
                                                name="message_id"
                                                value="<?= (int)$row['message_id'] ?>"
                                            >

                                            <select
                                                name="status"
                                                class="status-select"
                                                onchange="this.form.submit()"
                                            >

                                                <option
                                                    value="New"
                                                    <?= $row['status'] === 'New' ? 'selected' : '' ?>
                                                >
                                                    New
                                                </option>

                                                <option
                                                    value="In Progress"
                                                    <?= $row['status'] === 'In Progress' ? 'selected' : '' ?>
                                                >
                                                    In Progress
                                                </option>

                                                <option
                                                    value="Completed"
                                                    <?= $row['status'] === 'Completed' ? 'selected' : '' ?>
                                                >
                                                    Completed
                                                </option>

                                            </select>

                                        </form>

                                    </td>

                                </tr>

                            <?php endwhile; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </section>

    </main>

</div>

<?php

require_once __DIR__ . '/../includes/footer.php';

?>