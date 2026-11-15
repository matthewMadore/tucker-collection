<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Status CHECK constraints mirror the PHP enums, so the database rejects
     * an invalid status even if application code is bypassed.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('buyer_email');
            $table->string('status')->default('pending');
            $table->bigInteger('subtotal_cents');
            $table->bigInteger('shipping_cents')->default(0);
            $table->bigInteger('total_cents');
            $table->char('currency', 3)->default('USD');
            $table->jsonb('shipping_address');
            $table->timestampTz('placed_at')->nullable();
            $table->timestampsTz();
            $table->index('status');
        });

        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amounts_non_negative CHECK (subtotal_cents >= 0 AND shipping_cents >= 0 AND total_cents >= 0)');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_valid CHECK (status IN ('pending', 'paid', 'fulfilled', 'delivered', 'cancelled', 'refunded'))");

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('art_piece_id')->constrained()->restrictOnDelete();
            $table->string('name_snapshot');
            $table->bigInteger('price_cents_snapshot');
            $table->timestampTz('voided_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_price_non_negative CHECK (price_cents_snapshot >= 0)');
        // One-of-a-kind backstop: a piece can be on at most one live (not
        // voided) order item, even if two checkouts race past the app code.
        DB::statement('CREATE UNIQUE INDEX order_items_piece_once_live ON order_items (art_piece_id) WHERE voided_at IS NULL');

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('stripe_payment_intent_id')->unique();
            $table->string('stripe_event_id')->nullable();
            $table->string('status')->default('pending');
            $table->bigInteger('amount_cents');
            $table->char('currency', 3)->default('USD');
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_non_negative CHECK (amount_cents >= 0)');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_valid CHECK (status IN ('pending', 'processing', 'succeeded', 'failed', 'refunded', 'disputed'))");

        // Webhook idempotency ledger: Stripe delivers at least once, so each
        // event id is inserted once and duplicates are skipped.
        Schema::create('stripe_events_processed', function (Blueprint $table) {
            $table->string('stripe_event_id')->primary();
            $table->string('type');
            $table->timestampTz('processed_at')->useCurrent();
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('carrier')->default('ups');
            $table->string('service_level')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('status')->default('pending');
            $table->bigInteger('quoted_rate_cents')->nullable();
            $table->timestampTz('last_carrier_sync_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE shipments ADD CONSTRAINT shipments_status_valid CHECK (status IN ('pending', 'label_created', 'in_transit', 'delivered', 'exception'))");
        DB::statement('ALTER TABLE shipments ADD CONSTRAINT shipments_rate_non_negative CHECK (quoted_rate_cents >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('stripe_events_processed');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
