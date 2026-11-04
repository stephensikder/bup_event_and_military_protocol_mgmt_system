<?php

require_once __DIR__ . '/config/auth.php';
require_login();

$pdo = db();

$page_title = 'Emergency Contacts | BUP Protocol';


// =====================================================
// HANDLE POST ACTIONS
// =====================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';


    // =================================================
    // ADD CONTACT
    // =================================================

    if ($action === 'add') {

        $name = trim($_POST['name'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $notes = trim($_POST['notes'] ?? '');


        if ($name === '' || $phone === '') {

            flash(
                'error',
                'Name and phone number are required.'
            );

            redirect(
                BASE_URL . '/emergency.php'
            );
        }


        try {

            $pdo->beginTransaction();


            $stmt = $pdo->prepare("
                INSERT INTO emergency_contacts
                (
                    name,
                    designation,
                    phone,
                    department,
                    notes
                )
                VALUES (?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $name,
                $designation,
                $phone,
                $department,
                $notes
            ]);


            $contactId =
                (int)$pdo->lastInsertId();


            // Audit log

            $stmt = $pdo->prepare("
                INSERT INTO audit_logs
                (
                    user_id,
                    action,
                    description
                )
                VALUES (?, ?, ?)
            ");

            $stmt->execute([
                current_user()['id'],
                'Emergency Contact Added',
                'Emergency contact "' .
                $name .
                '" was added.'
            ]);


            $pdo->commit();


            flash(
                'success',
                'Emergency contact added successfully.'
            );

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            flash(
                'error',
                'Unable to add emergency contact.'
            );
        }


        redirect(
            BASE_URL . '/emergency.php'
        );
    }


    // =================================================
    // UPDATE CONTACT
    // =================================================

    if ($action === 'update') {

        $id = (int)($_POST['id'] ?? 0);

        $name = trim($_POST['name'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $notes = trim($_POST['notes'] ?? '');


        if (
            !$id ||
            $name === '' ||
            $phone === ''
        ) {

            flash(
                'error',
                'Name and phone number are required.'
            );

            redirect(
                BASE_URL . '/emergency.php'
            );
        }


        $stmt = $pdo->prepare("
            SELECT id, name
            FROM emergency_contacts
            WHERE id = ?
        ");

        $stmt->execute([$id]);

        $contact = $stmt->fetch();


        if (!$contact) {

            flash(
                'error',
                'Emergency contact not found.'
            );

            redirect(
                BASE_URL . '/emergency.php'
            );
        }


        try {

            $pdo->beginTransaction();


            $stmt = $pdo->prepare("
                UPDATE emergency_contacts
                SET
                    name = ?,
                    designation = ?,
                    phone = ?,
                    department = ?,
                    notes = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $name,
                $designation,
                $phone,
                $department,
                $notes,
                $id
            ]);


            // Audit log

            $stmt = $pdo->prepare("
                INSERT INTO audit_logs
                (
                    user_id,
                    action,
                    description
                )
                VALUES (?, ?, ?)
            ");

            $stmt->execute([
                current_user()['id'],
                'Emergency Contact Updated',
                'Emergency contact #' .
                $id .
                ' "' .
                $name .
                '" was updated.'
            ]);


            $pdo->commit();


            flash(
                'success',
                'Emergency contact updated successfully.'
            );

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            flash(
                'error',
                'Unable to update emergency contact.'
            );
        }


        redirect(
            BASE_URL . '/emergency.php'
        );
    }


    // =================================================
    // DELETE CONTACT
    // =================================================

    if ($action === 'delete') {

        $id = (int)($_POST['id'] ?? 0);


        if (!$id) {

            flash(
                'error',
                'Invalid emergency contact.'
            );

            redirect(
                BASE_URL . '/emergency.php'
            );
        }


        $stmt = $pdo->prepare("
            SELECT id, name
            FROM emergency_contacts
            WHERE id = ?
        ");

        $stmt->execute([$id]);

        $contact = $stmt->fetch();


        if (!$contact) {

            flash(
                'error',
                'Emergency contact not found.'
            );

            redirect(
                BASE_URL . '/emergency.php'
            );
        }


        try {

            $pdo->beginTransaction();


            $stmt = $pdo->prepare("
                DELETE FROM emergency_contacts
                WHERE id = ?
            ");

            $stmt->execute([$id]);


            // Audit log

            $stmt = $pdo->prepare("
                INSERT INTO audit_logs
                (
                    user_id,
                    action,
                    description
                )
                VALUES (?, ?, ?)
            ");

            $stmt->execute([
                current_user()['id'],
                'Emergency Contact Deleted',
                'Emergency contact #' .
                $id .
                ' "' .
                $contact['name'] .
                '" was deleted.'
            ]);


            $pdo->commit();


            flash(
                'success',
                'Emergency contact deleted successfully.'
            );

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            flash(
                'error',
                'Unable to delete emergency contact.'
            );
        }


        redirect(
            BASE_URL . '/emergency.php'
        );
    }
}


// =====================================================
// SEARCH / FILTER
// =====================================================

$search = trim($_GET['q'] ?? '');
$departmentFilter = trim($_GET['department'] ?? '');


// =====================================================
// DEPARTMENTS
// =====================================================

$departments = $pdo->query("
    SELECT DISTINCT department
    FROM emergency_contacts
    WHERE department IS NOT NULL
      AND department <> ''
    ORDER BY department
")->fetchAll(PDO::FETCH_COLUMN);


// =====================================================
// STATISTICS
// =====================================================

$stats = [

    'total' => (int)$pdo->query("
        SELECT COUNT(*)
        FROM emergency_contacts
    ")->fetchColumn(),

    'departments' => (int)$pdo->query("
        SELECT COUNT(DISTINCT department)
        FROM emergency_contacts
        WHERE department IS NOT NULL
          AND department <> ''
    ")->fetchColumn(),

    'with_notes' => (int)$pdo->query("
        SELECT COUNT(*)
        FROM emergency_contacts
        WHERE notes IS NOT NULL
          AND notes <> ''
    ")->fetchColumn()

];


// =====================================================
// CONTACT QUERY
// =====================================================

$sql = "
    SELECT *
    FROM emergency_contacts
    WHERE 1=1
";

$params = [];


if ($search !== '') {

    $sql .= "
        AND (
            name LIKE ?
            OR designation LIKE ?
            OR phone LIKE ?
            OR department LIKE ?
            OR notes LIKE ?
        )
    ";

    $term = '%' . $search . '%';

    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}


if ($departmentFilter !== '') {

    $sql .= "
        AND department = ?
    ";

    $params[] = $departmentFilter;
}


$sql .= "
    ORDER BY
        CASE
            WHEN department = 'Emergency Services'
            THEN 1
            WHEN department = 'Campus Security'
            THEN 2
            ELSE 3
        END,
        name ASC
";


$stmt = $pdo->prepare($sql);

$stmt->execute($params);

$rows = $stmt->fetchAll();


// =====================================================
// EDIT CONTACT
// =====================================================

$editId = (int)($_GET['edit'] ?? 0);

$editContact = null;

if ($editId) {

    $stmt = $pdo->prepare("
        SELECT *
        FROM emergency_contacts
        WHERE id = ?
    ");

    $stmt->execute([$editId]);

    $editContact = $stmt->fetch();
}


// =====================================================
// HEADER
// =====================================================

require __DIR__ . '/includes/header.php';

?>


<div class="page-head">

    <div>

        <span class="eyebrow">
            SAFETY & RESPONSE
        </span>

        <h1>
            Emergency Contacts
        </h1>

        <p>
            Keep critical emergency and event-response contacts accessible.
        </p>

    </div>

</div>


<!-- =====================================================
     EMERGENCY ALERT BAR
====================================================== -->

<div class="emergency-alert">

    <div class="emergency-alert-icon">
        !
    </div>

    <div>

        <strong>
            Emergency Response Center
        </strong>

        <p>
            Use the contact directory below for urgent operational and safety coordination.
        </p>

    </div>

</div>


<!-- =====================================================
     STATISTICS
====================================================== -->

<div class="stat-grid emergency-stats">

    <div class="stat-card">

        <span>Total Contacts</span>

        <strong>
            <?= $stats['total'] ?>
        </strong>

        <em>
            Registered emergency contacts
        </em>

    </div>


    <div class="stat-card">

        <span>Departments</span>

        <strong>
            <?= $stats['departments'] ?>
        </strong>

        <em>
            Response departments
        </em>

    </div>


    <div class="stat-card">

        <span>With Notes</span>

        <strong>
            <?= $stats['with_notes'] ?>
        </strong>

        <em>
            Contacts with instructions
        </em>

    </div>


    <div class="stat-card">

        <span>Directory Status</span>

        <strong>
            Ready
        </strong>

        <em>
            Emergency directory available
        </em>

    </div>

</div>


<!-- =====================================================
     MAIN LAYOUT
====================================================== -->

<div class="emergency-layout">


    <!-- =================================================
         CONTACT DIRECTORY
    ================================================== -->

    <section class="panel">

        <div class="panel-head">

            <div>

                <span class="eyebrow">
                    RESPONSE DIRECTORY
                </span>

                <h2>
                    Emergency Contacts
                </h2>

            </div>

            <span class="record-count">

                <?= count($rows) ?>
                contact<?= count($rows) !== 1 ? 's' : '' ?>

            </span>

        </div>


        <!-- FILTER -->

        <form
            method="get"
            class="emergency-filter"
        >

            <input
                type="search"
                name="q"
                value="<?= e($search) ?>"
                placeholder="Search contact, phone or department..."
            >


            <select name="department">

                <option value="">
                    All Departments
                </option>

                <?php foreach ($departments as $department): ?>

                    <option
                        value="<?= e($department) ?>"
                        <?= $departmentFilter === $department
                            ? 'selected'
                            : '' ?>
                    >

                        <?= e($department) ?>

                    </option>

                <?php endforeach; ?>

            </select>


            <button class="btn btn-dark">
                Filter
            </button>


            <?php if (
                $search !== '' ||
                $departmentFilter !== ''
            ): ?>

                <a
                    href="<?= BASE_URL ?>/emergency.php"
                    class="btn btn-light"
                >
                    Reset
                </a>

            <?php endif; ?>

        </form>


        <?php if ($rows): ?>

            <div class="emergency-contact-grid">

                <?php foreach ($rows as $row): ?>

                    <article class="emergency-contact-card">


                        <!-- CARD HEADER -->

                        <div class="emergency-contact-head">

                            <div class="contact-icon">
                                !
                            </div>


                            <div class="contact-main">

                                <h3>
                                    <?= e($row['name']) ?>
                                </h3>

                                <?php if (!empty($row['designation'])): ?>

                                    <p>
                                        <?= e($row['designation']) ?>
                                    </p>

                                <?php endif; ?>

                            </div>


                            <span class="contact-priority">
                                Emergency
                            </span>

                        </div>


                        <!-- DEPARTMENT -->

                        <?php if (!empty($row['department'])): ?>

                            <div class="contact-department">

                                <?= e($row['department']) ?>

                            </div>

                        <?php endif; ?>


                        <!-- PHONE -->

                        <div class="contact-phone">

                            <span>
                                Phone
                            </span>

                            <strong>
                                <?= e($row['phone']) ?>
                            </strong>

                        </div>


                        <!-- NOTES -->

                        <?php if (!empty($row['notes'])): ?>

                            <div class="contact-notes">

                                <?= nl2br(
                                    e($row['notes'])
                                ) ?>

                            </div>

                        <?php endif; ?>


                        <!-- ACTIONS -->

                        <div class="contact-actions">

                            <a
                                href="tel:<?= e($row['phone']) ?>"
                                class="btn btn-primary btn-sm"
                            >
                                Call
                            </a>


                            <a
                                href="<?= BASE_URL ?>/emergency.php?edit=<?= (int)$row['id'] ?>"
                                class="btn btn-light btn-sm"
                            >
                                Edit
                            </a>


                            <form
                                method="post"
                                onsubmit="return confirm('Delete this emergency contact?')"
                            >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="delete"
                                >

                                <input
                                    type="hidden"
                                    name="id"
                                    value="<?= (int)$row['id'] ?>"
                                >

                                <button
                                    type="submit"
                                    class="btn btn-danger btn-sm"
                                >
                                    Delete
                                </button>

                            </form>

                        </div>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="empty">

                <strong>
                    No emergency contacts found
                </strong>

                <p>
                    Add an emergency contact or change your filters.
                </p>

            </div>

        <?php endif; ?>

    </section>


    <!-- =================================================
         ADD / EDIT FORM
    ================================================== -->

    <section class="panel form-panel emergency-form-panel">

        <div class="panel-head">

            <div>

                <span class="eyebrow">
                    CONTACT MANAGEMENT
                </span>

                <h2>
                    <?= $editContact
                        ? 'Edit Contact'
                        : 'Add Contact' ?>
                </h2>

                <p>
                    Maintain accurate emergency response information.
                </p>

            </div>

        </div>


        <form method="post">

            <input
                type="hidden"
                name="action"
                value="<?= $editContact ? 'update' : 'add' ?>"
            >


            <?php if ($editContact): ?>

                <input
                    type="hidden"
                    name="id"
                    value="<?= (int)$editContact['id'] ?>"
                >

            <?php endif; ?>


            <label>

                Name

                <input
                    type="text"
                    name="name"
                    maxlength="160"
                    value="<?= e(
                        $editContact['name'] ?? ''
                    ) ?>"
                    placeholder="Contact / service name"
                    required
                >

            </label>


            <label>

                Designation

                <input
                    type="text"
                    name="designation"
                    maxlength="160"
                    value="<?= e(
                        $editContact['designation'] ?? ''
                    ) ?>"
                    placeholder="Designation"
                >

            </label>


            <label>

                Phone

                <input
                    type="tel"
                    name="phone"
                    maxlength="40"
                    value="<?= e(
                        $editContact['phone'] ?? ''
                    ) ?>"
                    placeholder="Emergency phone number"
                    required
                >

            </label>


            <label>

                Department

                <input
                    type="text"
                    name="department"
                    maxlength="160"
                    value="<?= e(
                        $editContact['department'] ?? ''
                    ) ?>"
                    placeholder="Department / organization"
                >

            </label>


            <label>

                Notes

                <textarea
                    name="notes"
                    rows="5"
                    placeholder="Important instructions or additional information..."
                ><?= e(
                    $editContact['notes'] ?? ''
                ) ?></textarea>

            </label>


            <div class="form-actions">

                <button
                    type="submit"
                    class="btn btn-primary btn-lg"
                >

                    <?= $editContact
                        ? 'Update Contact'
                        : 'Save Contact' ?>

                </button>


                <?php if ($editContact): ?>

                    <a
                        href="<?= BASE_URL ?>/emergency.php"
                        class="btn btn-light"
                    >
                        Cancel
                    </a>

                <?php endif; ?>

            </div>

        </form>

    </section>

</div>


<style>

/* =========================================
   ALERT
========================================= */

.emergency-alert {
    margin-top: 18px;
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 15px 17px;
    border-radius: 14px;
    background: rgba(239,68,68,.07);
    border: 1px solid rgba(239,68,68,.22);
}

.emergency-alert-icon {
    width: 40px;
    height: 40px;
    flex: 0 0 40px;
    display: grid;
    place-items: center;
    border-radius: 12px;
    background: rgba(239,68,68,.14);
    color: var(--red);
    font-size: 19px;
    font-weight: 900;
}

.emergency-alert strong {
    display: block;
}

.emergency-alert p {
    margin: 4px 0 0;
    color: var(--muted);
    font-size: 12px;
}


/* =========================================
   LAYOUT
========================================= */

.emergency-layout {
    display: grid;
    grid-template-columns:
        minmax(0, 1.45fr)
        minmax(320px, .7fr);
    gap: 18px;
    margin-top: 18px;
}

.emergency-form-panel {
    align-self: start;
}


/* =========================================
   FILTER
========================================= */

.emergency-filter {
    display: grid;
    grid-template-columns:
        minmax(180px, 1.5fr)
        minmax(170px, 1fr)
        auto
        auto;
    gap: 8px;
    margin-bottom: 18px;
}

.emergency-filter input,
.emergency-filter select {
    min-width: 0;
}


/* =========================================
   CONTACT GRID
========================================= */

.emergency-contact-grid {
    display: grid;
    grid-template-columns:
        repeat(2, minmax(0, 1fr));
    gap: 13px;
}

.emergency-contact-card {
    padding: 17px;
    border-radius: 14px;
    border: 1px solid rgba(148,163,184,.15);
    background: rgba(148,163,184,.035);
    transition: .18s ease;
}

.emergency-contact-card:hover {
    border-color: rgba(239,68,68,.35);
    transform: translateY(-1px);
}


/* =========================================
   CARD HEADER
========================================= */

.emergency-contact-head {
    display: flex;
    align-items: flex-start;
    gap: 11px;
}

.contact-icon {
    width: 38px;
    height: 38px;
    flex: 0 0 38px;
    display: grid;
    place-items: center;
    border-radius: 11px;
    background: rgba(239,68,68,.12);
    color: var(--red);
    font-weight: 900;
    font-size: 17px;
}

.contact-main {
    min-width: 0;
    flex: 1;
}

.contact-main h3 {
    margin: 0;
    font-size: 15px;
}

.contact-main p {
    margin: 4px 0 0;
    color: var(--muted);
    font-size: 12px;
}

.contact-priority {
    padding: 5px 8px;
    border-radius: 999px;
    background: rgba(239,68,68,.1);
    color: var(--red);
    font-size: 9px;
    font-weight: 800;
    text-transform: uppercase;
}


/* =========================================
   DEPARTMENT
========================================= */

.contact-department {
    margin-top: 13px;
    display: inline-flex;
    padding: 6px 9px;
    border-radius: 8px;
    background: rgba(148,163,184,.07);
    color: var(--muted);
    font-size: 11px;
}


/* =========================================
   PHONE
========================================= */

.contact-phone {
    margin-top: 14px;
    padding: 12px;
    border-radius: 10px;
    background: rgba(148,163,184,.05);
}

.contact-phone span {
    display: block;
    color: var(--muted);
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .06em;
}

.contact-phone strong {
    display: block;
    margin-top: 3px;
    font-size: 16px;
}


/* =========================================
   NOTES
========================================= */

.contact-notes {
    margin-top: 11px;
    color: var(--muted);
    font-size: 12px;
    line-height: 1.55;
}


/* =========================================
   ACTIONS
========================================= */

.contact-actions {
    display: flex;
    align-items: center;
    gap: 7px;
    flex-wrap: wrap;
    margin-top: 15px;
}

.contact-actions form {
    margin: 0;
}


/* =========================================
   FORM
========================================= */

.form-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.record-count {
    color: var(--muted);
    font-size: 12px;
}


/* =========================================
   RESPONSIVE
========================================= */

@media (max-width: 1100px) {

    .emergency-filter {
        grid-template-columns: 1fr 1fr;
    }

    .emergency-filter input {
        grid-column: 1 / -1;
    }

}

@media (max-width: 1000px) {

    .emergency-layout {
        grid-template-columns: 1fr;
    }

}

@media (max-width: 720px) {

    .emergency-alert {
        align-items: flex-start;
    }

    .emergency-filter {
        grid-template-columns: 1fr;
    }

    .emergency-filter input {
        grid-column: auto;
    }

    .emergency-filter .btn,
    .emergency-filter a {
        width: 100%;
    }

    .emergency-contact-grid {
        grid-template-columns: 1fr;
    }

    .contact-actions {
        align-items: stretch;
        flex-direction: column;
    }

    .contact-actions .btn,
    .contact-actions form {
        width: 100%;
    }

    .contact-actions form .btn {
        width: 100%;
    }

}




/* =========================================
   EMERGENCY.PHP — CARD SPACING FIX
========================================= */

/* Emergency alert banner */
.emergency-alert {
    margin: 0 0 28px;
    padding: 22px 26px;
    gap: 18px;
    border-radius: 18px;
    box-sizing: border-box;
}

/* Four summary cards */
.emergency-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 22px;
    margin: 0 0 32px;
}

.emergency-stats > .stat-card {
    min-width: 0;
    margin: 0;
    padding: 28px;
    border-radius: 22px;
    box-sizing: border-box;
}

/* Directory and Add Contact panels */
.emergency-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.55fr) minmax(320px, 0.85fr);
    align-items: start;
    gap: 28px;
    margin: 0 0 32px;
}

.emergency-layout > .panel {
    min-width: 0;
    width: 100%;
    margin: 0;
    border-radius: 22px;
    box-sizing: border-box;
    overflow: hidden;
}

/* Panel heading spacing */
.emergency-layout > .panel > .panel-head {
    padding: 26px 28px;
    margin: 0;
}

/* Search and filter */
.emergency-filter {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(150px, 0.7fr) auto auto;
    gap: 12px;
    align-items: center;
    margin: 0;
    padding: 20px 24px;
}

.emergency-filter input,
.emergency-filter select {
    width: 100%;
    min-width: 0;
    min-height: 54px;
    box-sizing: border-box;
}

.emergency-filter .btn,
.emergency-filter a {
    min-height: 54px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
}

/* Contact card grid */
.emergency-contact-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
    padding: 24px;
}

/* Individual contact cards */
.emergency-contact-card {
    min-width: 0;
    width: 100%;
    margin: 0;
    padding: 22px;
    border-radius: 18px;
    box-sizing: border-box;
    overflow-wrap: anywhere;
}

.emergency-contact-head {
    display: flex;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 12px;
}

.contact-main {
    min-width: 0;
    flex: 1 1 130px;
}

.contact-priority {
    flex: 0 0 auto;
}

.contact-phone {
    margin-top: 18px;
}

.contact-notes {
    margin-top: 14px;
    overflow-wrap: anywhere;
}

.contact-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 20px;
}

.contact-actions form {
    margin: 0;
}

/* Add/Edit contact form */
.emergency-form-panel > form {
    display: flex;
    flex-direction: column;
    gap: 20px;
    padding: 26px 28px 30px;
    box-sizing: border-box;
}

.emergency-form-panel label {
    display: flex;
    flex-direction: column;
    gap: 9px;
    min-width: 0;
}

.emergency-form-panel input,
.emergency-form-panel textarea,
.emergency-form-panel select {
    width: 100%;
    max-width: 100%;
    min-width: 0;
    box-sizing: border-box;
}

.emergency-form-panel textarea {
    min-height: 130px;
    resize: vertical;
}

.form-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 4px;
}

/* Tablet */
@media (max-width: 1100px) {
    .emergency-layout {
        grid-template-columns: minmax(0, 1fr);
        gap: 24px;
    }

    .emergency-contact-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .emergency-filter {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    }
}

/* Mobile */
@media (max-width: 720px) {
    .emergency-alert {
        align-items: flex-start;
        padding: 18px;
        margin-bottom: 22px;
    }

    .emergency-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 24px;
    }

    .emergency-stats > .stat-card {
        padding: 20px;
    }

    .emergency-layout {
        gap: 22px;
    }

    .emergency-layout > .panel {
        border-radius: 18px;
    }

    .emergency-layout > .panel > .panel-head {
        padding: 20px;
    }

    .emergency-filter {
        grid-template-columns: minmax(0, 1fr);
        gap: 12px;
        padding: 18px;
    }

    .emergency-filter .btn,
    .emergency-filter a {
        width: 100%;
    }

    .emergency-contact-grid {
        grid-template-columns: minmax(0, 1fr);
        gap: 16px;
        padding: 18px;
    }

    .emergency-contact-card {
        padding: 18px;
    }

    .emergency-form-panel > form {
        gap: 17px;
        padding: 20px;
    }

    .contact-actions {
        gap: 8px;
    }
}

@media (max-width: 420px) {
    .emergency-stats {
        grid-template-columns: minmax(0, 1fr);
    }

    .emergency-stats > .stat-card {
        padding: 20px;
    }

    .emergency-contact-grid {
        padding: 12px;
    }

    .emergency-contact-card {
        padding: 15px;
    }

    .emergency-form-panel > form {
        padding: 16px;
    }
}

</style>


<?php require __DIR__ . '/includes/footer.php'; ?>