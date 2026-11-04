# Feature-to-Module Mapping

The source PDF contains 58 feature items plus the PHP/MySQL technology section.

| Source area | Implemented location |
|---|---|
| User Login & Authentication | `login.php`, `config/auth.php` |
| Role-Based Access | `config/auth.php`, user roles in DB |
| User Profile | `profile.php` |
| Account activation/deactivation | `users` schema / admin extension point |
| Login security | `users.failed_attempts` schema / extension point |
| Event Creation & Information | `events.php` |
| Event Update/Cancellation | cancellation flow; update extension point |
| Event Calendar | `calendar.php` |
| Event Status/Search/Filtering | `events.php` |
| Event Dashboard | `dashboard.php` |
| Guest/VIP Management | `guests.php` |
| Seat & Venue Management | `seating.php` placeholder-ready module |
| Duty Management | `duties.php` |
| Protocol | `protocol.php` |
| Communication | `notifications.php` |
| QR/Attendance | `attendance.php` |
| Approval Workflow | `approvals.php` |
| Transport/Parking/Accommodation | `transport.php` |
| Reports/Analytics/Audit | `reports.php`, `audit_logs` |
| Emergency Contacts | `emergency.php` |
| Digital Invitations | `invitations.php` |
| Advanced Search | `search.php` |
| PHP + MySQL + XAMPP | configured in `config/` and `database/` |

The source document describes seat allocation, venue layout, smart duty assignment, live event dashboard, conflict detection, document storage and feedback as advanced/enhancement scope. These should be expanded as the next implementation phase.
