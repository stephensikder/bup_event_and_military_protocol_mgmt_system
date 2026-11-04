const fs = require('fs');
const targetDbName = 'bup_event_protocol';
const db = db.getSiblingDB(targetDbName);

print(`=== Starting Sync of all 14 Collections to MongoDB '${targetDbName}' ===`);

const data = JSON.parse(fs.readFileSync('E:/bup-event-protocol/database/full_mongo_sync.json', 'utf8'));

for (const [table, rows] of Object.entries(data)) {
  const coll = db.getCollection(table);
  coll.drop();
  if (rows && rows.length > 0) {
    // Process dates and numbers where appropriate
    const processed = rows.map(r => {
      const doc = { ...r };
      for (const [k, v] of Object.entries(doc)) {
        if (v === null) continue;
        if (typeof v === 'string' && /^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/.test(v)) {
          doc[k] = new Date(v.replace(' ', 'T') + (v.length === 10 ? 'T00:00:00Z' : 'Z'));
        }
      }
      return doc;
    });
    coll.insertMany(processed);
    print(`- ${table}: ${rows.length} documents inserted.`);
  } else {
    print(`- ${table}: 0 documents (empty).`);
  }
}

// Add primary indexes
db.users.createIndex({ email: 1 }, { unique: true });
db.events.createIndex({ event_date: 1 });
db.events.createIndex({ status: 1 });
db.supplies.createIndex({ item_code: 1 }, { unique: true, sparse: true });
db.military_sops.createIndex({ code: 1 }, { unique: true, sparse: true });

print(`=== MongoDB '${targetDbName}' is now 100% Synced! Collections: ===`);
printjson(db.getCollectionNames());
