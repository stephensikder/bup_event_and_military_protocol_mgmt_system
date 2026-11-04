<?php
/**
 * MongoDB Atlas Architecture & Integration Helper
 * Exclusively powers the BUP Event & Military Protocol Management System.
 * Database: bup_event_protocol
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Helper to safely load .env configuration if present
if (!function_exists('mongo_load_env')) {
    function mongo_load_env(): void {
        static $loaded = false;
        if ($loaded) return;
        $loaded = true;

        $envFile = dirname(__DIR__) . '/.env';
        if (file_exists($envFile) && is_readable($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) continue;
                if (str_contains($line, '=')) {
                    [$name, $value] = explode('=', $line, 2);
                    $name = trim($name);
                    $value = trim($value, " \t\n\r\0\x0B\"'");
                    if (!isset($_SERVER[$name]) && !isset($_ENV[$name]) && getenv($name) === false) {
                        putenv("{$name}={$value}");
                        $_ENV[$name] = $value;
                        $_SERVER[$name] = $value;
                    }
                }
            }
        }
    }
}

// Retrieve MongoDB URI securely without exposing credentials
if (!function_exists('get_mongodb_uri')) {
    function get_mongodb_uri(): string {
        mongo_load_env();
        $envUri = getenv('MONGODB_URI') ?: ($_ENV['MONGODB_URI'] ?? ($_SERVER['MONGODB_URI'] ?? null));
        if ($envUri && trim($envUri) !== '') {
            $uri = trim($envUri);
            if (str_ends_with($uri, '/')) {
                $uri .= 'bup_event_protocol';
            }
            return $uri;
        }

        // Require MONGODB_URI via environment or local .env
        throw new \RuntimeException(
            'CRITICAL: MongoDB connection URI is not configured. Please define MONGODB_URI in your environment or .env file.'
        );
    }
}

// Mask sensitive credentials in URI for safe error logging
if (!function_exists('mongo_mask_uri')) {
    function mongo_mask_uri(string $uri): string {
        return preg_replace('/(mongodb(\+srv)?:\/\/)([^:]+):([^@]+)@/i', '$1$3:***@', $uri);
    }
}

// MongoDB Database Singleton
if (!function_exists('mdb')) {
    function mdb(): MongoDB\Database {
        static $database = null;
        if ($database instanceof MongoDB\Database) {
            return $database;
        }

        $uri = get_mongodb_uri();
        try {
            $client = new MongoDB\Client($uri, [
                'readPreference' => 'primary',
                'retryWrites' => true,
                'w' => 'majority',
                'serverSelectionTimeoutMS' => 5000
            ]);
            $database = $client->selectDatabase('bup_event_protocol');
            return $database;
        } catch (\Throwable $e) {
            $safeUri = mongo_mask_uri($uri);
            error_log("CRITICAL: MongoDB Atlas connection failed to [{$safeUri}]: " . $e->getMessage());
            die('<div style="font-family:sans-serif; padding:40px; text-align:center; color:#1e293b;">
                <h2 style="color:#dc2626;">System Database Notice</h2>
                <p>Unable to establish connection to the primary MongoDB Atlas cluster.</p>
                <p style="font-size:13px; color:#64748b;">Please verify your network access or ensure <code>MONGODB_URI</code> is correctly configured in your deployment environment.</p>
            </div>');
        }
    }
}

// Select MongoDB Collection
if (!function_exists('mcoll')) {
    function mcoll(string $name): MongoDB\Collection {
        return mdb()->selectCollection($name);
    }
}

// Atomic Sequence Counter for Integer IDs
if (!function_exists('mongo_next_id')) {
    function mongo_next_id(string $sequenceName): int {
        $counters = mcoll('counters');
        $res = $counters->findOneAndUpdate(
            ['_id' => $sequenceName],
            ['$inc' => ['seq' => 1]],
            [
                'upsert' => true,
                'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER
            ]
        );
        return (int)($res['seq'] ?? 1);
    }
}

// Convert MongoDB BSON Document to Standard PHP Associative Array
if (!function_exists('mongo_doc_to_array')) {
    function mongo_doc_to_array($doc): ?array {
        if ($doc === null) return null;
        $arr = (array)$doc;
        if (isset($arr['_id']) && $arr['_id'] instanceof MongoDB\BSON\ObjectId) {
            $arr['_id'] = (string)$arr['_id'];
        }
        foreach ($arr as $k => $v) {
            if ($v instanceof MongoDB\BSON\UTCDateTime) {
                $tz = defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Dhaka';
                $arr[$k] = $v->toDateTime()->setTimezone(new DateTimeZone($tz))->format('Y-m-d H:i:s');
            } elseif ($v instanceof MongoDB\Model\BSONDocument || $v instanceof MongoDB\Model\BSONArray || is_object($v)) {
                $arr[$k] = mongo_doc_to_array($v);
            }
        }
        return $arr;
    }
}

// Atomic Audit Log to MongoDB Atlas
if (!function_exists('mongo_audit_log')) {
    function mongo_audit_log($userIdOrAction, string $actionOrDetails = '', string $details = ''): void {
        try {
            if (is_string($userIdOrAction)) {
                $userId = (function_exists('current_user') && current_user()) ? (int)(current_user()['id'] ?? null) : null;
                $action = $userIdOrAction;
                $description = $actionOrDetails;
            } else {
                $userId = $userIdOrAction !== null ? (int)$userIdOrAction : null;
                $action = $actionOrDetails;
                $description = $details;
            }
            $id = mongo_next_id('audit_logs');
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            mcoll('audit_logs')->insertOne([
                'id' => $id,
                'user_id' => $userId,
                'action' => $action,
                'description' => $description,
                'details' => $description,
                'ip_address' => $ip,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (\Throwable $e) {
            error_log('Failed to write mongo audit log: ' . $e->getMessage());
        }
    }
}

// Backward Compatibility No-Op Stubs (Legacy Reverse-Sync Decommissioned)
if (!function_exists('mongo_sync_table')) {
    function mongo_sync_table(string $table): void {
        // No-op: The application writes directly to MongoDB Atlas exclusively.
    }
}

if (!function_exists('mongo_full_sync')) {
    function mongo_full_sync(): void {
        // No-op: Full database lifecycle operates on MongoDB Atlas.
    }
}
