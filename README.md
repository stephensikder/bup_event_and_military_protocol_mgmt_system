# BUP Event & Military Protocol Management System
## PHP + MySQL + XAMPP Edition

A modern, professional web-based management system based on the supplied **BUP Event & Military Protocol Management System — FINAL Complete Feature List**.

### Technology
- Frontend: HTML5, CSS3, Vanilla JavaScript
- Backend: PHP 8+
- Database: MySQL / MariaDB
- Local server: XAMPP (Apache + MySQL)
- Optional API testing: Postman
- Version control: Git / GitHub

### Main modules
1. Authentication & role-based access
2. Event management
3. Guest & VIP management
4. Venue & seating
5. Duty management
6. Military & official protocol
7. Announcements & notifications
8. Attendance & QR check-in
9. Event approval / workflow
10. Transport, parking & accommodation
11. Reports & analytics
12. Audit log & document storage
13. Emergency contacts
14. Digital invitations
15. Priority events
16. Advanced search

### XAMPP setup
1. Install XAMPP.
2. Start **Apache** and **MySQL**.
3. Copy the `bup-event-protocol` folder into:
   `C:\xampp\htdocs\`
4. Open phpMyAdmin:
   `http://localhost/phpmyadmin`
5. Create/import the database by importing:
   `database/bup_event_protocol.sql`
6. Open:
   `http://localhost/bup-event-protocol/`

### Demo login
- Admin: `admin@bup.local`
- Password: `password`

Other seeded roles:
- Event Manager: `event@bup.local` / `password`
- Protocol Officer: `protocol@bup.local` / `password`
- Duty Personnel: `duty@bup.local` / `password`
- BUP Official: `official@bup.local` / `password`

> For a real deployment, replace all demo passwords with strong password hashes and configure HTTPS.

### Project structure
```text
bup-event-protocol/
├── index.php
├── login.php
├── logout.php
├── dashboard.php
├── events.php
├── guests.php
├── seating.php
├── duties.php
├── protocol.php
├── attendance.php
├── approvals.php
├── transport.php
├── reports.php
├── notifications.php
├── emergency.php
├── invitations.php
├── search.php
├── profile.php
├── config/
│   ├── config.php
│   ├── database.php
│   └── auth.php
├── includes/
│   ├── header.php
│   ├── navbar.php
│   ├── sidebar.php
│   └── footer.php
├── actions/
│   └── event_action.php
├── assets/
│   ├── css/style.css
│   └── js/app.js
├── database/
│   └── bup_event_protocol.sql
├── uploads/
│   └── .gitkeep
└── api/
    └── events.php
```

### Important
This package is designed as a strong functional foundation for the full feature list. Some advanced integrations such as real email/SMS delivery, production QR scanners, PDF rendering engines, and cloud file storage require deployment-specific services/libraries.
