<?php

declare(strict_types=1);

use App\Enums\AlertType;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ----------------------------------------------------------------
        // 0. notifications — Laravel's table, backing the in-app bell
        // ----------------------------------------------------------------
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            // jsonb, not Laravel's stock `text`. Filament's bell filters on
            // data->>'format', and Postgres has no ->> operator for text — the
            // topbar 500s on every page with a text column here.
            $table->jsonb('data');
            $table->timestampTz('read_at')->nullable();
            $table->timestamps();

            // The bell queries "unread, newest first, for this user".
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });

        // ----------------------------------------------------------------
        // 1. alert_rules — the lead times a manager owns, not a developer
        // ----------------------------------------------------------------
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('template_key', 100);

            // Fire when the subject is this many days away. 0 = react on the day.
            $table->integer('days_before')->default(0);

            // ADR-012 dials. Null repeat = alert once per subject, ever.
            $table->integer('repeat_every_days')->nullable();
            $table->integer('max_repeats')->nullable();

            $table->jsonb('channels')->default('[]');
            $table->jsonb('recipient_roles')->default('[]');
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'is_active']);
            $table->index('branch_id');
        });

        // One active rule per (type, branch). Partial, so soft-deleted and
        // deactivated rules do not block re-creating the rule they replaced.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX alert_rules_type_branch_active_unique
                ON alert_rules (type, branch_id)
                WHERE is_active = true AND deleted_at IS NULL
        SQL);

        // A global rule has a NULL branch_id, and NULL is never equal to NULL in a
        // unique index — so the index above would happily allow two active global
        // rules of the same type. Guard that case separately.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX alert_rules_type_global_active_unique
                ON alert_rules (type)
                WHERE branch_id IS NULL AND is_active = true AND deleted_at IS NULL
        SQL);

        // ----------------------------------------------------------------
        // 2. notification_logs — the delivery audit trail
        // ----------------------------------------------------------------
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('alert_rule_id')->nullable()->constrained()->nullOnDelete();

            $table->string('channel', 20);
            $table->string('template_key', 100);

            // Who it went to. user_id when the recipient is a system user; the
            // address is kept alongside because it is what the provider actually
            // received, and users change their email.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient');
            $table->string('locale', 5)->default('fr');

            // The subject the alert is about, e.g. a Booking or a CarDocument.
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();

            $table->jsonb('payload')->nullable();
            $table->string('status', 20)->default(NotificationStatus::Queued->value);
            $table->string('provider', 50)->nullable();
            $table->string('provider_message_id')->nullable();
            $table->text('error')->nullable();
            $table->integer('attempts')->default(0);

            // Per-message provider cost in DZD. decimal(18,2) like all money, cast
            // 'decimal:2' to match every other model — never a float.
            $table->decimal('cost', 18, 2)->default(0);

            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestamps();

            $table->index(['related_type', 'related_id']);
            $table->index(['status', 'channel']);
            $table->index('branch_id');
        });

        // The ADR-012 deduplication index. The dedup check filters on exactly this
        // tuple and orders by created_at, so it must be covered — the check runs
        // once per candidate subject per hourly sweep, which is the hot path.
        DB::statement(<<<'SQL'
            CREATE INDEX notification_logs_dedup_idx
                ON notification_logs (template_key, related_type, related_id, channel, created_at DESC)
        SQL);

        // ----------------------------------------------------------------
        // 3. Enum CHECK constraints — varchar + backed enum + check (never native PG enums)
        // ----------------------------------------------------------------
        DB::statement("ALTER TABLE alert_rules ADD CHECK (type IN ('".implode("','", AlertType::values())."'))");
        DB::statement("ALTER TABLE notification_logs ADD CHECK (channel IN ('".implode("','", NotificationChannel::values())."'))");
        DB::statement("ALTER TABLE notification_logs ADD CHECK (status IN ('".implode("','", NotificationStatus::values())."'))");

        DB::statement('ALTER TABLE alert_rules ADD CHECK (days_before >= 0)');
        DB::statement('ALTER TABLE alert_rules ADD CHECK (repeat_every_days IS NULL OR repeat_every_days > 0)');
        DB::statement('ALTER TABLE alert_rules ADD CHECK (max_repeats IS NULL OR max_repeats > 0)');
        DB::statement('ALTER TABLE notification_logs ADD CHECK (attempts >= 0)');
        DB::statement('ALTER TABLE notification_logs ADD CHECK (cost >= 0)');

        // related_type and related_id travel together or not at all — a half-set
        // pair would silently fall out of the dedup check.
        DB::statement(<<<'SQL'
            ALTER TABLE notification_logs ADD CHECK (
                (related_type IS NULL AND related_id IS NULL)
                OR (related_type IS NOT NULL AND related_id IS NOT NULL)
            )
        SQL);

        // ----------------------------------------------------------------
        // 4. Per-user digest preference
        // ----------------------------------------------------------------
        // Opting in swaps immediate emails for one daily summary. The in-app bell
        // is unaffected — a digest is about inbox volume, not about hiding alerts.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notification_digest')->default(false);
            $table->time('notification_digest_at')->default('07:00:00');
        });
    }

    public function down(): void
    {
        // Guarded: dropping a column that is not there aborts the rollback and
        // leaves the migration marked as run, which is a confusing state to debug.
        $columns = array_values(array_filter(
            ['notification_digest', 'notification_digest_at'],
            static fn (string $column): bool => Schema::hasColumn('users', $column),
        ));

        if ($columns !== []) {
            Schema::table('users', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }

        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('alert_rules');
        Schema::dropIfExists('notifications');
    }
};
