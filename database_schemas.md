
##         Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('profile_picture_path')->nullable();
            $table->string('nik')->unique()->nullable();
            $table->enum('status', ['active', 'declining', 'watchlist', 'suspended', 'inactive'])->default('active');
            $table->boolean('is_super_admin')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
            $table->index('status');

##         Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();

##         Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();

##         Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');

##         Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');

##         Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');

##         Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();

##         Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();

##         Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();

##         Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['user_id', 'role_id']);

##         Schema::create('provinces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();

##         Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('province_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->timestamps();

##         Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->timestamps();

##         Schema::create('villages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('district_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->timestamps();

##         Schema::create('segmentations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('image_path')->nullable();
            $table->timestamps();

##             Schema::create('addresses', function (Blueprint $table) {
                $table->id();
                $table->morphs('addressable');
                $table->foreignId('province_id')->constrained('provinces')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('city_id')->constrained('cities')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('district_id')->constrained('districts')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('village_id')->constrained('villages')->cascadeOnUpdate()->restrictOnDelete();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->text('detail')->nullable();
                $table->string('label', 50)->nullable();
                $table->timestamps();
                $table->index(['province_id', 'city_id', 'district_id', 'village_id']);

##         Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
            $table->foreignId('segmentation_id')
            $table->string('name');
            $table->string('slug')
            $table->text('description')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('phone')->nullable();
            $table->json('operational_hours')->nullable();
            $table->enum('status', [
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')
            $table->timestamp('response_at')->nullable();
            $table->string('NPWP')->unique()->nullable();
            $table->string('bank_code')->nullable(); // BCA, BRI (WAJIB untuk Xendit)
            $table->string('bank_account_number')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->decimal('balance_available', 15, 2)->default(0);
            $table->decimal('balance_pending', 15, 2)->default(0);
            $table->timestamp('last_payout_at')->nullable();
            $table->timestamps();

##         Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('image_path')->nullable();
            $table->timestamps();
            $table->index('parent_id');

##         Schema::create('images', function (Blueprint $table)
            $table->id();
            $table->morphs('imageable'); // sudah auto-index imageable_type + imageable_id
            $table->string('image_path');
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('is_cover')->default(false);
            $table->timestamps();
            $table->index(['imageable_type', 'imageable_id', 'is_cover'], 'images_cover_idx');
            $table->index(['imageable_type', 'imageable_id', 'display_order'], 'images_order_idx');

##         Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('min_purchase')->default(1);
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->timestamps();

##         Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedInteger('stock')->default(0);
            $table->string('sku')->nullable()->unique();
            $table->decimal('price', 12, 2)->default(0);
            $table->timestamps();
            $table->index('product_id');

##         Schema::create('product_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('option_name');
            $table->boolean('uses_image')->default(false);
            $table->timestamps();
            $table->unique(['product_id', 'option_name']);

##         Schema::create('product_option_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_option_id')->constrained('product_options')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('option_value');
            $table->string('image_path')->nullable();
            $table->timestamps();
            $table->unique(['product_option_id', 'option_value']);

##         Schema::create('product_variant_option_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')
            $table->foreignId('product_option_value_id')
            $table->timestamps();
            $table->index('product_variant_id', 'pvov_variant_idx');
            $table->index('product_option_value_id', 'pvov_value_idx');
            $table->unique(['product_variant_id', 'product_option_value_id'], 'pvov_unique');

##         Schema::create('categorizables', function (Blueprint $table)
            $table->id();
            $table->foreignId('category_id')
            $table->morphs('categorizable'); // auto-index categorizable_type + categorizable_id
            $table->timestamps();
            $table->unique(['categorizable_id', 'categorizable_type', 'category_id'], 'categorizables_unique');
            $table->index(['categorizable_type', 'categorizable_id', 'category_id'], 'categorizables_morph_idx');

##         Schema::create('community_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('post_title');
            $table->text('post_content');
            $table->string('post_slug')->unique();
            $table->enum('post_status', ['draft', 'published', 'archived'])->default('published');
            $table->unsignedInteger('views_count')->default(0);
            $table->timestamps();
            $table->index('user_id');
            $table->index('post_status');
            $table->index('created_at');
            $table->index('post_title');

##         Schema::create('community_post_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('community_posts')->onDelete('cascade');
            $table->string('post_image_path');
            $table->string('alt_text')->nullable();
            $table->timestamps();
            $table->index('post_id');
            $table->index('created_at');

##         Schema::create('addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')
            $table->string('addon_name', 100);
            $table->timestamps();
            $table->index('merchant_id', 'addons_merchant_idx');

##         Schema::create('addon_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')
            $table->string('addon_group_name', 100);
            $table->enum('selection_type', ['single', 'multiple'])->default('single');
            $table->unsignedTinyInteger('min_selection')->default(0);
            $table->unsignedTinyInteger('max_selection')->nullable();
            $table->timestamps();
            $table->index('product_id', 'addon_groups_product_idx');

##         Schema::create('addon_group_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('addon_group_id')
            $table->foreignId('addon_id')
            $table->decimal('addon_price', 10, 2)->default(0);
            $table->timestamps();
            $table->unique(['addon_group_id', 'addon_id'], 'addon_group_option_unique');
            $table->index('addon_group_id', 'addon_group_options_group_idx');

##         Schema::create('post_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('community_posts')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('parent_id')->nullable()->constrained('post_comments')->onDelete('cascade');
            $table->foreignId('reply_to_user_id')->nullable()->constrained('users')->onDelete('set null'); // NEW
            $table->text('comment_content');
            $table->timestamps();
            $table->index('post_id');
            $table->index('user_id');
            $table->index('parent_id');
            $table->index('created_at');

##         Schema::create('jasas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->onDelete('cascade');
            $table->string('title');
            $table->string('slug')->unique()->nullable();
            $table->text('description')->nullable();
            $table->integer('fixed_price')->default(0);
            $table->integer('base_price')->default(0);
            $table->enum('delivery_type', ['online', 'on-site', 'in-store'])->default('in-store');
            $table->enum('service_type_booking', ['keranjang', 'booking', 'konsultasi'])->default('keranjang');
            $table->string('location_address')->nullable();
            $table->text('special_notes')->nullable();
            $table->string('payment_methods')->nullable(); // contoh: "cod,transfer"
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->string('operating_days')->nullable();   // contoh: "1,2,3,4,5,6,7"
            $table->string('operating_times')->nullable(); // contoh: "08.00,08.30,09.00"
            $table->timestamps();

##         Schema::create('report_reasons', function (Blueprint $table) {
            $table->tinyIncrements('id');
            $table->string('reason_title', 255);
            $table->text('reason_description')->nullable();
            $table->enum('applies_to', ['product', 'service', 'merchant', 'post', 'post_comment', 'user']);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

##         Schema::create('content_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
            $table->unsignedTinyInteger('report_reason_id');
            $table->morphs('reportable');
            $table->text('report_comment')->nullable();
            $table->enum('status', [
            $table->foreignId('reviewed_by')
            $table->text('admin_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('action_taken')->nullable();
            $table->text('original_content')->nullable();
            $table->foreignId('forwarded_to')
            $table->foreignId('forwarded_by')
            $table->timestamp('forwarded_at')->nullable();
            $table->text('forward_message')->nullable();
            $table->timestamps();
            $table->foreign('report_reason_id')
            $table->index('reportable_type');
            $table->index('status');

##         Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('event_name', 255)->unique(); 
            $table->text('event_description')->nullable();
            $table->date('event_start_date');
            $table->date('event_end_date');
            $table->string('banner_img_path');
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            $table->index('status');
            $table->index(['event_start_date', 'event_end_date']);

##         Schema::create('event_merchants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->onDelete('cascade');
            $table->foreignId('event_id');
            $table->enum('status', ['pending', 'accepted', 'cancelled', 'rejected', 'removed'])->default('pending');
            $table->text('removal_reason')->nullable(); 
            $table->foreignId('removed_by')->nullable()->constrained('users')->onDelete('set null'); 
            $table->timestamp('removed_at')->nullable(); 
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['merchant_id', 'event_id']);

##         Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('event_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('voucher_name', 100);
            $table->string('voucher_code', 100);
            $table->enum('voucher_status', ['active', 'inactive'])->default('active');
            $table->boolean('is_secret')->default(false);
            $table->enum('voucher_type', ['percent', 'fixed'])->default('percent');
            $table->text('voucher_description')->nullable();
            $table->date('voucher_start_date');
            $table->date('voucher_end_date');
            $table->decimal('value', 15, 2);
            $table->decimal('max_discount_amount', 15, 2)->nullable();
            $table->decimal('min_purchase_amount', 15, 2)->default(0);
            $table->unsignedSmallInteger('usage_limit_per_user')->default(1);
            $table->unsignedSmallInteger('usage_limit')->nullable();
            $table->timestamps();
            $table->index('voucher_status');
            $table->index('voucher_code');

##         Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('address_id')->nullable()->constrained('addresses')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('voucher_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_type')->nullable();
            $table->string('order_code');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->enum('delivery_type', ['pickup', 'delivery', 'online', 'on-site', 'in-store'])->default('pickup');
            $table->string('payment_method')->nullable();
            $table->string('payment_status')->nullable();
            $table->decimal('delivery_fee_snapshot', 12, 2)->default(0);
            $table->decimal('platform_fee', 12, 2)->default(0);
            $table->decimal('gross_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->enum('status', ['pending', 'accepted', 'rejected', 'on-progress', 'paid', 'delivered', 'undelivered', 'completed', 'cancelled', 'ready_to_pickup', 'unpicked'])->default('pending');
            $table->text('notes')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('on_progress_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('ready_to_pickup_at')->nullable();
            $table->timestamp('unpicked_at')->nullable();
            $table->timestamp('confirm_deadline')->nullable();
            $table->string('user_name_snapshot');
            $table->string('user_phone_snapshot');
            $table->text('address_detail_snapshot');
            $table->string('province_name_snapshot');
            $table->string('city_name_snapshot');
            $table->string('district_name_snapshot');
            $table->string('village_name_snapshot');
            $table->decimal('latitude_snapshot', 10, 7)->nullable();
            $table->decimal('longitude_snapshot', 10, 7)->nullable();
            $table->string('proof_image_path')->nullable();
            $table->string('proof_description')->nullable();
            $table->string('failed_reason')->nullable();
            $table->timestamps();

##         Schema::create('voucher_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('voucher_id')->constrained()->onDelete('cascade');
            $table->foreignId('order_id')->nullable()->constrained()->onDelete('set null');
            $table->decimal('discount_amount', 15, 2);
            $table->timestamps();
            $table->index(['user_id', 'voucher_id']);

##         Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('merchant_id')->constrained()->onDelete('cascade');
            $table->unique(['user_id', 'merchant_id']);
            $table->timestamps();

##         Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')
            $table->morphs('itemable');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->string('itemable_name_snapshot');
            $table->string('product_variant_name_snapshot')->nullable();
            $table->string('image_snapshot_path')->nullable();
            $table->integer('price_snapshot');
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();
            $table->index('product_variant_id');

##         Schema::create('cart_item_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_item_id')
            $table->unsignedBigInteger('addon_group_id');
            $table->unsignedBigInteger('addon_id');
            $table->string('addon_name_snapshot')->nullable();
            $table->integer('addon_price_snapshot');
            $table->timestamps();
            $table->index('addon_group_id');
            $table->index('addon_id');

##         Schema::create('user_activity_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('period', 7);
            $table->unsignedInteger('posts_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedInteger('total_activity')->default(0); 
            $table->decimal('activity_score', 8, 2)->default(0);
            $table->unsignedInteger('population_avg_activity')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'period']);
            $table->index('period');
            $table->index(['user_id', 'period']);

##         Schema::create('admin_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('users')->onDelete('cascade');
            $table->enum('action_type', [
            $table->morphs('target');
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable(); 
            $table->string('status_before')->nullable();
            $table->string('status_after')->nullable();
            $table->timestamps();
            $table->index('action_type');
            $table->index(['admin_id', 'created_at']);

##         Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->morphs('alertable');
            $table->enum('alert_type', [
            $table->enum('priority', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->enum('status', ['pending', 'in_progress', 'resolved', 'dismissed'])->default('pending');
            $table->text('message');
            $table->json('recommended_actions')->nullable(); 
            $table->json('metadata')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'priority']);
            $table->index(['alert_type', 'status']);
            $table->index('assigned_to');

##         Schema::create('user_activity_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->unsignedInteger('posts_30d')->default(0);
            $table->unsignedInteger('comments_30d')->default(0);
            $table->unsignedInteger('orders_30d')->default(0);
            $table->unsignedInteger('total_activity_30d')->default(0);
            $table->unsignedInteger('reports_validated_30d')->default(0);
            $table->unsignedInteger('reports_total')->default(0);
            $table->timestamp('last_login_at')->nullable();
            $table->unsignedInteger('last_login_days')->default(0);
            $table->decimal('activity_score', 8, 2)->default(0);
            $table->boolean('pattern_spam_detected')->default(false);
            $table->timestamps();
            $table->unique('user_id');
            $table->index('activity_score');
            $table->index('reports_validated_30d');

##         Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

##         Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('notification_id')->constrained('notifications')->onDelete('cascade');
            $table->string('channel'); // mail, sms, push
            $table->enum('status', ['pending', 'sent', 'failed', 'bounced'])->default('pending');
            $table->text('provider_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamps();
            $table->index(['notification_id', 'channel']);
            $table->index('status');

##         Schema::create('user_login_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('logged_in_at')->useCurrent()->index();
            $table->timestamps();
            $table->index(['user_id', 'logged_in_at']);

##         Schema::create('product_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name_snapshot');
            $table->string('product_variant_snapshot')->nullable();
            $table->text('sku_snapshot')->nullable();
            $table->text('image_snapshot_path');
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price_snapshot', 12, 2);
            $table->decimal('subtotal_snapshot', 12, 2);
            $table->timestamps();

##         Schema::create('voucher_merchants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->onDelete('cascade');
            $table->foreignId('merchant_id')->constrained()->onDelete('cascade');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->enum('voucher_type', ['percent', 'fixed'])->nullable(); // opsional, jika merchant boleh override
            $table->decimal('discount_value', 15, 2)->nullable(); // nominal/persen diisi merchant
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->unique(['voucher_id', 'merchant_id']);

##         Schema::create('product_order_item_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('addon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('addon_name_snapshot');
            $table->decimal('addon_price_snapshot', 12, 2);
            $table->timestamps();

##         Schema::create('shipping_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('base_cost', 10, 2)->default(0);
            $table->decimal('cost_per_km', 10, 2)->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

##         Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->unique(); // order-123
            $table->string('xendit_invoice_id')->nullable();
            $table->string('xendit_refund_id')->nullable();
            $table->string('invoice_url')->nullable();
            $table->string('payment_method')->nullable(); // VA, QRIS, dll
            $table->timestamp('expired_at')->nullable();
            $table->decimal('amount', 15, 2);
            $table->enum('status', [
            $table->string('refund_status')->nullable()->comment('processing, succeeded, failed, resolved');
            $table->string('refund_destination')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamps();

##         Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->unique();
            $table->decimal('amount', 15, 2);
            $table->string('bank_code');
            $table->string('account_number');
            $table->string('account_name');
            $table->enum('status', [
            $table->text('failure_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamps();

##         Schema::create('merchant_wallet_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->enum('type', [
            $table->decimal('amount', 15, 2);
            $table->string('reference_type'); // order / payout
            $table->unsignedBigInteger('reference_id');
            $table->text('description')->nullable();
            $table->timestamps();

##         Schema::create('voucher_merchant_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->onDelete('cascade');
            $table->foreignId('merchant_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->timestamps();
            $table->unique(['voucher_id', 'merchant_id', 'product_id'], 'vmp_unique');

##         Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('audiences')->nullable();
            $table->string('endpoint', 512)->unique();
            $table->string('p256dh', 255);
            $table->string('auth', 255);
            $table->string('content_encoding', 20)->nullable();
            $table->unsignedBigInteger('expiration_time')->nullable();
            $table->timestamps();

##         Schema::create('payment_fees', function (Blueprint $table) {
            $table->id();
            $table->string('method_code')->unique();
            $table->string('method_name');
            $table->enum('type', ['percentage', 'flat']);
            $table->decimal('value', 10, 2);
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

##         Schema::create('report_appeals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_report_id')->constrained('content_reports')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade'); // user yang mengajukan sanggahan
            $table->text('appeal_text'); // isi sanggahan
            $table->enum('status', ['pending', 'reviewed', 'accepted', 'rejected'])->default('pending');
            $table->text('admin_response')->nullable(); // respons admin
            $table->foreignId('responded_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->index('content_report_id');
            $table->index('user_id');
            $table->index('status');

##         Schema::create('service_consultations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jasa_id')->constrained('jasas')->onDelete('cascade');
            $table->foreignId('customer_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('merchant_id')->constrained('merchants')->onDelete('cascade');
            $table->string('service_name');
            $table->decimal('original_price', 12, 2)->default(0);
            $table->text('customer_description')->nullable();  // Description of needs
            $table->decimal('customer_budget', 12, 2)->nullable();  // Customer's budget
            $table->date('customer_deadline')->nullable();  // Customer's desired deadline
            $table->text('customer_note')->nullable();  // Additional notes
            $table->string('merchant_response', 30)->nullable();  // Available options
            $table->decimal('merchant_offered_price', 12, 2)->nullable();  // Price offered by merchant
            $table->text('merchant_note')->nullable();  // Notes/adjustments from merchant
            $table->decimal('negotiated_price', 12, 2)->nullable();  // Final agreed price
            $table->text('negotiation_notes')->nullable();  // Both parties notes
            $table->date('agreed_deadline')->nullable();  // Agreed deadline
            $table->date('proposed_date')->nullable();
            $table->time('proposed_time')->nullable();
            $table->text('proposed_notes')->nullable();
            $table->string('offer_status', 30)->nullable();
            $table->boolean('customer_accepted')->default(false);
            $table->timestamp('customer_accepted_at')->nullable();
            $table->string('status', 30)->default('pending');
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('order_id')->nullable()
            $table->timestamps();
            $table->index(['customer_id', 'status']);
            $table->index(['merchant_id', 'status']);
            $table->index(['jasa_id', 'status']);
            $table->index(['status']);

##         Schema::create('service_consultation_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_consultation_id')
            $table->string('file_name');  // Original filename
            $table->string('file_path');  // Storage path
            $table->string('file_url')->nullable();  // Full public URL
            $table->string('file_type', 20);  // image | video
            $table->string('mime_type', 100)->nullable();
            $table->bigInteger('file_size')->default(0);
            $table->unsignedTinyInteger('display_order')->default(0);
            $table->timestamps();
            $table->index(['service_consultation_id']);

##         Schema::create('service_consultation_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_consultation_id')
            $table->foreignId('sender_id')->nullable()
            $table->string('sender_type', 20);  // customer | merchant | system
            $table->text('note');
            $table->timestamps();
            $table->index(['service_consultation_id']);

##         Schema::create('consultation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_consultation_id')
            $table->foreignId('sender_id')
            $table->string('sender_type', 20);  // customer | merchant
            $table->text('message')->nullable();  // Text message (optional if media exists)
            $table->string('message_type', 30)->default('text'); // text | proposal | rejection | acceptance
            $table->decimal('proposed_price', 12, 2)->nullable();  // Proposed price if any
            $table->timestamps();
            $table->index(['service_consultation_id', 'created_at']);

##         Schema::create('consultation_message_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_message_id')
            $table->string('file_name');  // Original filename
            $table->string('file_path');  // Storage path
            $table->string('file_url')->nullable();  // Full public URL
            $table->string('file_type', 20);  // image | video
            $table->string('mime_type', 100)->nullable();
            $table->bigInteger('file_size')->default(0);
            $table->unsignedTinyInteger('display_order')->default(0);
            $table->timestamps();
            $table->index(['consultation_message_id']);

##         Schema::create('jasa_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->foreignId('jasa_id')->constrained('jasas')->onDelete('cascade');
            $table->foreignId('service_consultation_id')->nullable()->constrained('service_consultations')->nullOnDelete();
            $table->integer('quantity')->default(1);
            $table->decimal('price', 12, 2);
            $table->decimal('subtotal', 12, 2);
            $table->string('order_method')->default('keranjang'); // keranjang, booking, konsultasi
            $table->date('booking_date')->nullable();
            $table->time('booking_time')->nullable();
            $table->string('jasa_title_snapshot')->nullable();
            $table->text('jasa_image_snapshot')->nullable();
            $table->decimal('jasa_price_snapshot', 12, 2)->nullable();
            $table->timestamps();

##         Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // Pemberi rating
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete(); // UMKM yang diratingkan
            $table->foreignId('order_id')->nullable()->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_order_item_id')->nullable()->constrained('product_order_items')->cascadeOnDelete();
            $table->foreignId('jasa_order_item_id')->nullable()->constrained('jasa_order_items')->cascadeOnDelete();
            $table->morphs('rateable');
            $table->integer('rating')->unsigned(); // 1-5
            $table->string('title')->nullable(); // Judul review
            $table->text('comment')->nullable(); // Komentar review
            $table->boolean('is_anonymous')->default(false);
            $table->text('merchant_reply')->nullable();
            $table->timestamp('merchant_reply_at')->nullable();
            $table->unsignedInteger('update_count')->default(0);
            $table->timestamp('review_updated_at')->nullable();
            $table->timestamps();
            $table->unique(
            $table->unique(['user_id', 'product_order_item_id'], 'ratings_user_product_order_unique');
            $table->unique(['user_id', 'jasa_order_item_id'], 'ratings_user_jasa_order_unique');

##         Schema::create('rating_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->morphs('rateable');
            $table->unsignedBigInteger('summaryable_id')->nullable();
            $table->string('summaryable_type')->nullable();
            $table->decimal('average_rating', 3, 2)->default(0);
            $table->integer('total_ratings')->default(0);
            $table->integer('total_reviews')->default(0);
            $table->integer('rating_5')->default(0);
            $table->integer('rating_4')->default(0);
            $table->integer('rating_3')->default(0);
            $table->integer('rating_2')->default(0);
            $table->integer('rating_1')->default(0);
            $table->integer('rating_5_count')->default(0);
            $table->integer('rating_4_count')->default(0);
            $table->integer('rating_3_count')->default(0);
            $table->integer('rating_2_count')->default(0);
            $table->integer('rating_1_count')->default(0);
            $table->timestamps();
            $table->unique(['merchant_id', 'rateable_id', 'rateable_type']);

##         Schema::create('review_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('ratings')->cascadeOnDelete();
            $table->string('file_path')->nullable();
            $table->string('file_url')->nullable();
            $table->string('file_type')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->string('original_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->integer('display_order')->default(0);
            $table->timestamps();

##         Schema::create('review_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rating_id')->constrained('ratings')->onDelete('cascade');
            $table->unsignedTinyInteger('old_rating')->nullable();
            $table->string('old_title', 80)->nullable();
            $table->text('old_comment')->nullable();
            $table->json('old_media')->nullable(); // Snapshot of old media files
            $table->unsignedTinyInteger('new_rating')->nullable();
            $table->string('new_title', 80)->nullable();
            $table->text('new_comment')->nullable();
            $table->json('new_media')->nullable(); // Snapshot of new media files
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            $table->index(['rating_id', 'created_at']);
