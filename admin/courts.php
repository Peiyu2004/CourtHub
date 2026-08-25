<?php
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';

requireAdmin();
finalizePendingCourtDeletions($conn);

$sports = getSportTypes($conn);
$errors = [];
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $court_number = trim($_POST['court_number'] ?? '');
        $sport_type_id = (int)($_POST['sport_type_id'] ?? 0);

        if ($court_number === '') {
            $errors[] = "Court name is required.";
        }

        $sport_exists = false;
        foreach ($sports as $sport) {
            if ((int)$sport['sport_type_id'] === $sport_type_id) {
                $sport_exists = true;
                break;
            }
        }
        if (!$sport_exists) {
            $errors[] = "Please choose a valid sport type.";
        }

        if (empty($errors)) {
            $stmt = $conn->prepare("INSERT INTO courts (court_number, sport_type_id, status) VALUES (?, ?, 'active')");
            $stmt->bind_param("si", $court_number, $sport_type_id);
            if ($stmt->execute()) {
                $notice = "Court added successfully.";
            } else {
                $errors[] = "Court could not be added.";
            }
            $stmt->close();
        }
    }

    if ($action === 'update_court') {
        $court_id = (int)($_POST['court_id'] ?? 0);
        $court_number = trim($_POST['court_number'] ?? '');
        $sport_type_id = (int)($_POST['sport_type_id'] ?? 0);

        if ($court_number === '') {
            $errors[] = "Court name is required.";
        }

        $sport_exists = false;
        foreach ($sports as $sport) {
            if ((int)$sport['sport_type_id'] === $sport_type_id) {
                $sport_exists = true;
                break;
            }
        }
        if (!$sport_exists) {
            $errors[] = "Please choose a valid sport type.";
        }

        if (empty($errors)) {
            $stmt = $conn->prepare(
                "UPDATE courts
                 SET court_number = ?, sport_type_id = ?
                 WHERE court_id = ? AND status = 'active'"
            );
            $stmt->bind_param("sii", $court_number, $sport_type_id, $court_id);
            $stmt->execute();
            if ($stmt->affected_rows > 0) {
                $notice = "Court updated successfully.";
            } else {
                $errors[] = "Court could not be updated. Only active courts can be edited.";
            }
            $stmt->close();
        }
    }

    if ($action === 'update_price') {
        $sport_type_id = (int)($_POST['sport_type_id'] ?? 0);
        $price_per_hour = (float)($_POST['price_per_hour'] ?? 0);

        if ($price_per_hour <= 0) {
            $errors[] = "Hourly price must be more than zero.";
        }

        $sport_exists = false;
        foreach ($sports as $sport) {
            if ((int)$sport['sport_type_id'] === $sport_type_id) {
                $sport_exists = true;
                break;
            }
        }
        if (!$sport_exists) {
            $errors[] = "Please choose a valid sport type.";
        }

        if (empty($errors)) {
            $stmt = $conn->prepare("UPDATE sport_types SET price_per_hour = ? WHERE sport_type_id = ?");
            $stmt->bind_param("di", $price_per_hour, $sport_type_id);
            $stmt->execute();
            $stmt->close();
            $notice = "Facility hourly price updated successfully.";
            $sports = getSportTypes($conn);
        }
    }

    if ($action === 'delete') {
        $court_id = (int)($_POST['court_id'] ?? 0);
        $stmt = $conn->prepare("SELECT court_number, status FROM courts WHERE court_id = ? AND status <> 'deleted'");
        $stmt->bind_param("i", $court_id);
        $stmt->execute();
        $court = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        /*
         * A court with a reservation still to come cannot be deleted at all.
         *
         * It used to be accepted and turned into a 'pending_deletion' court,
         * which stopped taking new bookings and vanished once the old ones had
         * been played. That quietly retired a court the customers holding
         * those bookings could no longer see, on an admin click that read like
         * an ordinary delete. Refusing outright keeps the court and the people
         * booked on it visible, and says when it can be removed instead.
         *
         * This check is the one that decides. The Delete button is already
         * hidden for these courts further down the page, but that state was
         * worked out when the page was drawn, and somebody can pay for a
         * booking in between - so the answer is asked for again here, at the
         * moment it is acted on.
         */
        $block = $court ? courtBookingBlock($conn, $court_id) : ['count' => 0, 'last_ends_at' => null];

        if (!$court) {
            $errors[] = "Court could not be found.";
        } elseif ($block['count'] > 0) {
            $errors[] = $court['court_number'] . " cannot be deleted: "
                      . $block['count'] . " paid reservation"
                      . ($block['count'] === 1 ? '' : 's')
                      . " on it "
                      . ($block['count'] === 1 ? 'has' : 'have')
                      . " not finished yet. The last one ends on "
                      . date('j M Y, g:i A', strtotime($block['last_ends_at']))
                      . ". You can delete this court once it has passed.";
        } else {
            $stmt = $conn->prepare("UPDATE courts SET status = 'deleted' WHERE court_id = ?");
            $stmt->bind_param("i", $court_id);
            $stmt->execute();
            $stmt->close();
            $notice = $court['court_number'] . " has been deleted from the system.";
        }
    }
}

$courts = [];
$result = $conn->query(
    "SELECT c.court_id, c.court_number, c.sport_type_id, c.status, st.name AS sport_name
     FROM courts c
     JOIN sport_types st ON c.sport_type_id = st.sport_type_id
     WHERE c.status <> 'deleted'
     ORDER BY st.sport_type_id, c.court_id"
);
while ($row = $result->fetch_assoc()) {
    $courts[] = $row;
}

// How many unfinished reservations each court is carrying, for the whole table
// in one query. A court that appears here cannot be deleted, so its Delete
// button is replaced with the reason.
$courts_with_bookings = courtsWithUnfinishedBookings($conn);

// Get order count for sidebar badge indicator
$order_counts = function_exists('equipmentOrderStatusCounts') ? equipmentOrderStatusCounts($conn) : ['pending' => 0];

// Admin pages use the wider container - see includes/header.php.
$wide_layout = true;
$page_title = 'Manage Courts';
$extra_css = ['admin'];
require_once __DIR__ . '/../includes/header.php';
?>


<section class="page-hero">
    <h1>Court Management</h1>
    <p class="muted">Maintain court listings, hourly rates and availability.</p>
</section>

<div class="dashboard-layout">
    <!-- Persistent Admin Panel -->
    <?php include __DIR__ . '/../includes/adminPanel.php'; ?>

    <!-- Main Content Area -->
    <div class="dashboard-main-content">
        <section class="card">
            <h1>Manage Courts</h1>
            <p class="muted">
                A court that still has reservations to come cannot be deleted.
                Its Delete button stays off until the last booking on it has
                been played, so nobody loses a court they have paid for.
            </p>
        </section>

        <?php if ($notice): ?>
            <div class="alert alert-success"><?= h($notice) ?></div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?>
                    <p><?= h($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="grid two-col">
            <div class="card">
                <h2>Add Court</h2>
                <form method="POST" action="<?= h(app_url('/admin/courts.php')) ?>">
                    <input type="hidden" name="action" value="add">
                    <div class="form-group">
                        <label for="court_number">Court Name</label>
                        <input type="text" id="court_number" name="court_number" placeholder="Badminton Court 7" required>
                    </div>
                    <div class="form-group">
                        <label for="sport_type_id">Sport Type</label>
                        <select id="sport_type_id" name="sport_type_id" required>
                            <?php foreach ($sports as $sport): ?>
                                <option value="<?= (int)$sport['sport_type_id'] ?>"><?= h($sport['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn">Add Court</button>
                </form>
            </div>

            <div class="card">
                <h2>Facility Prices</h2>
                <p class="muted">These prices are used for new reservations. Existing paid reservations keep their original paid price.</p>
                <?php foreach ($sports as $sport): ?>
                    <form method="POST" action="<?= h(app_url('/admin/courts.php')) ?>" class="inline-form price-form">
                        <input type="hidden" name="action" value="update_price">
                        <input type="hidden" name="sport_type_id" value="<?= (int)$sport['sport_type_id'] ?>">
                        <label for="price_<?= (int)$sport['sport_type_id'] ?>"><?= h($sport['name']) ?></label>
                        <input type="number" id="price_<?= (int)$sport['sport_type_id'] ?>" name="price_per_hour" value="<?= h($sport['price_per_hour']) ?>" min="0.01" step="0.01" required>
                        <button type="submit" class="btn btn-secondary">Update Price</button>
                    </form>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="card">
                <h2>Current Courts</h2>
                <?php if (empty($courts)): ?>
                    <div class="empty-state">No courts are currently displayed.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Court</th>
                                    <th>Sport</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($courts as $court): ?>
                                    <?php $court_form_id = 'court_form_' . (int)$court['court_id']; ?>
                                    <tr>
                                        <td>
                                            <div class="form-group">
                                            <?php if ($court['status'] === 'active'): ?>
                                                <form id="<?= h($court_form_id) ?>" method="POST" action="<?= h(app_url('/admin/courts.php')) ?>"></form>
                                                <input type="hidden" name="action" value="update_court" form="<?= h($court_form_id) ?>">
                                                <input type="hidden" name="court_id" value="<?= (int)$court['court_id'] ?>" form="<?= h($court_form_id) ?>">
                                                <input type="text" name="court_number" value="<?= h($court['court_number']) ?>" form="<?= h($court_form_id) ?>" required>
                                            <?php else: ?>
                                                <?= h($court['court_number']) ?>
                                            <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="form-group">
                                            <?php if ($court['status'] === 'active'): ?>
                                                <select name="sport_type_id" form="<?= h($court_form_id) ?>" required>
                                                    <?php foreach ($sports as $sport): ?>
                                                        <option value="<?= (int)$sport['sport_type_id'] ?>" <?= (int)$court['sport_type_id'] === (int)$sport['sport_type_id'] ? 'selected' : '' ?>>
                                                            <?= h($sport['name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            <?php else: ?>
                                                <?= h($court['sport_name']) ?>
                                            <?php endif; ?>
                                            </div>
                                        </td>
                                        <td><span class="status <?= h($court['status']) ?>"><?= h(str_replace('_', ' ', $court['status'])) ?></span></td>
                                        <td>
                                            <?php if ($court['status'] !== 'pending_deletion'): ?>
                                                <button type="submit" class="btn btn-secondary" form="<?= h($court_form_id) ?>">Save</button>

                                                <?php
                                                    // Courts carrying a reservation that has not been
                                                    // played cannot be deleted, so the button is not
                                                    // offered. Saying why here beats letting the admin
                                                    // click and be refused. The POST is checked again
                                                    // regardless - see the delete action above.
                                                    $pending_bookings = (int)($courts_with_bookings[(int)$court['court_id']] ?? 0);
                                                ?>

                                                <?php if ($pending_bookings > 0): ?>
                                                    <button type="button" class="btn btn-danger" disabled
                                                            title="This court has <?= $pending_bookings ?> reservation<?= $pending_bookings === 1 ? '' : 's' ?> that <?= $pending_bookings === 1 ? 'has' : 'have' ?> not finished yet.">Delete</button>
                                                    <span class="muted">
                                                        Booked &mdash; <?= $pending_bookings ?> reservation<?= $pending_bookings === 1 ? '' : 's' ?> still to come
                                                    </span>
                                                <?php else: ?>
                                                    <form method="POST" action="<?= h(app_url('/admin/courts.php')) ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="court_id" value="<?= (int)$court['court_id'] ?>">
                                                        <button type="submit" class="btn btn-danger">Delete</button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="muted">Disabled</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
        </section>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
