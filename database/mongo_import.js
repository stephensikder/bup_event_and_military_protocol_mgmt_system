// MongoDB Migration Script for BUP Event & Military Protocol Management System
// Target Database: bup_event_protocol
// Does NOT touch or interfere with 'travelkoribangladesh' database!

const targetDbName = 'bup_event_protocol';
const db = db.getSiblingDB(targetDbName);

print(`--- Starting migration to MongoDB Database: '${targetDbName}' ---`);

// 1. users
db.users.drop();
db.users.createIndex({ email: 1 }, { unique: true });
db.users.insertMany([
  {
    id: 1,
    name: 'System Administrator',
    email: 'admin@bup.local',
    password_hash: '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro3M6V3K7M6h3WvP7u8tK8kS',
    role: 'Admin',
    designation: 'System Administrator',
    phone: '01700000001',
    status: 'Active',
    failed_attempts: 0,
    last_login: null,
    created_at: new Date()
  },
  {
    id: 2,
    name: 'Event Management Officer',
    email: 'event@bup.local',
    password_hash: '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro3M6V3K7M6h3WvP7u8tK8kS',
    role: 'Event Management',
    designation: 'Event Management Officer',
    phone: '01700000002',
    status: 'Active',
    failed_attempts: 0,
    last_login: null,
    created_at: new Date()
  },
  {
    id: 3,
    name: 'Protocol Officer',
    email: 'protocol@bup.local',
    password_hash: '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro3M6V3K7M6h3WvP7u8tK8kS',
    role: 'Protocol Officer',
    designation: 'Military Protocol Officer',
    phone: '01700000003',
    status: 'Active',
    failed_attempts: 0,
    last_login: null,
    created_at: new Date()
  },
  {
    id: 4,
    name: 'Duty Personnel',
    email: 'duty@bup.local',
    password_hash: '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro3M6V3K7M6h3WvP7u8tK8kS',
    role: 'Duty Personnel',
    designation: 'Duty Coordinator',
    phone: '01700000004',
    status: 'Active',
    failed_attempts: 0,
    last_login: null,
    created_at: new Date()
  },
  {
    id: 5,
    name: 'BUP Official',
    email: 'official@bup.local',
    password_hash: '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro3M6V3K7M6h3WvP7u8tK8kS',
    role: 'BUP Official',
    designation: 'University Official',
    phone: '01700000005',
    status: 'Active',
    failed_attempts: 0,
    last_login: null,
    created_at: new Date()
  }
]);

// 2. events
db.events.drop();
db.events.createIndex({ event_date: 1 });
db.events.createIndex({ status: 1 });
const now = new Date();
db.events.insertMany([
  {
    id: 1,
    name: 'BUP Annual Academic Conference',
    event_type: 'Academic',
    event_date: new Date(now.getTime() + 7 * 86400000).toISOString().split('T')[0],
    event_time: '10:00:00',
    venue: 'BUP Grand Auditorium',
    description: 'Annual academic conference with invited faculty and officials.',
    status: 'Upcoming',
    priority: 1,
    approval_status: 'Approved',
    approved_by: 1,
    approved_at: new Date(),
    created_by: 1,
    created_at: new Date()
  },
  {
    id: 2,
    name: 'Official Delegation Reception',
    event_type: 'Official',
    event_date: new Date(now.getTime() + 14 * 86400000).toISOString().split('T')[0],
    event_time: '16:30:00',
    venue: 'Protocol Lounge',
    description: 'Formal reception and guest coordination for an official delegation.',
    status: 'Upcoming',
    priority: 1,
    approval_status: 'Pending',
    approved_by: null,
    approved_at: null,
    created_by: 1,
    created_at: new Date()
  },
  {
    id: 3,
    name: 'Military Leadership Seminar',
    event_type: 'Military',
    event_date: new Date(now.getTime() + 21 * 86400000).toISOString().split('T')[0],
    event_time: '09:30:00',
    venue: 'Seminar Hall A',
    description: 'Leadership and strategic affairs seminar.',
    status: 'Upcoming',
    priority: 0,
    approval_status: 'Pending',
    approved_by: null,
    approved_at: null,
    created_by: 1,
    created_at: new Date()
  }
]);

// 3. guests
db.guests.drop();
db.guests.createIndex({ event_id: 1 });
db.guests.insertMany([
  {
    id: 1,
    event_id: 1,
    name: 'Brigadier General Guest',
    designation: 'Guest of Honour',
    organization: 'Bangladesh Armed Forces',
    phone: '01810000001',
    email: 'guest1@example.local',
    category: 'VIP',
    priority_level: 'VIP',
    arrival_status: 'Expected',
    created_at: new Date()
  },
  {
    id: 2,
    event_id: 1,
    name: 'Professor Guest',
    designation: 'Keynote Speaker',
    organization: 'Academic Institution',
    phone: '01810000002',
    email: 'guest2@example.local',
    category: 'Special Guest',
    priority_level: 'High',
    arrival_status: 'Expected',
    created_at: new Date()
  },
  {
    id: 3,
    event_id: 2,
    name: 'Official Delegate',
    designation: 'Delegation Member',
    organization: 'Government Office',
    phone: '01810000003',
    email: 'guest3@example.local',
    category: 'Official',
    priority_level: 'Official',
    arrival_status: 'Expected',
    created_at: new Date()
  }
]);

// 4. duties
db.duties.drop();
db.duties.createIndex({ event_id: 1 });
db.duties.insertMany([
  {
    id: 1,
    event_id: 1,
    title: 'Main Gate Coordination',
    description: 'Coordinate guest entry and reception.',
    assigned_to: 4,
    status: 'Assigned',
    due_at: new Date(now.getTime() + 5 * 86400000),
    created_at: new Date()
  },
  {
    id: 2,
    event_id: 1,
    title: 'VIP Seating Verification',
    description: 'Verify VIP seating plan before arrival.',
    assigned_to: 3,
    status: 'Pending',
    due_at: new Date(now.getTime() + 6 * 86400000),
    created_at: new Date()
  },
  {
    id: 3,
    event_id: 2,
    title: 'Transport Coordination',
    description: 'Confirm vehicle and parking arrangements.',
    assigned_to: 2,
    status: 'Ongoing',
    due_at: new Date(now.getTime() + 12 * 86400000),
    created_at: new Date()
  }
]);

// 5. protocol_items
db.protocol_items.drop();
db.protocol_items.createIndex({ event_id: 1 });
db.protocol_items.insertMany([
  { id: 1, event_id: 1, item: 'Verify VIP seating plan', instructions: 'Confirm seat allocation and reserved sections.', completed: 1, sort_order: 1 },
  { id: 2, event_id: 1, item: 'Prepare protocol reception desk', instructions: 'Ensure guest list and instructions are available.', completed: 1, sort_order: 2 },
  { id: 3, event_id: 1, item: 'Confirm ceremonial sequence', instructions: 'Review official event sequence with responsible team.', completed: 0, sort_order: 3 },
  { id: 4, event_id: 1, item: 'Final venue inspection', instructions: 'Check hall, entry, signage and reserved areas.', completed: 0, sort_order: 4 },
  { id: 5, event_id: 2, item: 'Confirm official guest arrival', instructions: 'Coordinate expected arrival information.', completed: 0, sort_order: 1 }
]);

// 6. attendance
db.attendance.drop();
db.attendance.createIndex({ qr_token: 1 }, { unique: true });
db.attendance.insertMany([
  { id: 1, event_id: 1, guest_id: 1, qr_token: 'ATT-BUP-0001', status: 'Registered', checkin_at: null },
  { id: 2, event_id: 1, guest_id: 2, qr_token: 'ATT-BUP-0002', status: 'Registered', checkin_at: null },
  { id: 3, event_id: 2, guest_id: 3, qr_token: 'ATT-BUP-0003', status: 'Registered', checkin_at: null }
]);

// 7. announcements
db.announcements.drop();
db.announcements.insertMany([
  { id: 1, title: 'Welcome to BUP Protocol', message: 'The event command center is ready. Review upcoming events, duties and protocol checklists from the dashboard.', created_by: 1, created_at: new Date() },
  { id: 2, title: 'Protocol Review Reminder', message: 'All responsible personnel should review event-specific protocol requirements before execution.', created_by: 3, created_at: new Date() }
]);

// 8. transport
db.transport.drop();
db.transport.insertMany([
  { id: 1, event_id: 1, guest_name: 'Brigadier General Guest', vehicle_no: 'ARMY-1234', driver_name: 'Rahim', parking_slot: 'VIP-P1', arrival_expected: null, arrival_actual: null, accommodation: 'BUP VIP Guest House', created_at: new Date() }
]);

// 9. emergency_contacts
db.emergency_contacts.drop();
db.emergency_contacts.insertMany([
  { id: 1, name: 'Emergency Control', designation: 'Emergency Coordination Desk', phone: '999', department: 'Emergency Services', notes: 'Use for urgent emergency response.', created_at: new Date() },
  { id: 2, name: 'Campus Security', designation: 'Security Duty Officer', phone: '01711000000', department: 'Campus Security', notes: 'Primary campus security contact.', created_at: new Date() }
]);

// 10. invitations
db.invitations.drop();
db.invitations.insertMany([
  { id: 1, event_id: 1, guest_name: 'Brigadier General Guest', guest_email: 'guest1@example.local', status: 'Pending', invited_at: new Date(), responded_at: null },
  { id: 2, event_id: 2, guest_name: 'Official Delegate', guest_email: 'guest3@example.local', status: 'Pending', invited_at: new Date(), responded_at: null }
]);

// 11. audit_logs
db.audit_logs.drop();
db.audit_logs.insertMany([
  { id: 1, user_id: 1, action: 'System Initialized', description: 'BUP Event & Military Protocol Management System initialized.', created_at: new Date() },
  { id: 2, user_id: 1, action: 'Sample Data Loaded', description: 'Demo events, guests, duties and protocol checklist created.', created_at: new Date() }
]);

// 12. supplies
db.supplies.drop();
db.supplies.createIndex({ item_code: 1 }, { unique: true });
db.supplies.insertMany([
  { id: 1, item_name: 'VIP Executive Badges & Lanyards', item_code: 'SUP-BADGE-001', category: 'Badges & Credentials', batch_lot_number: 'LOT-2026-A1', serial_number: 'SN-001-500', unit_of_measure: 'pcs', qty_total: 500, qty_available: 450, qty_issued: 50, storage_location: 'Central Logistics Store - Bay 1', reorder_level: 50, assigned_to_department: 'Logistics', status: 'In Stock', notes: 'Golden foil embossed for VIP guests', created_at: new Date() },
  { id: 2, item_name: 'Ceremonial Silk Ribbons (Red/Gold)', item_code: 'SUP-RIBBON-002', category: 'Decor & Ceremonial', batch_lot_number: 'LOT-2026-R2', serial_number: null, unit_of_measure: 'roll', qty_total: 50, qty_available: 42, qty_issued: 8, storage_location: 'Central Logistics Store - Bay 2', reorder_level: 10, assigned_to_department: 'Protocol', status: 'In Stock', notes: 'Used for inauguration stages', created_at: new Date() },
  { id: 3, item_name: 'Wireless UHF Radios & Headsets', item_code: 'SUP-RADIO-003', category: 'Communication Devices', batch_lot_number: 'LOT-COM-88', serial_number: 'RAD-01-30', unit_of_measure: 'set', qty_total: 30, qty_available: 5, qty_issued: 25, storage_location: 'Comms Bunker Room 102', reorder_level: 8, assigned_to_department: 'Security', status: 'Low Stock', notes: 'Reserved for tactical & perimeter security', created_at: new Date() }
]);

// 13. media_documents
db.media_documents.drop();
db.media_documents.insertMany([
  { id: 1, event_id: 1, title: 'BUP Annual Academic Conference Press Kit', category: 'Press Release', description: 'Official press kit and media briefs for national journalists and campus media.', file_path: 'uploads/media/press_kit_2026.pdf', file_size: 2450000, file_type: 'pdf', responsible_staff_id: 4, approval_status: 'Approved', media_task: 'Press Distribution', documentation_status: 'Published', created_at: new Date() },
  { id: 2, event_id: 1, title: 'Inauguration Ceremony High-Res Photography', category: 'Official Photo', description: 'Staged high-resolution VIP and stage coverage photos.', file_path: 'uploads/media/ceremony_hires.jpg', file_size: 5120000, file_type: 'jpg', responsible_staff_id: 4, approval_status: 'Pending', media_task: 'Stage Photography', documentation_status: 'In Progress', created_at: new Date() }
]);

// 14. military_sops
db.military_sops.drop();
db.military_sops.createIndex({ code: 1 }, { unique: true });
db.military_sops.insertMany([
  { id: 1, code: 'SOP-MP-001', title: 'VIP Armed Guard & Escort Protocol', category: 'Security & Escort', version: 'v3.2', applicable_level: 'All Commands', content: 'Standard operating guidelines for VIP perimeter cordoning, motorized escort motorcade positioning, and immediate perimeter reaction drills.', authorized_by: 'BUP Protocol Directorate', status: 'Active' },
  { id: 2, code: 'SOP-MP-002', title: 'Ceremonial Guard of Honour Formation & Inspection', category: 'Ceremonial Drills', version: 'v2.1', applicable_level: 'Brigade, Battalion', content: 'Procedures for formal ceremonial quarter guards, arrival honors, band synchronization, and inspecting officer accompaniment.', authorized_by: 'BUP Protocol Directorate', status: 'Active' },
  { id: 3, code: 'SOP-MP-003', title: 'Tactical Radio Communication & Code Words (Green/Amber/Red)', category: 'Communications', version: 'v4.0', applicable_level: 'All Commands', content: 'Operational radio silence protocols, tactical frequency handover, emergency condition alerts (Condition Green, Amber, Red), and immediate chain escalation.', authorized_by: 'BUP Protocol Directorate', status: 'Active' },
  { id: 4, code: 'SOP-MP-004', title: 'Incident Command Chain & Upward Situation Escalation', category: 'Command & Control', version: 'v1.5', applicable_level: 'Section, Platoon, Company', content: 'Rules for instantaneous subordinate upward escalation to Higher Command, contingency incident reports, and casualty triage reporting.', authorized_by: 'BUP Protocol Directorate', status: 'Active' }
]);

print(`=== Migration Completed Successfully! ===`);
print(`Collections in '${targetDbName}':`);
printjson(db.getCollectionNames());
