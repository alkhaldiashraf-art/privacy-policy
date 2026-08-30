<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\DB;

final class SchemaHealthService {
    /**
     * Hostinger-safe Stage 5 schema check.
     * It intentionally uses direct table/column probes because some
     * shared-hosting DB users cannot query server metadata databases.
     */
    public static function stage5Issues(): array {
        $issues = [];

        $probes = [
            'users.locale' => 'SELECT locale FROM users LIMIT 0',
            'platform_admins' => 'SELECT user_id FROM platform_admins LIMIT 0',
            'referrals.reward_cycle_key' => 'SELECT reward_cycle_key FROM referrals LIMIT 0',
            'subscriptions.cancel_at_period_end' => 'SELECT cancel_at_period_end FROM subscriptions LIMIT 0',
            'billing_transactions' => 'SELECT id FROM billing_transactions LIMIT 0',
            'notifications' => 'SELECT id FROM notifications LIMIT 0',
            'notification_deliveries' => 'SELECT id FROM notification_deliveries LIMIT 0',
            'schema_migrations' => 'SELECT migration_key FROM schema_migrations LIMIT 0',
        ];

        foreach ($probes as $name => $sql) {
            try {
                DB::pdo()->query($sql);
            } catch (\Throwable $e) {
                $issues[] = $name;
            }
        }

        // 5.4 adds the dedicated `ai` evidence source. Shared-hosting accounts may
        // not be allowed to inspect information_schema, so use our own migration ledger.
        try {
            $migration=DB::one('SELECT migration_key FROM schema_migrations WHERE migration_key=? LIMIT 1',['stage5_4_ai_evidence_scan']);
            if(!$migration)$issues[]='stage5_4_ai_evidence_scan';
        } catch (\Throwable $e) {
            $issues[]='stage5_4_ai_evidence_scan';
        }

        try {
            $migration=DB::one('SELECT migration_key FROM schema_migrations WHERE migration_key=? LIMIT 1',['stage5_5_builder_workflow']);
            if(!$migration)$issues[]='stage5_5_builder_workflow';
        } catch (\Throwable $e) {
            $issues[]='stage5_5_builder_workflow';
        }

        return array_values(array_unique($issues));
    }
}
