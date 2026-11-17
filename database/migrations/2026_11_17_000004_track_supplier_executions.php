<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_sync_runs', function (Blueprint $table): void {
            $table->string('path', 200)->nullable()->change();
            $table->char('sha256', 64)->nullable()->change();
            $table->string('daily_key', 10)->nullable()->unique();
            $table->string('phase', 24)->nullable();
            $table->string('error_code', 40)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedTinyInteger('download_attempts')->default(0);
            $table->json('differences')->nullable();
        });
        Schema::table('commerce_locks', function (Blueprint $table): void {
            $table->uuid('sync_uuid')->nullable();
            $table->timestamp('sync_expires_at')->nullable();
        });
        Schema::create('supplier_sync_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_sync_run_id')->constrained()->restrictOnDelete();
            $table->string('event', 32);
            $table->text('text');
            $table->string('status', 24)->default('pending');
            $table->foreignId('outbox_message_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['supplier_sync_run_id', 'event'], 'supplier_notification_event_unique');
        });
    }

    public function down(): void
    {
        // Operational history is intentionally retained; rollback requires a reviewed forward migration.
        throw new LogicException('Esta migración conserva historia operativa; no admite rollback destructivo.');
    }
};
