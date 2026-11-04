<?php
/**
 * MongoDB Real-Time Sync & Integration Helper
 * Ensures all actions (login, registration, duty updates, events)
 * are seamlessly persisted and mirrored into MongoDB 'bup_event_protocol'.
 */
require_once __DIR__ . '/database.php';

if (!function_exists('get_mongodb_uri')) {
    function get_mongodb_uri(): string {
        $envUri = getenv('MONGODB_URI') ?: ($_ENV['MONGODB_URI'] ?? null);
        if ($envUri && trim($envUri) !== '') {
            $uri = trim($envUri);
            if (str_ends_with($uri, '/')) {
                $uri .= 'bup_event_protocol';
            }
            return $uri;
        }
        return 'mongodb://127.0.0.1:27017/bup_event_protocol';
    }
}

if (!function_exists('mongo_exec_eval')) {
    function mongo_exec_eval(string $script): ?string {
        $tmpJs = sys_get_temp_dir() . '/mongo_run_' . uniqid() . '.js';
        file_put_contents($tmpJs, $script);
        $uri = get_mongodb_uri();
        $cmd = 'mongosh "' . addslashes($uri) . '" --quiet "' . str_replace('\\', '/', $tmpJs) . '"';
        $res = @shell_exec($cmd);
        @unlink($tmpJs);
        return $res;
    }
}

if (!function_exists('mongo_sync_table')) {
    function mongo_sync_table(string $table): void {
        try {
            $pdo = db();
            $stmt = $pdo->query("SELECT * FROM " . preg_replace('/[^a-zA-Z0-9_]/', '', $table));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format for Mongo
            $processed = [];
            foreach ($rows as $r) {
                $processed[] = $r;
            }
            $json = json_encode($processed);
            $tmpFile = sys_get_temp_dir() . '/mongo_sync_' . $table . '.json';
            file_put_contents($tmpFile, $json);
            
            $jsScript = "
                const fs = require('fs');
                const data = JSON.parse(fs.readFileSync('" . str_replace('\\', '/', $tmpFile) . "', 'utf8'));
                db.getCollection('" . $table . "').drop();
                if (data.length > 0) {
                    db.getCollection('" . $table . "').insertMany(data);
                }
            ";
            mongo_exec_eval($jsScript);
            @unlink($tmpFile);
        } catch (Throwable $e) {
            // Non-blocking fallback
            error_log("Mongo sync error on table $table: " . $e->getMessage());
        }
    }
}

if (!function_exists('mongo_full_sync')) {
    function mongo_full_sync(): void {
        $tables = [
            'users', 'events', 'guests', 'duties', 'protocol_items', 
            'attendance', 'announcements', 'transport', 'emergency_contacts', 
            'invitations', 'audit_logs', 'supplies', 'media_documents', 'military_sops'
        ];
        foreach ($tables as $t) {
            mongo_sync_table($t);
        }
    }
}

