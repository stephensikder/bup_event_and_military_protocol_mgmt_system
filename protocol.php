<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/mongo.php';
require_login();

$page_title = 'Military Protocol | BUP Protocol';

/*
|--------------------------------------------------------------------------
| POST ACTIONS (MongoDB Atlas)
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    if (in_array($action, ['add', 'update', 'delete'], true) && (is_event_director() || is_event_coordinator())) {
        flash('error', 'Protocol requirements are managed strictly by Military Protocol command units and administrators.');
        redirect(BASE_URL . '/protocol.php');
    }

    /*
    |--------------------------------------------------------------------------
    | ADD PROTOCOL ITEM
    |--------------------------------------------------------------------------
    */

    if ($action === 'add') {

        $event_id = (int)($_POST['event_id'] ?? 0);
        $item = trim($_POST['item'] ?? '');
        $instructions = trim($_POST['instructions'] ?? '');

        if ($event_id <= 0 || $item === '') {
            flash('error', 'Event and protocol requirement are required.');
            redirect(BASE_URL . '/protocol.php');
        }

        // Put new item after existing items for this event
        $highestOrderDoc = mcoll('protocol_items')->findOne(
            ['event_id' => $event_id],
            ['sort' => ['sort_order' => -1]]
        );
        $next_order = $highestOrderDoc ? ((int)($highestOrderDoc['sort_order'] ?? 0) + 1) : 1;

        $protocol_id = mongo_next_id('protocol_items');
        mcoll('protocol_items')->insertOne([
            'id' => $protocol_id,
            'event_id' => $event_id,
            'item' => $item,
            'instructions' => $instructions,
            'completed' => 0,
            'sort_order' => $next_order,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        mongo_audit_log(
            'Protocol Item Added',
            'Added protocol item #' . $protocol_id . ' — ' . $item
        );

        flash('success', 'Protocol checklist item added successfully.');
        redirect(BASE_URL . '/protocol.php');
    }

    /*
    |--------------------------------------------------------------------------
    | TOGGLE PROTOCOL ITEM
    |--------------------------------------------------------------------------
    */

    if ($action === 'toggle') {

        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0) {
            $protocolDoc = mcoll('protocol_items')->findOne(['id' => $id]);

            if ($protocolDoc) {
                $protocol = mongo_doc_to_array($protocolDoc);
                $new_status = !empty($protocol['completed']) ? 0 : 1;

                mcoll('protocol_items')->updateOne(
                    ['id' => $id],
                    ['$set' => ['completed' => $new_status]]
                );

                mongo_audit_log(
                    $new_status ? 'Protocol Completed' : 'Protocol Reopened',
                    ($new_status ? 'Completed protocol item #' : 'Reopened protocol item #') . $id . ' — ' . ($protocol['item'] ?? '')
                );
            }
        }

        redirect(BASE_URL . '/protocol.php');
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE PROTOCOL ITEM
    |--------------------------------------------------------------------------
    */

    if ($action === 'update') {

        $id = (int)($_POST['id'] ?? 0);
        $event_id = (int)($_POST['event_id'] ?? 0);
        $item = trim($_POST['item'] ?? '');
        $instructions = trim($_POST['instructions'] ?? '');

        if ($id <= 0 || $event_id <= 0 || $item === '') {
            flash('error', 'Invalid protocol information.');
            redirect(BASE_URL . '/protocol.php');
        }

        mcoll('protocol_items')->updateOne(
            ['id' => $id],
            ['$set' => [
                'event_id' => $event_id,
                'item' => $item,
                'instructions' => $instructions,
                'updated_at' => date('Y-m-d H:i:s')
            ]]
        );

        mongo_audit_log(
            'Protocol Item Updated',
            'Updated protocol item #' . $id . ' — ' . $item
        );

        flash('success', 'Protocol requirement updated successfully.');
        redirect(BASE_URL . '/protocol.php');
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE PROTOCOL ITEM
    |--------------------------------------------------------------------------
    */

    if ($action === 'delete') {

        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0) {
            $protocolDoc = mcoll('protocol_items')->findOne(['id' => $id]);

            if ($protocolDoc) {
                $protocol = mongo_doc_to_array($protocolDoc);
                mcoll('protocol_items')->deleteOne(['id' => $id]);

                mongo_audit_log(
                    'Protocol Item Deleted',
                    'Deleted protocol item #' . $id . ' — ' . ($protocol['item'] ?? '')
                );

                flash('success', 'Protocol requirement deleted successfully.');
            }
        }

        redirect(BASE_URL . '/protocol.php');
    }

    /*
    |--------------------------------------------------------------------------
    | MILITARY-TO-CIVIL EVENT REQUEST SUBMISSION (HIGHER COMMAND L1 / ADMIN)
    |--------------------------------------------------------------------------
    */
    if ($action === 'submit_event_request') {
        $u = current_user();
        $is_higher_cmd = ($u['protocol_level'] ?? '') === 'Higher Command' || has_role('Admin');
        if (!$is_higher_cmd) {
            flash('error', 'Unauthorized: Only Armed Forces Higher Command (L1) may issue military event directives.');
            redirect(BASE_URL . '/protocol.php');
        }

        $title = trim($_POST['title'] ?? '');
        $event_type = trim($_POST['event_type'] ?? 'Official Ceremony');
        $proposed_date = trim($_POST['proposed_date'] ?? date('Y-m-d', strtotime('+14 days')));
        $proposed_venue = trim($_POST['proposed_venue'] ?? 'BUP Central Auditorium');
        $objectives = trim($_POST['objectives'] ?? '');
        $required_protocol = trim($_POST['required_protocol'] ?? '');
        $attached_sops = isset($_POST['attached_sops']) && is_array($_POST['attached_sops'])
            ? implode(', ', array_map('trim', $_POST['attached_sops']))
            : trim($_POST['attached_sops'] ?? '');
        $special_instructions = trim($_POST['special_instructions'] ?? '');
        $priority = in_array($_POST['priority'] ?? '', ['Routine', 'Priority', 'Immediate', 'Flash'], true) ? $_POST['priority'] : 'Routine';

        if ($title !== '' && $objectives !== '') {
            $year = date('Y');
            $yearPrefix = "REQ-{$year}-";
            $countYear = mcoll('military_event_requests')->countDocuments([
                'request_id' => ['$regex' => '^' . preg_quote($yearPrefix, '/')]
            ]);
            $nextNum = str_pad((int)$countYear + 1, 3, '0', STR_PAD_LEFT);
            $req_id = "REQ-{$year}-{$nextNum}";

            $newReqDbId = mongo_next_id('military_event_requests');
            mcoll('military_event_requests')->insertOne([
                'id' => $newReqDbId,
                'request_id' => $req_id,
                'title' => $title,
                'event_type' => $event_type,
                'proposed_date' => $proposed_date,
                'proposed_venue' => $proposed_venue,
                'objectives' => $objectives,
                'required_protocol' => $required_protocol,
                'attached_sops' => $attached_sops,
                'special_instructions' => $special_instructions,
                'priority' => $priority,
                'status' => 'Submitted',
                'requested_by' => $u['id'],
                'created_at' => date('Y-m-d H:i:s')
            ]);

            // Notify Civil Event Directors (L1) and Admins
            $directorsCursor = mcoll('users')->find([
                'status' => 'Active',
                '$or' => [
                    ['level' => 'L1'],
                    ['role' => ['$in' => ['Event Director', 'Admin']]]
                ]
            ]);
            foreach ($directorsCursor as $dirDoc) {
                if (function_exists('add_user_notification')) {
                    add_user_notification(
                        (int)$dirDoc['id'],
                        'Military Event Directive: ' . $req_id,
                        'Higher Command issued event directive "' . $title . '" (Priority: ' . $priority . ').',
                        'announcement',
                        BASE_URL . '/protocol.php'
                    );
                }
            }

            mongo_audit_log(
                $u['id'],
                'Military Event Directive Submitted',
                "Promulgated directive {$req_id} — {$title}"
            );

            flash('success', "Military Event Directive {$req_id} successfully issued to Civil Event Directorate.");
        } else {
            flash('error', 'Title and Operational Objectives are required for military event directives.');
        }
        redirect(BASE_URL . '/protocol.php');
    }

    /*
    |--------------------------------------------------------------------------
    | REVIEW MILITARY EVENT REQUEST (CIVIL EVENT DIRECTOR L1 / ADMIN)
    |--------------------------------------------------------------------------
    */
    if ($action === 'review_event_request') {
        if (!is_event_director() && !has_role('Admin')) {
            flash('error', 'Only Civil Event Director (L1) or Administrator can review military event requests.');
            redirect(BASE_URL . '/protocol.php');
        }

        $req_db_id = (int)($_POST['request_id'] ?? 0);
        $decision = $_POST['decision'] ?? '';

        $reqDoc = mcoll('military_event_requests')->findOne(['id' => $req_db_id]);

        if ($reqDoc) {
            $req = mongo_doc_to_array($reqDoc);
            $now = date('Y-m-d H:i:s');

            if ($decision === 'accept') {
                mcoll('military_event_requests')->updateOne(
                    ['id' => $req_db_id],
                    ['$set' => ['status' => 'Approved', 'updated_at' => $now]]
                );
                if (function_exists('add_user_notification')) {
                    add_user_notification(
                        (int)$req['requested_by'],
                        'Military Directive Accepted: ' . ($req['request_id'] ?? ''),
                        'Civil Event Director approved directive "' . ($req['title'] ?? '') . '". Event creation authorized.',
                        'system',
                        BASE_URL . '/protocol.php'
                    );
                }
                flash('success', "Military Directive {$req['request_id']} has been Accepted. You may now create the event.");
            } elseif ($decision === 'clarify') {
                $msg = trim($_POST['clarification_message'] ?? '');
                mcoll('military_event_requests')->updateOne(
                    ['id' => $req_db_id],
                    ['$set' => ['status' => 'Clarification Requested', 'clarification_message' => $msg, 'updated_at' => $now]]
                );
                if (function_exists('add_user_notification')) {
                    add_user_notification(
                        (int)$req['requested_by'],
                        'Clarification Requested: ' . ($req['request_id'] ?? ''),
                        'Civil Event Director requested clarification: "' . $msg . '"',
                        'system',
                        BASE_URL . '/protocol.php'
                    );
                }
                flash('success', "Clarification request dispatched to Military Higher Command.");
            } elseif ($decision === 'reject') {
                $reason = trim($_POST['rejection_reason'] ?? '');
                mcoll('military_event_requests')->updateOne(
                    ['id' => $req_db_id],
                    ['$set' => ['status' => 'Rejected', 'rejection_reason' => $reason, 'updated_at' => $now]]
                );
                if (function_exists('add_user_notification')) {
                    add_user_notification(
                        (int)$req['requested_by'],
                        'Military Directive Rejected: ' . ($req['request_id'] ?? ''),
                        'Civil Event Director was unable to proceed. Reason: "' . $reason . '"',
                        'system',
                        BASE_URL . '/protocol.php'
                    );
                }
                flash('success', "Military Directive {$req['request_id']} marked as Rejected.");
            }
        }
        redirect(BASE_URL . '/protocol.php');
    }

    /*
    |--------------------------------------------------------------------------
    | ECHELON DUTY DELEGATION (L2–L7 TO SUBORDINATES)
    |--------------------------------------------------------------------------
    */
    if ($action === 'assign_echelon_duty') {
        $event_id = (int)($_POST['event_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $target_user_id = (int)($_POST['target_user_id'] ?? 0);
        $subordinate_level = trim($_POST['subordinate_level'] ?? 'L5');
        $location = trim($_POST['location'] ?? 'Parade Ground / VIP Dais');
        $time_slot = trim($_POST['time_slot'] ?? 'Ceremony Duration');
        $priority = trim($_POST['priority'] ?? 'High');
        $unit_id = (int)($_POST['unit_id'] ?? 0);

        if ($title !== '' && $target_user_id > 0) {
            $dutyId = mongo_next_id('duties');
            $now = date('Y-m-d H:i:s');
            mcoll('duties')->insertOne([
                'id' => $dutyId,
                'event_id' => $event_id,
                'title' => $title,
                'department' => 'Protocol',
                'level' => $subordinate_level,
                'location' => $location,
                'time_slot' => $time_slot,
                'priority' => $priority,
                'assigned_to' => $target_user_id,
                'assigned_by' => current_user()['id'],
                'status' => 'Assigned',
                'created_at' => $now
            ]);

            if (function_exists('notify_duty_assignment')) {
                notify_duty_assignment($dutyId, $title, 'Protocol', $subordinate_level, $target_user_id, (int)current_user()['id']);
            }

            mongo_audit_log(
                current_user()['id'],
                'Military Echelon Duty Delegated',
                "Assigned tactical duty '{$title}' to subordinate personnel #{$target_user_id}"
            );

            flash('success', "Operational directive '{$title}' successfully delegated to subordinate echelon.");
        } else {
            flash('error', 'Directive title and subordinate recipient are mandatory.');
        }
        redirect(BASE_URL . '/protocol.php' . ($unit_id > 0 ? '?unit_id=' . $unit_id . '#command-detail' : ''));
    }

    /*
    |--------------------------------------------------------------------------
    | SOLDIER DUTY ACTION (ACKNOWLEDGE / IN PROGRESS / COMPLETED)
    |--------------------------------------------------------------------------
    */
    if ($action === 'soldier_update_duty') {
        $duty_id = (int)($_POST['duty_id'] ?? 0);
        $new_status = in_array($_POST['status'] ?? '', ['Accepted', 'In Progress', 'Completed'], true) ? $_POST['status'] : 'In Progress';
        $unit_id = (int)($_POST['unit_id'] ?? 0);

        if ($duty_id > 0) {
            mcoll('duties')->updateOne(
                ['id' => $duty_id],
                ['$set' => ['status' => $new_status, 'updated_at' => date('Y-m-d H:i:s')]]
            );

            if (function_exists('notify_duty_status_change')) {
                notify_duty_status_change($duty_id, $new_status, (int)current_user()['id']);
            }

            mongo_audit_log(
                current_user()['id'],
                'Soldier Post Duty Updated',
                "Soldier set duty #{$duty_id} status to {$new_status}"
            );

            flash('success', "Soldier post duty marked as: {$new_status}");
        }
        redirect(BASE_URL . '/protocol.php' . ($unit_id > 0 ? '?unit_id=' . $unit_id . '#command-detail' : ''));
    }
}


/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

$q = trim($_GET['q'] ?? '');
$event_filter = (int)($_GET['event_id'] ?? 0);
$status_filter = $_GET['status'] ?? '';


/*
|--------------------------------------------------------------------------
| EVENTS (MongoDB Atlas)
|--------------------------------------------------------------------------
*/

$eventsCursor = mcoll('events')->find([], ['sort' => ['event_date' => -1, 'event_time' => -1]]);
$events = [];
foreach ($eventsCursor as $eDoc) {
    $events[] = mongo_doc_to_array($eDoc);
}

/* Fetch Military Event Requests & Statistics (MongoDB Atlas) */
$reqPipeline = [
    ['$lookup' => [
        'from' => 'users',
        'localField' => 'requested_by',
        'foreignField' => 'id',
        'as' => 'requester_docs'
    ]],
    ['$addFields' => [
        'requester_name' => ['$ifNull' => [['$arrayElemAt' => ['$requester_docs.name', 0]], '']],
        'requester_designation' => ['$ifNull' => [['$arrayElemAt' => ['$requester_docs.designation', 0]], '']]
    ]],
    ['$project' => ['requester_docs' => 0]],
    ['$sort' => ['id' => -1]]
];
$reqCursor = mcoll('military_event_requests')->aggregate($reqPipeline);
$event_requests = [];
foreach ($reqCursor as $rDoc) {
    $event_requests[] = mongo_doc_to_array($rDoc);
}

$req_stats = [
    'total' => count($event_requests),
    'submitted' => 0,
    'clarification' => 0,
    'approved' => 0,
    'converted' => 0,
    'rejected' => 0
];
foreach ($event_requests as $er) {
    if (($er['status'] ?? '') === 'Submitted' || ($er['status'] ?? '') === 'Under Review') $req_stats['submitted']++;
    elseif (($er['status'] ?? '') === 'Clarification Requested') $req_stats['clarification']++;
    elseif (($er['status'] ?? '') === 'Approved') $req_stats['approved']++;
    elseif (($er['status'] ?? '') === 'Converted to Event') $req_stats['converted']++;
    elseif (($er['status'] ?? '') === 'Rejected' || ($er['status'] ?? '') === 'Declined') $req_stats['rejected']++;
}

$promulgatedSopsCursor = mcoll('military_sops')->find(
    ['status' => ['$in' => ['Active', 'Approved']]],
    ['sort' => ['code' => 1]]
);
$promulgated_sops = [];
foreach ($promulgatedSopsCursor as $sopDoc) {
    $promulgated_sops[] = mongo_doc_to_array($sopDoc);
}


/*
|--------------------------------------------------------------------------
| EDIT ITEM
|--------------------------------------------------------------------------
*/

$edit_id = (int)($_GET['edit'] ?? 0);
$edit_item = null;

if ($edit_id > 0) {
    $itemDoc = mcoll('protocol_items')->findOne(['id' => $edit_id]);
    if ($itemDoc) {
        $edit_item = mongo_doc_to_array($itemDoc);
    } else {
        flash('error', 'Protocol requirement not found.');
        redirect(BASE_URL . '/protocol.php');
    }
}


/*
|--------------------------------------------------------------------------
| PROTOCOL QUERY (MongoDB Atlas Aggregation Pipeline)
|--------------------------------------------------------------------------
*/

$pipeline = [];
$pipeline[] = [
    '$lookup' => [
        'from' => 'events',
        'localField' => 'event_id',
        'foreignField' => 'id',
        'as' => 'event_docs'
    ]
];
$pipeline[] = [
    '$addFields' => [
        'event_name' => ['$ifNull' => [['$arrayElemAt' => ['$event_docs.name', 0]], '']],
        'event_date' => ['$ifNull' => [['$arrayElemAt' => ['$event_docs.event_date', 0]], '']],
        'event_time' => ['$ifNull' => [['$arrayElemAt' => ['$event_docs.event_time', 0]], '']]
    ]
];
$pipeline[] = [
    '$project' => ['event_docs' => 0]
];

$match = [];
if ($q !== '') {
    $regex = new MongoDB\BSON\Regex($q, 'i');
    $match['$or'] = [
        ['item' => $regex],
        ['instructions' => $regex],
        ['event_name' => $regex]
    ];
}
if ($event_filter > 0) {
    $match['event_id'] = $event_filter;
}
if ($status_filter === 'Completed') {
    $match['completed'] = 1;
} elseif ($status_filter === 'Pending') {
    $match['completed'] = 0;
}
if (!empty($match)) {
    $pipeline[] = ['$match' => $match];
}

$pipeline[] = [
    '$sort' => [
        'event_date' => -1,
        'event_time' => -1,
        'sort_order' => 1,
        'id' => 1
    ]
];

$itemsCursor = mcoll('protocol_items')->aggregate($pipeline);
$items = [];
foreach ($itemsCursor as $pDoc) {
    $items[] = mongo_doc_to_array($pDoc);
}


/*
|--------------------------------------------------------------------------
| SUMMARY
|--------------------------------------------------------------------------
*/

$total_items = count($items);
$completed_count = 0;
$pending_count = 0;

foreach ($items as $item) {
    if ((int)($item['completed'] ?? 0) === 1) {
        $completed_count++;
    } else {
        $pending_count++;
    }
}

$completion_percentage = $total_items > 0
    ? round(($completed_count / $total_items) * 100)
    : 0;

/*
|--------------------------------------------------------------------------
| MILITARY PROTOCOL HIERARCHY LOGIC & PERMISSION BOUNDARIES (MongoDB Atlas)
|--------------------------------------------------------------------------
*/

$protocol_ranks = [
    'Higher Command' => 1,
    'Division' => 2,
    'Brigade' => 3,
    'Battalion' => 4,
    'Company' => 5,
    'Platoon' => 6,
    'Section' => 7,
    'Individual Soldier' => 8,
];

$user_role = current_role();
$user_proto_level = current_user()['protocol_level'] ?? null;
$is_director_or_admin = is_event_director() || has_role('Admin') || is_event_coordinator();
$user_rank = $user_proto_level ? ($protocol_ranks[$user_proto_level] ?? 8) : ($is_director_or_admin ? 0 : 8);

// Fetch all units in protocol hierarchy
$unitPipeline = [
    ['$lookup' => [
        'from' => 'users',
        'localField' => 'commander_id',
        'foreignField' => 'id',
        'as' => 'commander_docs'
    ]],
    ['$lookup' => [
        'from' => 'military_protocol_units',
        'localField' => 'parent_id',
        'foreignField' => 'id',
        'as' => 'parent_docs'
    ]],
    ['$addFields' => [
        'unit_name' => '$name',
        'unit_code' => '$code',
        'protocol_level' => '$level',
        'parent_unit_id' => '$parent_id',
        'commander_user_id' => '$commander_id',
        'location' => '$hq_location',
        'strength' => 12,
        'commander_name' => ['$ifNull' => [['$arrayElemAt' => ['$commander_docs.name', 0]], null]],
        'commander_email' => ['$ifNull' => [['$arrayElemAt' => ['$commander_docs.email', 0]], null]],
        'commander_phone' => ['$ifNull' => [['$arrayElemAt' => ['$commander_docs.phone', 0]], null]],
        'commander_designation' => ['$ifNull' => [['$arrayElemAt' => ['$commander_docs.designation', 0]], null]],
        'parent_unit_name' => ['$ifNull' => [['$arrayElemAt' => ['$parent_docs.name', 0]], null]],
        'parent_unit_level' => ['$ifNull' => [['$arrayElemAt' => ['$parent_docs.level', 0]], null]]
    ]],
    ['$project' => ['commander_docs' => 0, 'parent_docs' => 0]],
    ['$sort' => ['sort_order' => 1, 'id' => 1]]
];

$unitsCursor = mcoll('military_protocol_units')->aggregate($unitPipeline);
$all_protocol_units = [];
foreach ($unitsCursor as $uDoc) {
    $all_protocol_units[] = mongo_doc_to_array($uDoc);
}

// Fetch official military SOPs and rulebook instructions
$militarySopsCursor = mcoll('military_sops')->find(
    ['status' => 'Active'],
    ['sort' => ['id' => 1]]
);
$militarySops = [];
foreach ($militarySopsCursor as $sopDoc) {
    $militarySops[] = mongo_doc_to_array($sopDoc);
}

// Unit inspection selection
$inspect_id = (int)($_GET['unit_id'] ?? 0);
if ($inspect_id === 0 && !empty($all_protocol_units)) {
    if ($user_proto_level) {
        foreach ($all_protocol_units as $pu) {
            if (($pu['protocol_level'] ?? '') === $user_proto_level || ($pu['level'] ?? '') === $user_proto_level) {
                $inspect_id = (int)$pu['id'];
                break;
            }
        }
    }
    if ($inspect_id === 0) {
        $inspect_id = (int)$all_protocol_units[0]['id'];
    }
}

$inspected_unit = null;
$inspect_authorized = true;
$child_units = [];
$unit_duties = [];

if ($inspect_id > 0) {
    foreach ($all_protocol_units as $pu) {
        if ((int)$pu['id'] === $inspect_id) {
            $inspected_unit = $pu;
            break;
        }
    }

    if ($inspected_unit) {
        $uLevel = $inspected_unit['protocol_level'] ?? $inspected_unit['level'] ?? 'Individual Soldier';
        $unit_rank = $protocol_ranks[$uLevel] ?? 8;

        // Permission boundary: Division L2 operational workspace is restricted from Higher Command (L1) direct manipulation
        if ($user_proto_level === 'Higher Command' && ($uLevel === 'Division' || (int)$inspected_unit['id'] === 2)) {
            $inspect_authorized = false;
            $division_l1_restricted = true;
        } elseif (!$is_director_or_admin && $user_rank > $unit_rank) {
            $inspect_authorized = false;
        } else {
            $inspect_authorized = true;
            // Fetch children
            $childPipeline = [
                ['$match' => ['parent_id' => $inspect_id]],
                ['$lookup' => [
                    'from' => 'users',
                    'localField' => 'commander_id',
                    'foreignField' => 'id',
                    'as' => 'commander_docs'
                ]],
                ['$addFields' => [
                    'unit_name' => '$name',
                    'unit_code' => '$code',
                    'protocol_level' => '$level',
                    'parent_unit_id' => '$parent_id',
                    'commander_user_id' => '$commander_id',
                    'location' => '$hq_location',
                    'commander_name' => ['$ifNull' => [['$arrayElemAt' => ['$commander_docs.name', 0]], null]],
                    'commander_designation' => ['$ifNull' => [['$arrayElemAt' => ['$commander_docs.designation', 0]], null]]
                ]],
                ['$project' => ['commander_docs' => 0]],
                ['$sort' => ['sort_order' => 1, 'id' => 1]]
            ];
            $childCursor = mcoll('military_protocol_units')->aggregate($childPipeline);
            $child_units = [];
            foreach ($childCursor as $cDoc) {
                $child_units[] = mongo_doc_to_array($cDoc);
            }

            // Fetch duties assigned to commander or protocol unit
            $cmd_id = (int)($inspected_unit['commander_id'] ?? $inspected_unit['commander_user_id'] ?? 0);
            if ($cmd_id > 0) {
                $dutyPipeline = [
                    ['$match' => [
                        '$or' => [
                            ['assigned_to' => $cmd_id],
                            ['department' => 'Protocol']
                        ]
                    ]],
                    ['$lookup' => [
                        'from' => 'events',
                        'localField' => 'event_id',
                        'foreignField' => 'id',
                        'as' => 'event_docs'
                    ]],
                    ['$addFields' => [
                        'event_name' => ['$ifNull' => [['$arrayElemAt' => ['$event_docs.name', 0]], '']]
                    ]],
                    ['$project' => ['event_docs' => 0]],
                    ['$sort' => ['duty_date' => -1]],
                    ['$limit' => 6]
                ];
                $dutyCursor = mcoll('duties')->aggregate($dutyPipeline);
                $unit_duties = [];
                foreach ($dutyCursor as $dDoc) {
                    $unit_duties[] = mongo_doc_to_array($dDoc);
                }
            }
        }
    }
}


require __DIR__ . '/includes/header.php';
?>


<div class="page-head">
    <div>
        <span class="eyebrow">MILITARY PROTOCOL &amp; COMMAND CONTROL</span>
        <h1>Military Protocol Hierarchy &amp; Control</h1>
        <p>Interactive 8-level command hierarchy connected with official accounts, duties and protocol checkpoints.</p>
    </div>

    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <!-- MILITARY SOP / RULEBOOK DROPDOWN (MILITARY PROTOCOL ONLY) -->
        <div style="position:relative; display:inline-block;">
            <button type="button" class="btn btn-secondary" id="sopDropdownBtn" onclick="toggleSopDropdown()" style="background:#1e293b; color:#f5d98d; border:1px solid #d4ad5b; font-weight:700; font-size:12px; display:flex; align-items:center; gap:6px;">
                <span>📖 Military SOP &amp; Rulebook</span>
                <span>▾</span>
            </button>
            <div id="sopDropdownMenu" style="display:none; position:absolute; right:0; top:110%; background:#ffffff; color:#0f172a; min-width:320px; border-radius:10px; box-shadow:0 10px 25px rgba(0,0,0,0.25); border:1px solid #cbd5e1; z-index:1000; padding:8px 0;">
                <div style="padding:10px 14px; border-bottom:1px solid #f1f5f9; font-size:11px; font-weight:800; letter-spacing:1px; color:#64748b; text-transform:uppercase;">
                    Official Armed Forces SOPs (<?= count($militarySops) ?>)
                </div>
                <?php foreach ($militarySops as $sop): ?>
                    <button type="button" onclick="openSopModal(<?= (int)$sop['id'] ?>, '<?= e(addslashes($sop['code'])) ?>', '<?= e(addslashes($sop['title'])) ?>', '<?= e(addslashes($sop['applicable_level'])) ?>', '<?= e(addslashes($sop['content'])) ?>', '<?= e(addslashes($sop['authorized_by'])) ?>')" style="width:100%; text-align:left; background:none; border:none; padding:10px 14px; cursor:pointer; font-size:12px; color:#1e293b; border-bottom:1px solid #f8fafc; transition:background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='none'">
                        <strong style="color:#0f172a; display:block; font-size:12px;"><?= e($sop['code']) ?> &bull; <?= e($sop['title']) ?></strong>
                        <small style="color:#64748b; font-size:10px;">Tier: <?= e($sop['applicable_level']) ?> &bull; <?= e($sop['category']) ?></small>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <span style="font-size:12px; background:#082d21; color:#f5d98d; padding:6px 12px; border-radius:8px; font-weight:700;">
            Clearance: <?= e($user_proto_level ? $user_proto_level : current_user()['role']) ?> (<?= e(current_level()) ?>)
        </span>
        <?php if (!is_event_director() && !is_event_coordinator()): ?>
            <a class="btn btn-primary" href="#protocol-form">+ Add Requirement</a>
        <?php endif; ?>
    </div>
</div>

<!-- SOP VIEWER MODAL -->
<div id="sopModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:99999; align-items:center; justify-content:center; padding:20px;">
    <div style="background:#ffffff; color:#0f172a; border-radius:12px; max-width:620px; width:100%; padding:24px; box-shadow:0 20px 40px rgba(0,0,0,0.3); max-height:85vh; overflow-y:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:12px; margin-bottom:14px;">
            <div>
                <span id="sopModalCode" style="background:#082d21; color:#f5d98d; font-size:11px; font-weight:800; padding:2px 8px; border-radius:4px; font-family:monospace;"></span>
                <h3 id="sopModalTitle" style="margin:6px 0 0; font-size:16px; color:#0f172a;"></h3>
            </div>
            <button type="button" onclick="document.getElementById('sopModal').style.display='none'" style="border:none; background:transparent; font-size:22px; cursor:pointer; color:#64748b;">&times;</button>
        </div>
        <div style="margin-bottom:14px; font-size:12px; color:#64748b;">
            Applicable Level: <strong id="sopModalLevel" style="color:#0f172a;"></strong> &bull; Authorized by: <strong id="sopModalAuth" style="color:#0284c7;"></strong>
        </div>
        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin-bottom:16px; font-size:13px; line-height:1.6; color:#334155;" id="sopModalContent"></div>
        <div style="text-align:right;">
            <button type="button" class="btn btn-dark" onclick="document.getElementById('sopModal').style.display='none'">Close SOP</button>
        </div>
    </div>
</div>

<script>
function toggleSopDropdown() {
    const m = document.getElementById('sopDropdownMenu');
    m.style.display = (m.style.display === 'block') ? 'none' : 'block';
}
document.addEventListener('click', function(e) {
    const btn = document.getElementById('sopDropdownBtn');
    const menu = document.getElementById('sopDropdownMenu');
    if (btn && menu && !btn.contains(e.target) && !menu.contains(e.target)) {
        menu.style.display = 'none';
    }
});
function openSopModal(id, code, title, level, content, auth) {
    document.getElementById('sopModalCode').textContent = code;
    document.getElementById('sopModalTitle').textContent = title;
    document.getElementById('sopModalLevel').textContent = level;
    document.getElementById('sopModalContent').textContent = content;
    document.getElementById('sopModalAuth').textContent = auth;
    document.getElementById('sopModal').style.display = 'flex';
    document.getElementById('sopDropdownMenu').style.display = 'none';
}
</script>

<!-- =====================================================
     MILITARY DIRECTIVES & EVENT REQUESTS (TOP SECTION)
====================================================== -->
<?php 
$show_joint_requests = is_event_director() || has_role('Admin') || in_array($user_proto_level, ['Higher Command', 'Division'], true);
?>
<?php if ($show_joint_requests): ?>
<section class="panel" style="margin-bottom:28px; padding:24px 28px; background:#ffffff; border-radius:18px; border:2px solid #082d21; box-shadow:0 10px 30px rgba(6,40,30,0.08);">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px; margin-bottom:20px; border-bottom:1px solid #e2e8f0; padding-bottom:16px;">
        <div>
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                <span style="background:#082d21; color:#f5d98d; font-size:10px; font-weight:900; padding:3px 7px; border-radius:4px; font-family:monospace; letter-spacing:1px;">JOINT DEFENSE &amp; CIVIL INTEGRATION</span>
                <span style="color:#d4af37; font-size:12px; font-weight:800; letter-spacing:1px; text-transform:uppercase;">Military-to-Civil Event Requests</span>
            </div>
            <h2 style="margin:2px 0 4px; font-size:22px; color:#082d21;">Military Directives &amp; Event Requests</h2>
            <p style="margin:0; color:#5e776a; font-size:13px; max-width:820px; line-height:1.5;">
                Standing directives and official event requests promulgated by Armed Forces Higher Command (L1). The Civil Event Directorate (L1 Event Director) evaluates operational viability, requests tactical clarification, approves directives, and seamlessly converts them into live university events.
            </p>
        </div>
        <div>
            <?php if ($user_proto_level === 'Higher Command' || has_role('Admin')): ?>
                <button type="button" onclick="document.getElementById('submitReqModal').style.display='flex'" class="btn btn-primary" style="background:linear-gradient(135deg, #082d21 0%, #13533e 100%); border:1px solid #d4af37; color:#fff; font-weight:800; display:flex; align-items:center; gap:6px;">
                    <span>🎖️ + Issue Military Event Directive</span>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- METRIC SUMMARY COUNTERS -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:14px; margin-bottom:24px;">
        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px 18px;">
            <span style="display:block; font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase;">Total Directives</span>
            <strong style="display:block; font-size:24px; color:#082d21; margin-top:2px;"><?= $req_stats['total'] ?></strong>
            <small style="color:#0284c7; font-size:11px; font-weight:600;">Promulgated</small>
        </div>
        <div style="background:#fefbf2; border:1px solid #fde68a; border-radius:12px; padding:14px 18px;">
            <span style="display:block; font-size:11px; font-weight:700; color:#92400e; text-transform:uppercase;">Pending Review</span>
            <strong style="display:block; font-size:24px; color:#b45309; margin-top:2px;"><?= $req_stats['submitted'] ?></strong>
            <small style="color:#b45309; font-size:11px; font-weight:600;">Awaiting Civil Evaluation</small>
        </div>
        <div style="background:#fff7ed; border:1px solid #fed7aa; border-radius:12px; padding:14px 18px;">
            <span style="display:block; font-size:11px; font-weight:700; color:#9a3412; text-transform:uppercase;">Clarification Active</span>
            <strong style="display:block; font-size:24px; color:#ea580c; margin-top:2px;"><?= $req_stats['clarification'] ?></strong>
            <small style="color:#ea580c; font-size:11px; font-weight:600;">Feedback Requested</small>
        </div>
        <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:12px; padding:14px 18px;">
            <span style="display:block; font-size:11px; font-weight:700; color:#166534; text-transform:uppercase;">Authorized / Converted</span>
            <strong style="display:block; font-size:24px; color:#15803d; margin-top:2px;"><?= $req_stats['approved'] + $req_stats['converted'] ?></strong>
            <small style="color:#16a34a; font-size:11px; font-weight:600;">Ready or Live Event</small>
        </div>
    </div>

    <!-- DIRECTIVES CARDS -->
    <div style="display:flex; flex-direction:column; gap:16px;">
        <?php if (empty($event_requests)): ?>
            <div style="padding:28px; text-align:center; color:#64748b; background:#f8fafc; border-radius:12px;">
                No military event directives currently in register.
            </div>
        <?php else: ?>
            <?php foreach ($event_requests as $req): ?>
                <?php
                $pBadgeBg = '#3b82f6';
                if ($req['priority'] === 'Flash') $pBadgeBg = '#dc2626';
                elseif ($req['priority'] === 'Immediate') $pBadgeBg = '#e11d48';
                elseif ($req['priority'] === 'Priority') $pBadgeBg = '#f59e0b';

                $stBadgeBg = '#64748b';
                if ($req['status'] === 'Submitted') $stBadgeBg = '#b45309';
                elseif ($req['status'] === 'Clarification Requested') $stBadgeBg = '#ea580c';
                elseif ($req['status'] === 'Approved') $stBadgeBg = '#16a34a';
                elseif ($req['status'] === 'Converted to Event') $stBadgeBg = '#0284c7';
                elseif ($req['status'] === 'Rejected' || $req['status'] === 'Declined') $stBadgeBg = '#dc2626';
                ?>
                <div style="border:1px solid #e2e8f0; border-radius:14px; padding:18px 22px; background:#fafafa; transition:all 0.2s;" onmouseover="this.style.background='#ffffff'; this.style.borderColor='#cbd5e1';" onmouseout="this.style.background='#fafafa'; this.style.borderColor='#e2e8f0';">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px; margin-bottom:10px;">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span style="font-family:monospace; font-weight:900; font-size:12px; background:#082d21; color:#f5d98d; padding:4px 8px; border-radius:6px;">
                                <?= e($req['request_id']) ?>
                            </span>
                            <span style="background:<?= $pBadgeBg ?>; color:#fff; font-size:10px; font-weight:800; padding:3px 7px; border-radius:4px; text-transform:uppercase;">
                                <?= e($req['priority']) ?>
                            </span>
                            <span style="background:<?= $stBadgeBg ?>; color:#fff; font-size:10px; font-weight:800; padding:3px 7px; border-radius:4px;">
                                <?= e($req['status']) ?>
                            </span>
                        </div>
                        <div style="font-size:11px; color:#64748b;">
                            Issued by: <strong style="color:#082d21;"><?= e($req['requester_name'] ?? 'Higher Command') ?></strong> &bull; <?= date('d M Y, H:i', strtotime($req['created_at'])) ?>
                        </div>
                    </div>

                    <h3 style="margin:0 0 6px; font-size:17px; color:#0f172a;"><?= e($req['title']) ?></h3>
                    
                    <div style="display:flex; gap:16px; flex-wrap:wrap; font-size:12px; color:#475569; margin-bottom:10px;">
                        <div>🏷️ <strong>Type:</strong> <?= e($req['event_type']) ?></div>
                        <div>📅 <strong>Proposed Date:</strong> <?= date('d M Y', strtotime($req['proposed_date'])) ?></div>
                        <div>📍 <strong>Venue:</strong> <?= e($req['proposed_venue']) ?></div>
                    </div>

                    <div style="background:#ffffff; border:1px solid #e2e8f0; border-left:3px solid #082d21; border-radius:0 8px 8px 0; padding:10px 14px; font-size:12px; color:#334155; margin-bottom:12px; line-height:1.5;">
                        <strong>Operational Objectives:</strong> <?= e($req['objectives']) ?>
                    </div>

                    <?php if (!empty($req['attached_sops'])): ?>
                        <div style="margin-bottom:12px; font-size:11px; color:#0369a1; font-weight:700;">
                            📖 Attached SOP Doctrines: <span style="font-family:monospace; background:#f0f9ff; padding:2px 6px; border-radius:4px; border:1px solid #bae6fd;"><?= e($req['attached_sops']) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($req['status'] === 'Clarification Requested' && !empty($req['clarification_message'])): ?>
                        <div style="background:#fff7ed; border:1px solid #fed7aa; border-radius:8px; padding:10px 14px; font-size:12px; color:#9a3412; margin-bottom:12px;">
                            💬 <strong>Clarification Requested by Event Director:</strong> <?= e($req['clarification_message']) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (($req['status'] === 'Rejected' || $req['status'] === 'Declined') && !empty($req['rejection_reason'])): ?>
                        <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:10px 14px; font-size:12px; color:#991b1b; margin-bottom:12px;">
                            ❌ <strong>Director Review Remarks:</strong> <?= e($req['rejection_reason']) ?>
                        </div>
                    <?php endif; ?>

                    <!-- ACTIONS ROW -->
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; border-top:1px solid #f1f5f9; padding-top:12px;">
                        <button type="button" class="btn btn-light btn-sm" onclick="openReqDetailsModal(<?= htmlspecialchars(json_encode($req), ENT_QUOTES, 'UTF-8') ?>)" style="font-size:11px;">
                            👁️ View Full Directive
                        </button>

                        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                            <?php 
                            $can_review_event_director = is_event_director() || has_role('Admin');
                            ?>
                            <?php if ($can_review_event_director): ?>
                                <?php if ($req['status'] === 'Submitted' || $req['status'] === 'Clarification Requested'): ?>
                                    <form method="post" style="display:inline; margin:0;">
                                        <input type="hidden" name="action" value="review_event_request">
                                        <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                        <input type="hidden" name="decision" value="accept">
                                        <button type="submit" class="btn btn-sm" style="background:#16a34a; color:#fff; border:none; font-weight:700; font-size:11px; padding:5px 12px; border-radius:6px; cursor:pointer;">
                                            ✓ Accept Directive
                                        </button>
                                    </form>

                                    <button type="button" onclick="openClarifyModal(<?= $req['id'] ?>, '<?= e(addslashes($req['request_id'])) ?>')" class="btn btn-sm" style="background:#ea580c; color:#fff; border:none; font-weight:700; font-size:11px; padding:5px 12px; border-radius:6px; cursor:pointer;">
                                        💬 Clarification
                                    </button>

                                    <button type="button" onclick="openRejectModal(<?= $req['id'] ?>, '<?= e(addslashes($req['request_id'])) ?>')" class="btn btn-sm" style="background:#dc2626; color:#fff; border:none; font-weight:700; font-size:11px; padding:5px 12px; border-radius:6px; cursor:pointer;">
                                        ✕ Decline
                                    </button>
                                <?php elseif ($req['status'] === 'Approved'): ?>
                                    <a href="<?= BASE_URL ?>/events.php?from_request=<?= urlencode($req['request_id']) ?>" class="btn btn-sm" style="background:linear-gradient(135deg, #082d21 0%, #13533e 100%); color:#f5d98d; border:1px solid #d4af37; font-weight:800; font-size:12px; padding:6px 14px; text-decoration:none; border-radius:6px; box-shadow:0 2px 8px rgba(8,45,33,0.2);">
                                        📅 Create Event from Request &rarr;
                                    </a>
                                <?php elseif ($req['status'] === 'Converted to Event' && !empty($req['converted_event_id'])): ?>
                                    <a href="<?= BASE_URL ?>/event_view.php?id=<?= (int)$req['converted_event_id'] ?>" class="btn btn-sm" style="background:#16a34a; color:#fff; font-weight:700; font-size:11px; padding:5px 12px; text-decoration:none; border-radius:6px;">
                                        ✓ View Converted Event #<?= (int)$req['converted_event_id'] ?> &rarr;
                                    </a>
                                <?php endif; ?>
                            <?php else: ?>
                                <!-- Military Higher Command & Division: View Status Only -->
                                <?php if ($req['status'] === 'Approved'): ?>
                                    <span class="badge" style="background:#16a34a; color:#fff; font-size:11px; font-weight:800; padding:5px 12px; border-radius:6px;">
                                        ✓ Approved by Civil Event Director
                                    </span>
                                <?php elseif ($req['status'] === 'Rejected' || $req['status'] === 'Declined'): ?>
                                    <span class="badge" style="background:#dc2626; color:#fff; font-size:11px; font-weight:800; padding:5px 12px; border-radius:6px;">
                                        ✕ Declined by Civil Event Director
                                    </span>
                                <?php elseif ($req['status'] === 'Clarification Requested'): ?>
                                    <span class="badge" style="background:#ea580c; color:#fff; font-size:11px; font-weight:800; padding:5px 12px; border-radius:6px;">
                                        💬 Clarification Requested by Civil Director
                                    </span>
                                <?php elseif ($req['status'] === 'Converted to Event' && !empty($req['converted_event_id'])): ?>
                                    <a href="<?= BASE_URL ?>/event_view.php?id=<?= (int)$req['converted_event_id'] ?>" class="btn btn-sm" style="background:#16a34a; color:#fff; font-weight:700; font-size:11px; padding:5px 12px; text-decoration:none; border-radius:6px;">
                                        ✓ Live University Event #<?= (int)$req['converted_event_id'] ?> &rarr;
                                    </a>
                                <?php else: ?>
                                    <span class="badge" style="background:#b45309; color:#fff; font-size:11px; font-weight:800; padding:5px 12px; border-radius:6px;">
                                        ⏳ Under Review by Civil Event Director
                                    </span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<!-- SUBMIT MILITARY EVENT DIRECTIVE MODAL (HIGHER COMMAND L1 ONLY) -->
<div id="submitReqModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.65); z-index:99999; align-items:center; justify-content:center; padding:20px;">
    <div style="background:#ffffff; color:#0f172a; border-radius:16px; max-width:720px; width:100%; padding:26px; box-shadow:0 24px 48px rgba(0,0,0,0.3); max-height:88vh; overflow-y:auto; border:2px solid #082d21;">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:2px solid #082d21; padding-bottom:12px; margin-bottom:18px;">
            <div>
                <span style="background:#082d21; color:#f5d98d; font-size:10px; font-weight:900; padding:2px 6px; border-radius:4px; font-family:monospace;">ARMED FORCES HIGHER COMMAND</span>
                <h3 style="margin:4px 0 0; font-size:18px; color:#082d21;">Promulgate Military Event Directive to Civil Directorate</h3>
            </div>
            <button type="button" onclick="document.getElementById('submitReqModal').style.display='none'" style="border:none; background:transparent; font-size:24px; cursor:pointer; color:#64748b;">&times;</button>
        </div>

        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="submit_event_request">

            <label class="span-2">
                Event Directive Title *
                <input type="text" name="title" required placeholder="e.g. Armed Forces Day Joint Ceremonial Parade &amp; Reception" class="form-control">
            </label>

            <label>
                Event Type *
                <select name="event_type" required class="form-control">
                    <option value="Armed Forces Day Ceremonial">Armed Forces Day Ceremonial</option>
                    <option value="Presidential / State Guard of Honour">Presidential / State Guard of Honour</option>
                    <option value="Convocation Military Parade">Convocation Military Parade</option>
                    <option value="High-Level Diplomatic Visit">High-Level Diplomatic Visit</option>
                    <option value="National Mourning / Martyr Remembrance">National Mourning / Martyr Remembrance</option>
                    <option value="Defense Symposium &amp; Seminar">Defense Symposium &amp; Seminar</option>
                </select>
            </label>

            <label>
                Operational Priority *
                <select name="priority" required class="form-control">
                    <option value="Routine">Routine (Regular Planning Cycle)</option>
                    <option value="Priority" selected>Priority (High-Level Dignitary)</option>
                    <option value="Immediate">Immediate (Time-Sensitive Directive)</option>
                    <option value="Flash">Flash (Head of State / Supreme Command)</option>
                </select>
            </label>

            <label>
                Proposed Date *
                <input type="date" name="proposed_date" value="<?= date('Y-m-d', strtotime('+14 days')) ?>" required class="form-control">
            </label>

            <label>
                Proposed Venue *
                <input type="text" name="proposed_venue" value="BUP Central Auditorium &amp; Parade Ground" required class="form-control">
            </label>

            <label class="span-2">
                Operational Objectives &amp; Mission Scope *
                <textarea name="objectives" rows="3" required placeholder="Outline the primary objectives, parade size, participating contingents, and required civil facilitation..." class="form-control"></textarea>
            </label>

            <label class="span-2">
                Required Military Protocol Standards
                <textarea name="required_protocol" rows="2" placeholder="e.g. 100-man guard of honour, 21-gun salute sync, presidential dais seating order..." class="form-control"></textarea>
            </label>

            <label class="span-2">
                Attached Standing Operating Procedures (SOPs)
                <select name="attached_sops[]" multiple class="form-control" style="min-height:90px;">
                    <?php foreach ($promulgated_sops as $ps): ?>
                        <option value="<?= e($ps['code']) ?>"><?= e($ps['code']) ?> &mdash; <?= e($ps['title']) ?> (<?= e($ps['category']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <small style="color:#64748b; font-size:11px;">Hold Ctrl/Cmd to select multiple applicable SOPs</small>
            </label>

            <label class="span-2">
                Special Tactical Instructions / Security Directives
                <textarea name="special_instructions" rows="2" placeholder="e.g. Motorcade route clearance via Mirpur-12, encrypted radio frequency CH-2, sweeper pilot mandatory..." class="form-control"></textarea>
            </label>

            <div class="span-2" style="display:flex; justify-content:flex-end; gap:10px; margin-top:10px;">
                <button type="button" class="btn btn-light" onclick="document.getElementById('submitReqModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background:#082d21; border:1px solid #d4af37; color:#fff; font-weight:800; padding:10px 24px;">
                    Promulgate Directive to Civil Command &rarr;
                </button>
            </div>
        </form>
    </div>
</div>

<!-- VIEW DIRECTIVE DETAIL MODAL -->
<div id="reqDetailModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.65); z-index:99999; align-items:center; justify-content:center; padding:20px;">
    <div style="background:#ffffff; color:#0f172a; border-radius:16px; max-width:700px; width:100%; padding:26px; box-shadow:0 24px 48px rgba(0,0,0,0.3); max-height:88vh; overflow-y:auto; border:2px solid #082d21;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #082d21; padding-bottom:12px; margin-bottom:16px;">
            <div>
                <span id="vReqId" style="background:#082d21; color:#f5d98d; font-size:11px; font-weight:900; padding:3px 7px; border-radius:4px; font-family:monospace;"></span>
                <span id="vReqPriority" style="font-size:10px; font-weight:800; padding:3px 7px; border-radius:4px; color:#fff; text-transform:uppercase; margin-left:6px;"></span>
                <h3 id="vReqTitle" style="margin:6px 0 2px; font-size:18px; color:#082d21;"></h3>
            </div>
            <button type="button" onclick="document.getElementById('reqDetailModal').style.display='none'" style="border:none; background:transparent; font-size:24px; cursor:pointer; color:#64748b;">&times;</button>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; font-size:12px; background:#f8fafc; padding:12px 14px; border-radius:8px; margin-bottom:16px; border:1px solid #e2e8f0;">
            <div>🏷️ <strong>Type:</strong> <span id="vReqType"></span></div>
            <div>📅 <strong>Date:</strong> <span id="vReqDate"></span></div>
            <div>📍 <strong>Venue:</strong> <span id="vReqVenue"></span></div>
            <div>🎖️ <strong>Issued by:</strong> <span id="vReqBy"></span></div>
        </div>

        <div style="margin-bottom:14px;">
            <strong style="font-size:12px; color:#082d21; text-transform:uppercase; letter-spacing:1px; display:block; margin-bottom:4px;">1. Operational Objectives &amp; Scope:</strong>
            <div id="vReqObj" style="background:#f8fafc; border:1px solid #e2e8f0; padding:12px; border-radius:8px; font-size:13px; line-height:1.5; color:#334155;"></div>
        </div>

        <div style="margin-bottom:14px;">
            <strong style="font-size:12px; color:#082d21; text-transform:uppercase; letter-spacing:1px; display:block; margin-bottom:4px;">2. Required Protocol Standards:</strong>
            <div id="vReqProto" style="background:#f8fafc; border:1px solid #e2e8f0; padding:12px; border-radius:8px; font-size:13px; line-height:1.5; color:#334155;"></div>
        </div>

        <div style="margin-bottom:14px;">
            <strong style="font-size:12px; color:#082d21; text-transform:uppercase; letter-spacing:1px; display:block; margin-bottom:4px;">3. Attached Standing Operating Procedures:</strong>
            <div id="vReqSops" style="background:#f0fdf4; border:1px solid #bbf7d0; padding:10px 12px; border-radius:8px; font-size:12px; color:#166534; font-family:monospace; font-weight:700;"></div>
        </div>

        <div style="margin-bottom:16px;">
            <strong style="font-size:12px; color:#082d21; text-transform:uppercase; letter-spacing:1px; display:block; margin-bottom:4px;">4. Tactical &amp; Security Instructions:</strong>
            <div id="vReqSpecial" style="background:#fffbeb; border:1px solid #fef3c7; padding:12px; border-radius:8px; font-size:13px; line-height:1.5; color:#92400e;"></div>
        </div>

        <div style="text-align:right;">
            <button type="button" class="btn btn-dark" onclick="document.getElementById('reqDetailModal').style.display='none'">Close Window</button>
        </div>
    </div>
</div>

<!-- CLARIFICATION MODAL -->
<div id="clarifyModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.65); z-index:99999; align-items:center; justify-content:center; padding:20px;">
    <div style="background:#ffffff; color:#0f172a; border-radius:14px; max-width:540px; width:100%; padding:22px; box-shadow:0 20px 40px rgba(0,0,0,0.3); border:2px solid #ea580c;">
        <h3 style="margin:0 0 8px; font-size:17px; color:#9a3412;">Request Operational Clarification (<span id="cReqId"></span>)</h3>
        <p style="margin:0 0 12px; font-size:12px; color:#64748b;">Specify queries or constraints regarding timing, civilian venue capacity, or resource logistics for Higher Command review.</p>
        <form method="post">
            <input type="hidden" name="action" value="review_event_request">
            <input type="hidden" name="decision" value="clarify">
            <input type="hidden" name="request_id" id="cDbId">
            <textarea name="clarification_message" rows="4" required class="form-control" placeholder="Type your clarification query to Higher Command..." style="width:100%; margin-bottom:12px;"></textarea>
            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="btn btn-light" onclick="document.getElementById('clarifyModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background:#ea580c; border:none; color:#fff; font-weight:700;">Dispatch Query &rarr;</button>
            </div>
        </form>
    </div>
</div>

<!-- REJECT MODAL -->
<div id="rejectModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.65); z-index:99999; align-items:center; justify-content:center; padding:20px;">
    <div style="background:#ffffff; color:#0f172a; border-radius:14px; max-width:540px; width:100%; padding:22px; box-shadow:0 20px 40px rgba(0,0,0,0.3); border:2px solid #dc2626;">
        <h3 style="margin:0 0 8px; font-size:17px; color:#991b1b;">Decline Military Event Directive (<span id="rReqId"></span>)</h3>
        <p style="margin:0 0 12px; font-size:12px; color:#64748b;">Please supply the formal institutional justification for declining this requested military event.</p>
        <form method="post">
            <input type="hidden" name="action" value="review_event_request">
            <input type="hidden" name="decision" value="reject">
            <input type="hidden" name="request_id" id="rDbId">
            <textarea name="rejection_reason" rows="4" required class="form-control" placeholder="Specify formal reason for declining..." style="width:100%; margin-bottom:12px;"></textarea>
            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="btn btn-light" onclick="document.getElementById('rejectModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-danger" style="background:#dc2626; border:none; color:#fff; font-weight:700;">Decline Directive</button>
            </div>
        </form>
    </div>
</div>

<script>
function openReqDetailsModal(r) {
    document.getElementById('vReqId').textContent = r.request_id || '';
    document.getElementById('vReqTitle').textContent = r.title || '';
    document.getElementById('vReqPriority').textContent = r.priority || 'Routine';
    
    const p = r.priority || 'Routine';
    document.getElementById('vReqPriority').style.background = (p === 'Flash') ? '#dc2626' : ((p === 'Immediate') ? '#e11d48' : ((p === 'Priority') ? '#f59e0b' : '#3b82f6'));

    document.getElementById('vReqType').textContent = r.event_type || 'Ceremony';
    document.getElementById('vReqDate').textContent = r.proposed_date || 'N/A';
    document.getElementById('vReqVenue').textContent = r.proposed_venue || 'N/A';
    document.getElementById('vReqBy').textContent = (r.requester_name || 'Higher Command') + (r.requester_designation ? ' (' + r.requester_designation + ')' : '');
    
    document.getElementById('vReqObj').textContent = r.objectives || 'No detailed objectives supplied.';
    document.getElementById('vReqProto').textContent = r.required_protocol || 'Standard Armed Forces ceremonial protocol applies.';
    document.getElementById('vReqSops').textContent = r.attached_sops || 'Standard Armed Forces Doctrine';
    document.getElementById('vReqSpecial').textContent = r.special_instructions || 'None specified.';
    
    document.getElementById('reqDetailModal').style.display = 'flex';
}

function openClarifyModal(dbId, reqId) {
    document.getElementById('cDbId').value = dbId;
    document.getElementById('cReqId').textContent = reqId;
    document.getElementById('clarifyModal').style.display = 'flex';
}

function openRejectModal(dbId, reqId) {
    document.getElementById('rDbId').value = dbId;
    document.getElementById('rReqId').textContent = reqId;
    document.getElementById('rejectModal').style.display = 'flex';
}
</script>

<!-- =====================================================
     INTERACTIVE MILITARY PROTOCOL HIERARCHY FLOW (8 LEVELS)
====================================================== -->
<section class="panel" style="margin-bottom:28px; padding:24px 28px; background:linear-gradient(135deg, #051d14 0%, #082d21 50%, #0d3b2b 100%); color:#ffffff; border-radius:18px; border:1px solid rgba(212,175,55,0.25); box-shadow:0 10px 30px rgba(6,40,30,0.12);">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:20px; border-bottom:1px solid rgba(255,255,255,0.12); padding-bottom:14px;">
        <div>
            <span style="font-size:11px; font-weight:800; letter-spacing:2px; color:#f5d98d; text-transform:uppercase;">OFFICIAL ARMED FORCES &amp; BUP PROTOCOL CHAIN</span>
            <h2 style="margin:4px 0 0; color:#ffffff; font-size:20px;">8-Tier Connected Command Flow (Click Any Node to Inspect)</h2>
        </div>
        <div style="font-size:12px; color:#94a3b8;">
            Hierarchy: Higher Command &rarr; Division &rarr; Brigade &rarr; Battalion &rarr; Company &rarr; Platoon &rarr; Section &rarr; Soldier
        </div>
    </div>

    <!-- 8 Levels Connected Visual Horizontal Flow -->
    <div style="display:flex; gap:8px; overflow-x:auto; padding-bottom:12px; margin-bottom:20px; align-items:center;">
        <?php foreach ($all_protocol_units as $idx => $pu): ?>
            <?php
            $pRank = $protocol_ranks[$pu['protocol_level']] ?? 8;
            $is_active_selection = ($inspected_unit && (int)$inspected_unit['id'] === (int)$pu['id']);
            $has_clearance = ($is_director_or_admin || $user_rank <= $pRank);
            ?>
            <a href="<?= BASE_URL ?>/protocol.php?unit_id=<?= $pu['id'] ?>#command-detail"
               style="text-decoration:none; min-width:170px; flex:1; background:<?= $is_active_selection ? 'rgba(245, 217, 141, 0.15)' : 'rgba(255,255,255,0.06)' ?>; border:2px solid <?= $is_active_selection ? '#f5d98d' : ($has_clearance ? 'rgba(255,255,255,0.18)' : 'rgba(239, 68, 68, 0.35)') ?>; border-radius:12px; padding:12px 14px; transition:all 0.2s; position:relative;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                    <span style="background:<?= $is_active_selection ? '#f5d98d' : '#334155' ?>; color:<?= $is_active_selection ? '#0f172a' : '#ffffff' ?>; font-weight:800; font-size:10px; padding:2px 6px; border-radius:4px; font-family:monospace;">
                        Level <?= $pRank ?>
                    </span>
                    <?php if (!$has_clearance): ?>
                        <span style="font-size:10px; color:#ef4444; font-weight:700;">🔒 Restricted</span>
                    <?php else: ?>
                        <span style="font-size:10px; color:#10b981; font-weight:700;">✓ Access</span>
                    <?php endif; ?>
                </div>
                <strong style="display:block; font-size:13px; color:<?= $is_active_selection ? '#f5d98d' : '#ffffff' ?>; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                    <?= e($pu['protocol_level']) ?>
                </strong>
                <div style="font-size:11px; color:#94a3b8; margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                    <?= e($pu['unit_name']) ?>
                </div>
                <div style="font-size:10px; color:#38bdf8; margin-top:4px;">
                    <?= e($pu['unit_code']) ?><?= !empty($pu['commander_name']) ? ' · ' . e($pu['commander_name']) : '' ?>
                </div>
            </a>
            <?php if ($idx < count($all_protocol_units) - 1): ?>
                <span style="color:#64748b; font-size:16px; font-weight:bold;">&rarr;</span>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <!-- INSPECTION DETAILS PANEL -->
    <div id="command-detail" style="background:rgba(0,0,0,0.25); border:1px solid rgba(255,255,255,0.12); border-radius:14px; padding:20px 24px;">
        <?php if (!$inspected_unit): ?>
            <p style="color:#94a3b8; margin:0;">Select a command node from the flow above to view its operational structure and duties.</p>
        <?php elseif (!$inspect_authorized): ?>
            <div style="display:flex; align-items:center; gap:14px; background:rgba(239, 68, 68, 0.12); border:1px solid rgba(239, 68, 68, 0.4); border-radius:10px; padding:16px 20px;">
                <span style="font-size:28px;">🔒</span>
                <div>
                    <strong style="color:#f87171; font-size:15px; display:block;">
                        <?= !empty($division_l1_restricted) ? 'AUTONOMOUS FORMATION BOUNDARY: Division (L2) Operational Panel' : 'RESTRICTED MILITARY PROTOCOL: Clearance Level Insufficient' ?>
                    </strong>
                    <p style="margin:4px 0 0; color:#fca5a5; font-size:12px; line-height:1.5;">
                        <?php if (!empty($division_l1_restricted)): ?>
                            In accordance with Armed Forces protocol hierarchy, the Division Commander's (L2) internal operational workspace is restricted from direct access by Higher Command (L1). Higher Command exercises strategic oversight through Authorized Directives, Rulebooks, and Division Reports, preserving tactical autonomy for the Division.
                        <?php else: ?>
                            Access to <strong><?= e($inspected_unit['unit_name']) ?> (<?= e($inspected_unit['protocol_level']) ?>)</strong> is restricted. Your current clearance level (<?= e($user_proto_level ?: current_user()['role']) ?>) is subordinate. In accordance with military chain-of-command rules, lower-level accounts can only inspect commands at or below their level.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        <?php else: ?>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:20px;">
                <!-- Unit Information -->
                <div>
                    <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                        <span style="background:#f5d98d; color:#0f172a; font-weight:800; font-size:11px; padding:3px 8px; border-radius:4px; font-family:monospace;">
                            <?= e($inspected_unit['protocol_level']) ?>
                        </span>
                        <span style="background:#0284c7; color:#fff; font-size:11px; font-weight:700; padding:3px 8px; border-radius:4px;">
                            Code: <?= e($inspected_unit['unit_code']) ?>
                        </span>
                        <span style="background:#16a34a; color:#fff; font-size:10px; font-weight:700; padding:2px 6px; border-radius:4px;">
                            <?= e($inspected_unit['status']) ?>
                        </span>
                    </div>

                    <h3 style="margin:0 0 6px; font-size:18px; color:#ffffff;">
                        <?= e($inspected_unit['unit_name']) ?>
                    </h3>
                    <p style="margin:0 0 12px; font-size:12px; color:#cbd5e1; line-height:1.5;">
                        <?= e($inspected_unit['responsibilities'] ?: 'Command responsibilities for official military and BUP event protocol.') ?>
                    </p>

                    <div style="font-size:12px; color:#94a3b8; display:flex; flex-direction:column; gap:4px;">
                        <?php if (!empty($inspected_unit['parent_unit_name'])): ?>
                            <div>Parent Command: <strong style="color:#ffffff;"><?= e($inspected_unit['parent_unit_name']) ?></strong> (<?= e($inspected_unit['parent_unit_level']) ?>)</div>
                        <?php else: ?>
                            <div>Parent Command: <strong style="color:#f5d98d;">National Defense Headquarters (Supreme Command)</strong></div>
                        <?php endif; ?>
                        <div>Location: <strong style="color:#ffffff;"><?= e($inspected_unit['location']) ?></strong></div>
                        <div>Assigned Strength: <strong style="color:#ffffff;"><?= e($inspected_unit['strength']) ?> Personnel</strong></div>
                    </div>
                </div>

                <!-- Commander & Responsible Personnel -->
                <div style="background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); border-radius:10px; padding:16px;">
                    <span style="font-size:10px; font-weight:800; letter-spacing:1.5px; color:#f5d98d; text-transform:uppercase;">UNIT COMMANDER</span>
                    <?php if (!empty($inspected_unit['commander_name'])): ?>
                        <h4 style="margin:6px 0 2px; color:#ffffff; font-size:16px;"><?= e($inspected_unit['commander_name']) ?></h4>
                        <div style="font-size:12px; color:#38bdf8; font-weight:600;"><?= e($inspected_unit['commander_designation'] ?: 'Commanding Officer') ?></div>
                        <div style="font-size:11px; color:#94a3b8; margin-top:8px;">
                            Email: <strong style="color:#e2e8f0;"><?= e($inspected_unit['commander_email']) ?></strong><br>
                            Phone: <strong style="color:#e2e8f0;"><?= e($inspected_unit['commander_phone'] ?: 'N/A') ?></strong>
                        </div>
                    <?php else: ?>
                        <div style="font-size:12px; color:#94a3b8; margin-top:8px;">No specific commander assigned to this command unit.</div>
                    <?php endif; ?>

                    <!-- Immediate Subordinate Commands -->
                    <div style="margin-top:14px; border-top:1px solid rgba(255,255,255,0.08); padding-top:10px;">
                        <span style="font-size:10px; font-weight:800; letter-spacing:1.5px; color:#38bdf8; text-transform:uppercase;">SUBORDINATE UNITS (<?= count($child_units) ?>)</span>
                        <?php if (!empty($child_units)): ?>
                            <div style="display:flex; flex-wrap:wrap; gap:6px; margin-top:6px;">
                                <?php foreach ($child_units as $cu): ?>
                                    <a href="<?= BASE_URL ?>/protocol.php?unit_id=<?= $cu['id'] ?>#command-detail"
                                       style="background:rgba(255,255,255,0.1); color:#f5d98d; font-size:11px; font-weight:600; text-decoration:none; padding:3px 8px; border-radius:4px;">
                                        &darr; <?= e($cu['unit_code']) ?> · <?= e($cu['unit_name']) ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div style="font-size:11px; color:#94a3b8; margin-top:4px;">Direct execution tier (no further subordinate commands).</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Assigned Operational Duties -->
                <div style="background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); border-radius:10px; padding:16px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <span style="font-size:10px; font-weight:800; letter-spacing:1.5px; color:#f5d98d; text-transform:uppercase;">RELEVANT PROTOCOL DUTIES</span>
                        <?php if (!is_military_protocol() && empty($user_proto_level)): ?>
                            <a href="<?= BASE_URL ?>/duties.php?dept=Protocol" style="font-size:11px; color:#38bdf8; text-decoration:none;">All Duties &rarr;</a>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($unit_duties)): ?>
                        <div style="display:flex; flex-direction:column; gap:8px;">
                            <?php foreach ($unit_duties as $ud): ?>
                                <div style="border-bottom:1px solid rgba(255,255,255,0.06); padding-bottom:6px;">
                                    <div style="display:flex; justify-content:space-between; align-items:center;">
                                        <strong style="font-size:12px; color:#ffffff;"><?= e($ud['title']) ?></strong>
                                        <span class="badge <?= strtolower($ud['status']) ?>" style="font-size:9px; padding:2px 6px;"><?= e($ud['status']) ?></span>
                                    </div>
                                    <small style="color:#94a3b8; font-size:10px;">
                                        <?= !empty($ud['duty_date']) ? e(date('d M Y', strtotime($ud['duty_date']))) : '' ?>
                                        <?= !empty($ud['location']) ? ' · 📍 ' . e($ud['location']) : '' ?>
                                    </small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p style="color:#94a3b8; font-size:11px; margin:0;">No specific operational duties currently assigned to this unit.</p>
                    <?php endif; ?>
                </div>

                <!-- ECHELON OPERATIONAL CONSOLE (L2-L8) -->
                <div style="grid-column: 1 / -1; margin-top:16px; border-top:1px solid rgba(255,255,255,0.12); padding-top:18px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                        <div>
                            <span style="font-size:10px; font-weight:900; letter-spacing:1.5px; color:#d4af37; text-transform:uppercase;">
                                ECHELON LEVEL <?= $protocol_ranks[$uLevel] ?? 8 ?> &bull; TACTICAL OPERATIONS CONSOLE
                            </span>
                            <h4 style="margin:2px 0 0; font-size:16px; color:#ffffff;">
                                <?= e($uLevel) ?> Operational Workspace &mdash; <?= e($inspected_unit['unit_name']) ?>
                            </h4>
                        </div>
                    </div>

                    <?php if ($uLevel === 'Division'): ?>
                        <!-- L2 DIVISION CONSOLE: Assign duties to Brigades & Monitor Brigade progress -->
                        <div id="brigades" style="background:rgba(255,255,255,0.06); border:1px solid rgba(212,175,55,0.3); border-radius:12px; padding:18px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:8px;">
                                <h5 style="margin:0; font-size:14px; color:#f5d98d;">🎖️ Division Tactical Command &bull; Delegate to Brigades</h5>
                                <a href="<?= BASE_URL ?>/manage_subordinates.php" style="background:#d4af37; color:#082d21; font-weight:800; font-size:11px; padding:4px 10px; border-radius:4px; text-decoration:none;">
                                    Manage Brigade Accounts &rarr;
                                </a>
                            </div>
                            <p style="margin:0 0 14px; font-size:12px; color:#cbd5e1;">Assign ceremonial parade orders, security detachments, and convoy route clearance directly to Brigade Commanders.</p>
                            
                            <form method="post" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:10px; align-items:end;">
                                <input type="hidden" name="action" value="assign_echelon_duty">
                                <input type="hidden" name="subordinate_level" value="L3">
                                <input type="hidden" name="unit_id" value="<?= $inspected_unit['id'] ?>">
                                
                                <div>
                                    <label style="font-size:11px; color:#cbd5e1; display:block; margin-bottom:4px;">Operational Directive Title</label>
                                    <input type="text" name="title" required placeholder="e.g. 71st Brigade Cordon &amp; Saluting Battery Setup" style="width:100%; border-radius:6px; border:1px solid rgba(255,255,255,0.2); background:rgba(0,0,0,0.3); color:#fff; padding:8px 10px; font-size:12px;">
                                </div>
                                <div>
                                    <label style="font-size:11px; color:#cbd5e1; display:block; margin-bottom:4px;">Target Brigade Commander</label>
                                    <select name="target_user_id" required style="width:100%; border-radius:6px; border:1px solid rgba(255,255,255,0.2); background:#06281e; color:#fff; padding:8px 10px; font-size:12px;">
                                        <option value="13">Brigadier General A. K. Azad (71st Infantry Brigade)</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size:11px; color:#cbd5e1; display:block; margin-bottom:4px;">Location</label>
                                    <input type="text" name="location" value="Parade Ground &amp; Main Cordon" style="width:100%; border-radius:6px; border:1px solid rgba(255,255,255,0.2); background:rgba(0,0,0,0.3); color:#fff; padding:8px 10px; font-size:12px;">
                                </div>
                                <div>
                                    <button type="submit" class="btn btn-primary btn-block" style="background:#16a34a; border:none; font-weight:700; font-size:12px; padding:9px; cursor:pointer;">
                                        Delegate to Brigade &rarr;
                                    </button>
                                </div>
                            </form>
                        </div>

                    <?php elseif ($uLevel === 'Brigade'): ?>
                        <!-- L3 BRIGADE CONSOLE: Manage Battalion accounts & assign Battalion duties -->
                        <div id="battalions" style="background:rgba(255,255,255,0.06); border:1px solid rgba(212,175,55,0.3); border-radius:12px; padding:18px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:8px;">
                                <h5 style="margin:0; font-size:14px; color:#f5d98d;">🎖️ Brigade Command &bull; Delegate to Battalions</h5>
                                <a href="<?= BASE_URL ?>/manage_subordinates.php" style="background:#d4af37; color:#082d21; font-weight:800; font-size:11px; padding:4px 10px; border-radius:4px; text-decoration:none;">
                                    Manage Battalion Accounts &rarr;
                                </a>
                            </div>
                            <p style="margin:0 0 14px; font-size:12px; color:#cbd5e1;">Mobilize battalion commanders for ceremonial drill, quarter guard turnout, and static guard detachments.</p>
                            
                            <form method="post" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:10px; align-items:end;">
                                <input type="hidden" name="action" value="assign_echelon_duty">
                                <input type="hidden" name="subordinate_level" value="L4">
                                <input type="hidden" name="unit_id" value="<?= $inspected_unit['id'] ?>">
                                
                                <div>
                                    <label style="font-size:11px; color:#cbd5e1; display:block; margin-bottom:4px;">Battalion Mission Directive</label>
                                    <input type="text" name="title" required placeholder="e.g. 1st East Bengal 100-Man Presidential Contingent Muster" style="width:100%; border-radius:6px; border:1px solid rgba(255,255,255,0.2); background:rgba(0,0,0,0.3); color:#fff; padding:8px 10px; font-size:12px;">
                                </div>
                                <div>
                                    <label style="font-size:11px; color:#cbd5e1; display:block; margin-bottom:4px;">Target Battalion CO</label>
                                    <select name="target_user_id" required style="width:100%; border-radius:6px; border:1px solid rgba(255,255,255,0.2); background:#06281e; color:#fff; padding:8px 10px; font-size:12px;">
                                        <option value="14">Lieutenant Colonel F. Hossain (1st East Bengal Battalion CO)</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size:11px; color:#cbd5e1; display:block; margin-bottom:4px;">Tactical Post</label>
                                    <input type="text" name="location" value="BUP Parade Ground Dais" style="width:100%; border-radius:6px; border:1px solid rgba(255,255,255,0.2); background:rgba(0,0,0,0.3); color:#fff; padding:8px 10px; font-size:12px;">
                                </div>
                                <div>
                                    <button type="submit" class="btn btn-primary btn-block" style="background:#16a34a; border:none; font-weight:700; font-size:12px; padding:9px; cursor:pointer;">
                                        Delegate to Battalion &rarr;
                                    </button>
                                </div>
                            </form>
                        </div>

                    <?php elseif ($uLevel === 'Battalion'): ?>
                        <!-- L4 BATTALION CONSOLE: Manage Company accounts & assign duties -->
                        <div id="companies" style="background:rgba(255,255,255,0.06); border:1px solid rgba(212,175,55,0.3); border-radius:12px; padding:18px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:8px;">
                                <h5 style="margin:0; font-size:14px; color:#f5d98d;">🎖️ Battalion Command &bull; Delegate to Companies</h5>
                                <a href="<?= BASE_URL ?>/manage_subordinates.php" style="background:#d4af37; color:#082d21; font-weight:800; font-size:11px; padding:4px 10px; border-radius:4px; text-decoration:none;">
                                    Manage Company &amp; Civil Integration &rarr;
                                </a>
                            </div>
                            <p style="margin:0 0 14px; font-size:12px; color:#cbd5e1;">Deploy Company Commanders for ceremonial escort, entrance access cordons, and VIP motorcade clearance.</p>
                            
                            <form method="post" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:10px; align-items:end;">
                                <input type="hidden" name="action" value="assign_echelon_duty">
                                <input type="hidden" name="subordinate_level" value="L5">
                                <input type="hidden" name="unit_id" value="<?= $inspected_unit['id'] ?>">
                                
                                <div>
                                    <label style="font-size:11px; color:#cbd5e1; display:block; margin-bottom:4px;">Company Operational Order</label>
                                    <input type="text" name="title" required placeholder="e.g. Alpha Company VIP Inner Perimeter &amp; Dais Cordon" style="width:100%; border-radius:6px; border:1px solid rgba(255,255,255,0.2); background:rgba(0,0,0,0.3); color:#fff; padding:8px 10px; font-size:12px;">
                                </div>
                                <div>
                                    <label style="font-size:11px; color:#cbd5e1; display:block; margin-bottom:4px;">Target Company Commander</label>
                                    <select name="target_user_id" required style="width:100%; border-radius:6px; border:1px solid rgba(255,255,255,0.2); background:#06281e; color:#fff; padding:8px 10px; font-size:12px;">
                                        <option value="15">Major K. M. Bashir (Alpha Company Commander)</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size:11px; color:#cbd5e1; display:block; margin-bottom:4px;">Sector</label>
                                    <input type="text" name="location" value="Auditorium Main Concourse" style="width:100%; border-radius:6px; border:1px solid rgba(255,255,255,0.2); background:rgba(0,0,0,0.3); color:#fff; padding:8px 10px; font-size:12px;">
                                </div>
                                <div>
                                    <button type="submit" class="btn btn-primary btn-block" style="background:#16a34a; border:none; font-weight:700; font-size:12px; padding:9px; cursor:pointer;">
                                        Delegate to Company &rarr;
                                    </button>
                                </div>
                            </form>
                        </div>

                    <?php elseif ($uLevel === 'Company'): ?>
                        <!-- L5 COMPANY CONSOLE: Manage Platoon accounts & assign duties -->
                        <div id="platoons" style="background:rgba(255,255,255,0.06); border:1px solid rgba(212,175,55,0.3); border-radius:12px; padding:18px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:8px;">
                                <h5 style="margin:0; font-size:14px; color:#f5d98d;">🎖️ Company Command &bull; Delegate to Platoons</h5>
                                <a href="<?= BASE_URL ?>/manage_subordinates.php" style="background:#d4af37; color:#082d21; font-weight:800; font-size:11px; padding:4px 10px; border-radius:4px; text-decoration:none;">
                                    Manage Platoon Accounts &rarr;
                                </a>
                            </div>
                            <p style="margin:0 0 14px; font-size:12px; color:#cbd5e1;">Assign tactical posts, walkie-talkie checks, and guard lines directly to Platoon Commanders.</p>
                            
                            <form method="post" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:10px; align-items:end;">
                                <input type="hidden" name="action" value="assign_echelon_duty">
                                <input type="hidden" name="subordinate_level" value="L6">
                                <input type="hidden" name="unit_id" value="<?= $inspected_unit['id'] ?>">
                                
                                <div>
                                    <label style="font-size:11px; color:#cbd5e1; display:block; margin-bottom:4px;">Platoon Tactical Order</label>
                                    <input type="text" name="title" required placeholder="e.g. 1st Platoon Guard of Honour Gate Post &amp; Cordon" style="width:100%; border-radius:6px; border:1px solid rgba(255,255,255,0.2); background:rgba(0,0,0,0.3); color:#fff; padding:8px 10px; font-size:12px;">
                                </div>
                                <div>
                                    <label style="font-size:11px; color:#cbd5e1; display:block; margin-bottom:4px;">Target Platoon Commander</label>
                                    <select name="target_user_id" required style="width:100%; border-radius:6px; border:1px solid rgba(255,255,255,0.2); background:#06281e; color:#fff; padding:8px 10px; font-size:12px;">
                                        <option value="16">Captain T. Mahmood (1st Platoon Commander)</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size:11px; color:#cbd5e1; display:block; margin-bottom:4px;">Designated Gate</label>
                                    <input type="text" name="location" value="BUP Gate 1 &amp; Protocol Corridor" style="width:100%; border-radius:6px; border:1px solid rgba(255,255,255,0.2); background:rgba(0,0,0,0.3); color:#fff; padding:8px 10px; font-size:12px;">
                                </div>
                                <div>
                                    <button type="submit" class="btn btn-primary btn-block" style="background:#16a34a; border:none; font-weight:700; font-size:12px; padding:9px; cursor:pointer;">
                                        Delegate to Platoon &rarr;
                                    </button>
                                </div>
                            </form>
                        </div>

                    <?php elseif ($uLevel === 'Platoon'): ?>
                        <!-- L6 PLATOON CONSOLE: Manage Section accounts & assign section duties -->
                        <div id="sections" style="background:rgba(255,255,255,0.06); border:1px solid rgba(212,175,55,0.3); border-radius:12px; padding:18px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:8px;">
                                <h5 style="margin:0; font-size:14px; color:#f5d98d;">🎖️ Platoon Command &bull; Delegate to Sections</h5>
                                <a href="<?= BASE_URL ?>/manage_subordinates.php" style="background:#d4af37; color:#082d21; font-weight:800; font-size:11px; padding:4px 10px; border-radius:4px; text-decoration:none;">
                                    Manage Section Accounts &rarr;
                                </a>
                            </div>
                            <p style="margin:0 0 14px; font-size:12px; color:#cbd5e1;">Brief section commanders on turnout standards, sentry points, and live communication frequency.</p>
                            
                            <form method="post" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:10px; align-items:end;">
                                <input type="hidden" name="action" value="assign_echelon_duty">
                                <input type="hidden" name="subordinate_level" value="L7">
                                <input type="hidden" name="unit_id" value="<?= $inspected_unit['id'] ?>">
                                
                                <div>
                                    <label style="font-size:11px; color:#cbd5e1; display:block; margin-bottom:4px;">Section Task Order</label>
                                    <input type="text" name="title" required placeholder="e.g. Section 1 VIP Dais Guard &amp; Quarter Sentry" style="width:100%; border-radius:6px; border:1px solid rgba(255,255,255,0.2); background:rgba(0,0,0,0.3); color:#fff; padding:8px 10px; font-size:12px;">
                                </div>
                                <div>
                                    <label style="font-size:11px; color:#cbd5e1; display:block; margin-bottom:4px;">Target Section Commander</label>
                                    <select name="target_user_id" required style="width:100%; border-radius:6px; border:1px solid rgba(255,255,255,0.2); background:#06281e; color:#fff; padding:8px 10px; font-size:12px;">
                                        <option value="17">Sergeant R. Karim (Section 1 Commander)</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size:11px; color:#cbd5e1; display:block; margin-bottom:4px;">Post Position</label>
                                    <input type="text" name="location" value="Main Dais Stage Right" style="width:100%; border-radius:6px; border:1px solid rgba(255,255,255,0.2); background:rgba(0,0,0,0.3); color:#fff; padding:8px 10px; font-size:12px;">
                                </div>
                                <div>
                                    <button type="submit" class="btn btn-primary btn-block" style="background:#16a34a; border:none; font-weight:700; font-size:12px; padding:9px; cursor:pointer;">
                                        Delegate to Section &rarr;
                                    </button>
                                </div>
                            </form>
                        </div>

                    <?php elseif ($uLevel === 'Section'): ?>
                        <!-- L7 SECTION CONSOLE: Manage Soldier accounts & assign individual duties -->
                        <div id="soldiers" style="background:rgba(255,255,255,0.06); border:1px solid rgba(212,175,55,0.3); border-radius:12px; padding:18px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:8px;">
                                <h5 style="margin:0; font-size:14px; color:#f5d98d;">🎖️ Section Command &bull; Direct Soldier Tasking</h5>
                                <a href="<?= BASE_URL ?>/manage_subordinates.php" style="background:#d4af37; color:#082d21; font-weight:800; font-size:11px; padding:4px 10px; border-radius:4px; text-decoration:none;">
                                    Manage Assigned Soldier Accounts &rarr;
                                </a>
                            </div>
                            <p style="margin:0 0 14px; font-size:12px; color:#cbd5e1;">Assign individual soldiers to specific ceremonial sentry posts, escort positions, and inspection stations.</p>
                            
                            <form method="post" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:10px; align-items:end;">
                                <input type="hidden" name="action" value="assign_echelon_duty">
                                <input type="hidden" name="subordinate_level" value="L8">
                                <input type="hidden" name="unit_id" value="<?= $inspected_unit['id'] ?>">
                                
                                <div>
                                    <label style="font-size:11px; color:#cbd5e1; display:block; margin-bottom:4px;">Soldier Post Directive</label>
                                    <input type="text" name="title" required placeholder="e.g. Ceremonial Honor Sentry Post #1 - Dias Left" style="width:100%; border-radius:6px; border:1px solid rgba(255,255,255,0.2); background:rgba(0,0,0,0.3); color:#fff; padding:8px 10px; font-size:12px;">
                                </div>
                                <div>
                                    <label style="font-size:11px; color:#cbd5e1; display:block; margin-bottom:4px;">Target Soldier</label>
                                    <select name="target_user_id" required style="width:100%; border-radius:6px; border:1px solid rgba(255,255,255,0.2); background:#06281e; color:#fff; padding:8px 10px; font-size:12px;">
                                        <option value="18">Corporal N. Islam (Individual Soldier - Sentry)</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size:11px; color:#cbd5e1; display:block; margin-bottom:4px;">Assigned Sentry Spot</label>
                                    <input type="text" name="location" value="VIP Dais Guard Post 1" style="width:100%; border-radius:6px; border:1px solid rgba(255,255,255,0.2); background:rgba(0,0,0,0.3); color:#fff; padding:8px 10px; font-size:12px;">
                                </div>
                                <div>
                                    <button type="submit" class="btn btn-primary btn-block" style="background:#16a34a; border:none; font-weight:700; font-size:12px; padding:9px; cursor:pointer;">
                                        Assign Soldier Duty &rarr;
                                    </button>
                                </div>
                            </form>
                        </div>

                    <?php elseif ($uLevel === 'Individual Soldier'): ?>
                        <!-- L8 SOLDIER DESK & DIGITAL DUTY PASS -->
                        <div id="soldier-pass" style="background:rgba(255,255,255,0.06); border:2px solid #d4af37; border-radius:12px; padding:20px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:14px; border-bottom:1px solid rgba(212,175,55,0.3); padding-bottom:12px;">
                                <div>
                                    <span style="background:#d4af37; color:#082d21; font-size:10px; font-weight:900; padding:2px 6px; border-radius:4px; font-family:monospace;">LEVEL 8 SOLDIER DESK</span>
                                    <h5 style="margin:4px 0 0; font-size:17px; color:#ffffff;">Armed Forces Ceremonial Protocol Pass</h5>
                                </div>
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <a href="<?= BASE_URL ?>/manage_subordinates.php" style="background:#d4af37; color:#082d21; font-weight:800; font-size:11px; padding:6px 12px; border-radius:6px; text-decoration:none;">
                                        Open Full Duty Pass Console &rarr;
                                    </a>
                                    <div style="background:#06281e; border:1px solid #d4af37; border-radius:8px; padding:6px 12px; text-align:center;">
                                        <span style="font-size:10px; color:#94a3b8; text-transform:uppercase; font-family:monospace;">DUTY PASS ID</span>
                                        <strong style="display:block; font-size:13px; color:#f5d98d; font-family:monospace;">#MIL-PASS-018</strong>
                                    </div>
                                </div>
                            </div>

                            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:14px; font-size:12px; color:#cbd5e1; margin-bottom:16px;">
                                <div>👤 <strong>Soldier:</strong> Corporal N. Islam (BA-90182)</div>
                                <div>🛡️ <strong>Post:</strong> Ceremonial Honor Sentry (VIP Dais Guard)</div>
                                <div>⚔️ <strong>Unit:</strong> Alpha Company &bull; 1st East Bengal</div>
                                <div>📡 <strong>Comms:</strong> Frequency Band SEC-CH-2</div>
                            </div>

                            <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:14px;">
                                <?php
                                $sDutyId = !empty($unit_duties) ? (int)$unit_duties[0]['id'] : 0;
                                ?>
                                <?php if ($sDutyId > 0): ?>
                                    <form method="post" style="display:inline; margin:0;">
                                        <input type="hidden" name="action" value="soldier_update_duty">
                                        <input type="hidden" name="duty_id" value="<?= $sDutyId ?>">
                                        <input type="hidden" name="status" value="Accepted">
                                        <input type="hidden" name="unit_id" value="<?= $inspected_unit['id'] ?>">
                                        <button type="submit" class="btn btn-sm" style="background:#0284c7; color:#fff; border:none; font-weight:700; padding:6px 12px; border-radius:6px; cursor:pointer;">
                                            ✍️ Acknowledge Standing Order
                                        </button>
                                    </form>

                                    <form method="post" style="display:inline; margin:0;">
                                        <input type="hidden" name="action" value="soldier_update_duty">
                                        <input type="hidden" name="duty_id" value="<?= $sDutyId ?>">
                                        <input type="hidden" name="status" value="In Progress">
                                        <input type="hidden" name="unit_id" value="<?= $inspected_unit['id'] ?>">
                                        <button type="submit" class="btn btn-sm" style="background:#f59e0b; color:#0f172a; border:none; font-weight:800; padding:6px 12px; border-radius:6px; cursor:pointer;">
                                            ⚡ Manned &amp; In Progress
                                        </button>
                                    </form>

                                    <form method="post" style="display:inline; margin:0;">
                                        <input type="hidden" name="action" value="soldier_update_duty">
                                        <input type="hidden" name="duty_id" value="<?= $sDutyId ?>">
                                        <input type="hidden" name="status" value="Completed">
                                        <input type="hidden" name="unit_id" value="<?= $inspected_unit['id'] ?>">
                                        <button type="submit" class="btn btn-sm" style="background:#16a34a; color:#fff; border:none; font-weight:700; padding:6px 12px; border-radius:6px; cursor:pointer;">
                                            ✓ Submit Post Completion
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <div style="font-size:12px; color:#86efac; font-weight:600;">
                                        &check; Standing post verified. Awaiting next formal ceremonial muster.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- SUMMARY -->
<div class="stat-grid">

    <div class="stat-card">

        <span>Total Requirements</span>

        <strong>
            <?= $total_items ?>
        </strong>

        <em>
            Current filtered records
        </em>

    </div>


    <div class="stat-card">

        <span>Completed</span>

        <strong>
            <?= $completed_count ?>
        </strong>

        <em>
            Protocol checkpoints completed
        </em>

    </div>


    <div class="stat-card">

        <span>Pending</span>

        <strong>
            <?= $pending_count ?>
        </strong>

        <em>
            Requirements needing attention
        </em>

    </div>


    <div class="stat-card">

        <span>Completion</span>

        <strong>
            <?= $completion_percentage ?>%
        </strong>

        <em>
            Overall protocol progress
        </em>

    </div>

</div>


<!-- FILTER & CHECKLIST (CIVIL ONLY - EXCLUDED FOR MILITARY L1 TO L8) -->
<?php if (!is_military_protocol() && empty($user_proto_level)): ?>
<section class="panel">

    <div class="panel-head">

        <div>

            <h2>
                Protocol Search & Filter
            </h2>

            <p>
                Find protocol requirements by event,
                instruction or completion status.
            </p>

        </div>

    </div>


    <form
        method="get"
        class="filter-form"
    >

        <input
            type="text"
            name="q"
            value="<?= e($q) ?>"
            placeholder="Search requirement, instruction..."
        >


        <select name="event_id">

            <option value="">
                All Events
            </option>


            <?php foreach ($events as $ev): ?>

                <option
                    value="<?= $ev['id'] ?>"
                    <?= $event_filter == $ev['id']
                        ? 'selected'
                        : ''
                    ?>
                >
                    <?= e($ev['name']) ?>
                </option>

            <?php endforeach; ?>

        </select>


        <select name="status">

            <option value="">
                All Status
            </option>

            <option
                value="Pending"
                <?= $status_filter === 'Pending'
                    ? 'selected'
                    : ''
                ?>
            >
                Pending
            </option>

            <option
                value="Completed"
                <?= $status_filter === 'Completed'
                    ? 'selected'
                    : ''
                ?>
            >
                Completed
            </option>

        </select>


        <button
            class="btn btn-dark"
            type="submit"
        >
            Filter
        </button>


        <a
            class="btn btn-light"
            href="<?= BASE_URL ?>/protocol.php"
        >
            Reset
        </a>

    </form>

</section>


<!-- PROTOCOL REGISTER -->

<section class="panel">

    <div class="panel-head">

        <div>

            <h2>
                Protocol Checklist
            </h2>

            <p>
                <?= count($items) ?>
                protocol requirements found
            </p>

        </div>

    </div>


    <?php if ($items): ?>

        <div class="activity-list">

            <?php foreach ($items as $p): ?>

                <div class="protocol-item">


                    <!-- CHECK -->

                    <form method="post">

                        <input
                            type="hidden"
                            name="action"
                            value="toggle"
                        >

                        <input
                            type="hidden"
                            name="id"
                            value="<?= $p['id'] ?>"
                        >


                        <button
                            type="submit"
                            class="check <?= $p['completed']
                                ? 'done'
                                : ''
                            ?>"
                            title="<?= $p['completed']
                                ? 'Mark as pending'
                                : 'Mark as completed'
                            ?>"
                        >
                            ✓
                        </button>

                    </form>


                    <!-- CONTENT -->

                    <div style="flex:1;">

                        <strong
                            class="<?= $p['completed']
                                ? 'strike'
                                : ''
                            ?>"
                        >
                            <?= e($p['item']) ?>
                        </strong>


                        <small>

                            <?= e(
                                $p['event_name']
                                ?? 'Unassigned Event'
                            ) ?>


                            <?php if (!empty($p['instructions'])): ?>

                                · <?= e($p['instructions']) ?>

                            <?php endif; ?>

                        </small>


                        <small>

                            Event Date:
                            <?= !empty($p['event_date'])
                                ? e(date(
                                    'd M Y',
                                    strtotime($p['event_date'])
                                ))
                                : '—'
                            ?>

                        </small>

                    </div>


                    <!-- STATUS -->

                    <span
                        class="badge <?= $p['completed']
                            ? 'completed'
                            : 'pending'
                        ?>"
                    >

                        <?= $p['completed']
                            ? 'Completed'
                            : 'Pending'
                        ?>

                    </span>


                    <!-- ACTIONS -->

                    <?php if (!is_event_director() && !is_event_coordinator()): ?>
                    <div>

                        <a
                            class="table-link"
                            href="<?= BASE_URL ?>/protocol.php?edit=<?= $p['id'] ?>#protocol-form"
                        >
                            Edit
                        </a>


                        <form
                            method="post"
                            class="inline-form"
                            style="display:inline;"
                        >

                            <input
                                type="hidden"
                                name="action"
                                value="delete"
                            >

                            <input
                                type="hidden"
                                name="id"
                                value="<?= $p['id'] ?>"
                            >


                            <button
                                type="submit"
                                class="link-danger"
                                onclick="return confirm('Delete this protocol requirement?')"
                            >
                                Delete
                            </button>

                        </form>

                    </div>
                    <?php else: ?>
                    <div>
                        <span style="font-size:11px; color:#64748b;">Protocol Standard</span>
                    </div>
                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        </div>


    <?php else: ?>

        <div class="empty">

            No protocol requirements found.

        </div>

    <?php endif; ?>

</section>


<?php if (!is_event_director() && !is_event_coordinator()): ?>
<!-- ADD / EDIT FORM -->

<section
    id="protocol-form"
    class="panel form-panel"
>

    <div class="panel-head">

        <div>

            <h2>

                <?= $edit_item
                    ? 'Edit Protocol Requirement'
                    : 'Add Protocol Requirement'
                ?>

            </h2>


            <p>

                <?= $edit_item
                    ? 'Update the event-specific protocol instruction.'
                    : 'Add a new military or official protocol checkpoint.'
                ?>

            </p>

        </div>


        <?php if ($edit_item): ?>

            <a
                class="btn btn-light"
                href="<?= BASE_URL ?>/protocol.php"
            >
                Cancel Edit
            </a>

        <?php endif; ?>

    </div>


    <form
        method="post"
        class="form-grid"
    >

        <input
            type="hidden"
            name="action"
            value="<?= $edit_item
                ? 'update'
                : 'add'
            ?>"
        >


        <?php if ($edit_item): ?>

            <input
                type="hidden"
                name="id"
                value="<?= $edit_item['id'] ?>"
            >

        <?php endif; ?>


        <label>

            Event

            <select
                name="event_id"
                required
            >

                <option value="">
                    Select event
                </option>


                <?php foreach ($events as $ev): ?>

                    <option
                        value="<?= $ev['id'] ?>"
                        <?= (
                            $edit_item &&
                            $edit_item['event_id'] == $ev['id']
                        )
                            ? 'selected'
                            : ''
                        ?>
                    >

                        <?= e($ev['name']) ?>

                    </option>

                <?php endforeach; ?>

            </select>

        </label>


        <label>

            Requirement

            <input
                type="text"
                name="item"
                value="<?= e(
                    $edit_item['item'] ?? ''
                ) ?>"
                placeholder="e.g. VIP reception arrangement"
                required
            >

        </label>


        <label class="span-2">

            Special Instructions

            <textarea
                name="instructions"
                rows="4"
                placeholder="Enter protocol instructions..."
            ><?= e(
                $edit_item['instructions'] ?? ''
            ) ?></textarea>

        </label>


        <div class="span-2">

            <button
                class="btn btn-primary"
                type="submit"
            >

                <?= $edit_item
                    ? 'Update Requirement'
                    : 'Add to Checklist'
                ?>

            </button>


            <?php if ($edit_item): ?>

                <a
                    class="btn btn-light"
                    href="<?= BASE_URL ?>/protocol.php"
                >
                    Cancel
                </a>

            <?php endif; ?>

        </div>

    </form>

</section>
<?php endif; ?>
<?php endif; /* End of non-military filter and checklist */ ?>




<style>
/* ==========================================
   PROTOCOL PAGE — CARD SPACING FIX
========================================== */

/* Page heading */
.page-head {
    margin-bottom: 28px;
}

/* Summary cards */
.stat-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

/* Separate protocol sections */
.main-content > .panel,
.main-content > section.panel {
    margin-bottom: 26px;
}

/* Panel container */
.main-content .panel {
    min-width: 0;
    border-radius: 20px;
    overflow: hidden;
}

/* Panel header */
.main-content .panel-head {
    padding: 24px 28px;
    margin: 0;
    border-bottom: 1px solid #e5eaf1;
}

/* Search and filter */
.main-content .filter-form {
    display: grid;
    grid-template-columns:
        minmax(180px, 1.2fr)
        repeat(2, minmax(160px, 1fr))
        auto auto;
    align-items: center;
    gap: 12px;
    padding: 18px 22px;
}

.main-content .filter-form input,
.main-content .filter-form select {
    box-sizing: border-box;
    width: 100%;
    min-width: 0;
    min-height: 58px;
    margin: 0;
}

.main-content .filter-form .btn {
    min-height: 58px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    white-space: nowrap;
}

/* Protocol checklist rows */
.protocol-item {
    margin: 0 24px;
    padding: 20px 4px;
    border-bottom: 1px solid #e5eaf1;
}

.protocol-item:last-child {
    border-bottom: 0;
}

/* Add / Edit protocol form */
.main-content .form-panel {
    margin-top: 26px;
}

.main-content .form-panel .panel-head {
    margin-bottom: 0;
}

.main-content .form-panel form.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 22px 24px;
    padding: 26px 28px 30px;
}

.main-content .form-panel form.form-grid > label {
    display: flex;
    flex-direction: column;
    gap: 9px;
    min-width: 0;
}

.main-content .form-panel form.form-grid input,
.main-content .form-panel form.form-grid select,
.main-content .form-panel form.form-grid textarea {
    box-sizing: border-box;
    width: 100%;
    min-width: 0;
}

.main-content .form-panel form.form-grid .span-2 {
    grid-column: 1 / -1;
}

/* Tablet */
@media (max-width: 1200px) {
    .stat-grid {
        gap: 16px;
    }

    .main-content .filter-form {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

/* Mobile */
@media (max-width: 720px) {
    .page-head {
        margin-bottom: 20px;
    }

    .stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
        margin-bottom: 22px;
    }

    .main-content > .panel,
    .main-content > section.panel {
        margin-bottom: 20px;
    }

    .main-content .panel-head {
        padding: 20px;
    }

    .main-content .filter-form {
        grid-template-columns: 1fr;
        gap: 12px;
        padding: 16px;
    }

    .main-content .filter-form input,
    .main-content .filter-form select,
    .main-content .filter-form .btn {
        width: 100%;
        min-height: 50px;
    }

    .protocol-item {
        margin: 0 14px;
        padding: 16px 2px;
    }

    .main-content .form-panel form.form-grid {
        grid-template-columns: 1fr;
        gap: 18px;
        padding: 20px;
    }

    .main-content .form-panel form.form-grid .span-2 {
        grid-column: 1;
    }
}

@media (max-width: 420px) {
    .stat-grid {
        gap: 10px;
    }

    .main-content .panel-head {
        padding: 16px;
    }

    .main-content .form-panel form.form-grid {
        padding: 16px;
    }
}
</style>




<?php require __DIR__ . '/includes/footer.php'; ?>