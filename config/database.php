<?php
/**
 * Legacy Database Connector - DECOMMISSIONED
 *
 * The BUP Event & Military Protocol Management System has permanently migrated
 * from MySQL/PDO to MongoDB Atlas ('bup_event_protocol').
 * All database operations are natively handled via config/mongo.php.
 */

if (!function_exists('db')) {
    function db(): never {
        throw new \RuntimeException(
            'MySQL/PDO database layer has been permanently decommissioned. All operations now run on MongoDB Atlas via config/mongo.php.'
        );
    }
}
