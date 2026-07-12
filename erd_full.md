erDiagram
    users {
        integer id
        varchar name
        varchar email
        varchar phone
        varchar profile_picture_path
        varchar nik
        enum status
        boolean is_super_admin
        timestamp email_verified_at
        varchar password
        varchar remember_token
        timestamp created_at
        timestamp updated_at
    }
    password_reset_tokens {
        varchar email
        varchar token
        timestamp created_at
    }
    sessions {
        varchar id
        integer user_id
        varchar ip_address
        varchar user_agent
        varchar payload
        integer last_activity
    }
    cache {
        varchar key
        varchar value
        integer expiration
    }
    cache_locks {
        varchar key
        varchar owner
        integer expiration
    }
    jobs {
        integer id
        varchar queue
        varchar payload
        varchar attempts
        varchar reserved_at
        varchar available_at
        varchar created_at
    }
    job_batches {
        varchar id
        varchar name
        integer total_jobs
        integer pending_jobs
        integer failed_jobs
        varchar failed_job_ids
        varchar options
        integer cancelled_at
        integer created_at
        integer finished_at
    }
    failed_jobs {
        integer id
        varchar uuid
        varchar connection
        varchar queue
        varchar payload
        varchar exception
        timestamp failed_at
    }
    roles {
        integer id
        varchar name
        timestamp created_at
        timestamp updated_at
    }
    role_user {
        integer user_id
        integer role_id
        timestamp created_at
        timestamp updated_at
    }
    provinces {
        integer id
        varchar name
        timestamp created_at
        timestamp updated_at
    }
    cities {
        integer id
        integer province_id
        varchar name
        timestamp created_at
        timestamp updated_at
    }
    districts {
        integer id
        integer city_id
        varchar name
        timestamp created_at
        timestamp updated_at
    }
    villages {
        integer id
        integer district_id
        varchar name
        timestamp created_at
        timestamp updated_at
    }
    segmentations {
        integer id
        varchar name
        varchar image_path
        timestamp created_at
        timestamp updated_at
    }
    addresses {
        integer id
        varchar addressable
        integer province_id
        integer city_id
        integer district_id
        integer village_id
        decimal latitude
        decimal longitude
        varchar detail
        varchar label
        timestamp created_at
        timestamp updated_at
    }
    merchants {
        integer id
        integer user_id
        integer segmentation_id
        varchar name
        varchar slug
        varchar description
        varchar logo_path
        varchar cover_path
        varchar phone
        json operational_hours
        enum status
        varchar rejection_reason
        integer reviewed_by
        timestamp response_at
        varchar NPWP
        varchar bank_code
        varchar bank_account_number
        varchar bank_account_name
        decimal balance_available
        decimal balance_pending
        timestamp last_payout_at
        timestamp created_at
        timestamp updated_at
    }
    categories {
        integer id
        integer parent_id
        varchar name
        varchar slug
        varchar image_path
        timestamp created_at
        timestamp updated_at
    }
    images {
        integer id
        varchar imageable
        varchar image_path
        varchar display_order
        boolean is_cover
        timestamp created_at
        timestamp updated_at
    }
    products {
        integer id
        integer merchant_id
        varchar name
        varchar slug
        varchar description
        varchar min_purchase
        enum status
        timestamp created_at
        timestamp updated_at
    }
    product_variants {
        integer id
        integer product_id
        varchar stock
        varchar sku
        decimal price
        timestamp created_at
        timestamp updated_at
    }
    product_options {
        integer id
        integer product_id
        varchar option_name
        boolean uses_image
        timestamp created_at
        timestamp updated_at
    }
    product_option_values {
        integer id
        integer product_option_id
        varchar option_value
        varchar image_path
        timestamp created_at
        timestamp updated_at
    }
    product_variant_option_values {
        integer id
        integer product_variant_id
        integer product_option_value_id
        timestamp created_at
        timestamp updated_at
    }
    categorizables {
        integer id
        integer category_id
        varchar categorizable
        timestamp created_at
        timestamp updated_at
    }
    community_posts {
        integer id
        integer user_id
        varchar post_title
        varchar post_content
        varchar post_slug
        enum post_status
        varchar views_count
        timestamp created_at
        timestamp updated_at
    }
    community_post_images {
        integer id
        integer post_id
        varchar post_image_path
        varchar alt_text
        timestamp created_at
        timestamp updated_at
    }
    addons {
        integer id
        integer merchant_id
        varchar addon_name
        timestamp created_at
        timestamp updated_at
    }
    addon_groups {
        integer id
        integer product_id
        varchar addon_group_name
        enum selection_type
        varchar min_selection
        varchar max_selection
        timestamp created_at
        timestamp updated_at
    }
    addon_group_options {
        integer id
        integer addon_group_id
        integer addon_id
        decimal addon_price
        timestamp created_at
        timestamp updated_at
    }
    post_comments {
        integer id
        integer post_id
        integer user_id
        integer parent_id
        integer reply_to_user_id
        varchar comment_content
        timestamp created_at
        timestamp updated_at
    }
    jasas {
        integer id
        integer merchant_id
        varchar title
        varchar slug
        varchar description
        integer fixed_price
        integer base_price
        enum delivery_type
        enum service_type_booking
        varchar location_address
        varchar special_notes
        varchar payment_methods
        enum status
        varchar operating_days
        varchar operating_times
        timestamp created_at
        timestamp updated_at
    }
    report_reasons {
        varchar id
        varchar reason_title
        varchar reason_description
        enum applies_to
        boolean is_active
        timestamp created_at
        timestamp updated_at
    }
    content_reports {
        integer id
        integer user_id
        varchar report_reason_id
        varchar reportable
        varchar report_comment
        enum status
        integer reviewed_by
        varchar admin_note
        timestamp reviewed_at
        varchar action_taken
        varchar original_content
        integer forwarded_to
        integer forwarded_by
        timestamp forwarded_at
        varchar forward_message
        timestamp created_at
        timestamp updated_at
    }
    events {
        integer id
        varchar event_name
        varchar event_description
        timestamp event_start_date
        timestamp event_end_date
        varchar banner_img_path
        enum status
        integer created_by
        timestamp created_at
        timestamp updated_at
    }
    event_merchants {
        integer id
        integer merchant_id
        integer event_id
        enum status
        varchar removal_reason
        integer removed_by
        timestamp removed_at
        timestamp responded_at
        timestamp created_at
        timestamp updated_at
    }
    vouchers {
        integer id
        integer merchant_id
        integer event_id
        varchar voucher_name
        varchar voucher_code
        enum voucher_status
        boolean is_secret
        enum voucher_type
        varchar voucher_description
        timestamp voucher_start_date
        timestamp voucher_end_date
        decimal value
        decimal max_discount_amount
        decimal min_purchase_amount
        varchar usage_limit_per_user
        varchar usage_limit
        timestamp created_at
        timestamp updated_at
    }
    orders {
        integer id
        integer address_id
        integer user_id
        integer merchant_id
        integer voucher_id
        varchar order_type
        varchar order_code
        decimal subtotal
        decimal discount_total
        enum delivery_type
        varchar payment_method
        varchar payment_status
        decimal delivery_fee_snapshot
        decimal platform_fee
        decimal gross_amount
        decimal net_amount
        enum status
        varchar notes
        timestamp accepted_at
        timestamp on_progress_at
        timestamp rejected_at
        timestamp paid_at
        timestamp delivered_at
        timestamp completed_at
        timestamp cancelled_at
        timestamp ready_to_pickup_at
        timestamp unpicked_at
        timestamp confirm_deadline
        varchar user_name_snapshot
        varchar user_phone_snapshot
        varchar address_detail_snapshot
        varchar province_name_snapshot
        varchar city_name_snapshot
        varchar district_name_snapshot
        varchar village_name_snapshot
        decimal latitude_snapshot
        decimal longitude_snapshot
        varchar proof_image_path
        varchar proof_description
        varchar failed_reason
        timestamp created_at
        timestamp updated_at
    }
    voucher_usages {
        integer id
        integer user_id
        integer voucher_id
        integer order_id
        decimal discount_amount
        timestamp created_at
        timestamp updated_at
    }
    carts {
        integer id
        integer user_id
        integer merchant_id
        timestamp created_at
        timestamp updated_at
    }
    cart_items {
        integer id
        integer cart_id
        varchar itemable
        varchar product_variant_id
        varchar itemable_name_snapshot
        varchar product_variant_name_snapshot
        varchar image_snapshot_path
        integer price_snapshot
        varchar quantity
        timestamp created_at
        timestamp updated_at
    }
    cart_item_addons {
        integer id
        integer cart_item_id
        varchar addon_group_id
        varchar addon_id
        varchar addon_name_snapshot
        integer addon_price_snapshot
        timestamp created_at
        timestamp updated_at
    }
    user_activity_snapshots {
        integer id
        integer user_id
        varchar period
        varchar posts_count
        varchar comments_count
        varchar orders_count
        varchar total_activity
        decimal activity_score
        varchar population_avg_activity
        timestamp created_at
        timestamp updated_at
    }
    admin_actions {
        integer id
        integer admin_id
        enum action_type
        varchar target
        varchar reason
        json metadata
        varchar status_before
        varchar status_after
        timestamp created_at
        timestamp updated_at
    }
    alerts {
        integer id
        varchar alertable
        enum alert_type
        enum priority
        enum status
        varchar message
        json recommended_actions
        json metadata
        integer assigned_to
        timestamp assigned_at
        timestamp resolved_at
        timestamp created_at
        timestamp updated_at
    }
    user_activity_metrics {
        integer id
        integer user_id
        varchar posts_30d
        varchar comments_30d
        varchar orders_30d
        varchar total_activity_30d
        varchar reports_validated_30d
        varchar reports_total
        timestamp last_login_at
        varchar last_login_days
        decimal activity_score
        boolean pattern_spam_detected
        timestamp created_at
        timestamp updated_at
    }
    notifications {
        varchar id
        varchar type
        varchar notifiable
        varchar data
        timestamp read_at
        timestamp created_at
        timestamp updated_at
    }
    notification_deliveries {
        integer id
        varchar notification_id
        varchar channel
        enum status
        varchar provider_message
        timestamp sent_at
        timestamp failed_at
        integer retry_count
        timestamp created_at
        timestamp updated_at
    }
    user_login_events {
        integer id
        integer user_id
        timestamp logged_in_at
        timestamp created_at
        timestamp updated_at
    }
    product_order_items {
        integer id
        integer order_id
        integer product_id
        integer product_variant_id
        varchar product_name_snapshot
        varchar product_variant_snapshot
        varchar sku_snapshot
        varchar image_snapshot_path
        integer quantity
        decimal unit_price_snapshot
        decimal subtotal_snapshot
        timestamp created_at
        timestamp updated_at
    }
    voucher_merchants {
        integer id
        integer voucher_id
        integer merchant_id
        enum status
        enum voucher_type
        decimal discount_value
        timestamp activated_at
        timestamp created_at
        timestamp updated_at
    }
    product_order_item_addons {
        integer id
        integer product_order_item_id
        integer addon_id
        varchar addon_name_snapshot
        decimal addon_price_snapshot
        timestamp created_at
        timestamp updated_at
    }
    shipping_settings {
        integer id
        decimal base_cost
        decimal cost_per_km
        enum status
        timestamp created_at
        timestamp updated_at
    }
    payments {
        integer id
        integer order_id
        varchar external_id
        varchar xendit_invoice_id
        varchar xendit_refund_id
        varchar invoice_url
        varchar payment_method
        timestamp expired_at
        decimal amount
        enum status
        varchar refund_status
        varchar refund_destination
        timestamp paid_at
        json raw_response
        timestamp created_at
        timestamp updated_at
    }
    payouts {
        integer id
        integer merchant_id
        varchar external_id
        decimal amount
        varchar bank_code
        varchar account_number
        varchar account_name
        enum status
        varchar failure_reason
        timestamp processed_at
        json raw_response
        timestamp created_at
        timestamp updated_at
    }
    merchant_wallet_histories {
        integer id
        integer merchant_id
        enum type
        decimal amount
        varchar reference_type
        varchar reference_id
        varchar description
        timestamp created_at
        timestamp updated_at
    }
    voucher_merchant_products {
        integer id
        integer voucher_id
        integer merchant_id
        integer product_id
        timestamp created_at
        timestamp updated_at
    }
    push_subscriptions {
        integer id
        integer user_id
        json audiences
        varchar endpoint
        varchar p256dh
        varchar auth
        varchar content_encoding
        varchar expiration_time
        timestamp created_at
        timestamp updated_at
    }
    payment_fees {
        integer id
        varchar method_code
        varchar method_name
        enum type
        decimal value
        varchar description
        boolean is_active
        timestamp created_at
        timestamp updated_at
    }
    report_appeals {
        integer id
        integer content_report_id
        integer user_id
        varchar appeal_text
        enum status
        varchar admin_response
        integer responded_by
        timestamp responded_at
        timestamp created_at
        timestamp updated_at
    }
    service_consultations {
        integer id
        integer jasa_id
        integer customer_id
        integer merchant_id
        varchar service_name
        decimal original_price
        varchar customer_description
        decimal customer_budget
        timestamp customer_deadline
        varchar customer_note
        varchar merchant_response
        decimal merchant_offered_price
        varchar merchant_note
        decimal negotiated_price
        varchar negotiation_notes
        timestamp agreed_deadline
        timestamp proposed_date
        varchar proposed_time
        varchar proposed_notes
        varchar offer_status
        boolean customer_accepted
        timestamp customer_accepted_at
        varchar status
        timestamp responded_at
        timestamp closed_at
        integer order_id
        timestamp created_at
        timestamp updated_at
    }
    service_consultation_media {
        integer id
        integer service_consultation_id
        varchar file_name
        varchar file_path
        varchar file_url
        varchar file_type
        varchar mime_type
        varchar file_size
        varchar display_order
        timestamp created_at
        timestamp updated_at
    }
    service_consultation_notes {
        integer id
        integer service_consultation_id
        integer sender_id
        varchar sender_type
        varchar note
        timestamp created_at
        timestamp updated_at
    }
    service_completion_evidences {
        integer id
        integer order_id
        varchar file_name
        varchar file_path
        varchar file_url
        varchar file_type
        varchar mime_type
        varchar file_size
        varchar display_order
        timestamp created_at
        timestamp updated_at
    }
    consultation_messages {
        integer id
        integer service_consultation_id
        integer sender_id
        varchar sender_type
        varchar message
        varchar message_type
        decimal proposed_price
        timestamp created_at
        timestamp updated_at
    }
    consultation_message_media {
        integer id
        integer consultation_message_id
        varchar file_name
        varchar file_path
        varchar file_url
        varchar file_type
        varchar mime_type
        varchar file_size
        varchar display_order
        timestamp created_at
        timestamp updated_at
    }
    jasa_order_items {
        integer id
        integer order_id
        integer jasa_id
        integer service_consultation_id
        integer quantity
        decimal price
        decimal subtotal
        varchar order_method
        timestamp booking_date
        varchar booking_time
        varchar jasa_title_snapshot
        varchar jasa_image_snapshot
        decimal jasa_price_snapshot
        timestamp created_at
        timestamp updated_at
    }
    ratings {
        integer id
        integer user_id
        integer merchant_id
        integer order_id
        integer product_order_item_id
        integer jasa_order_item_id
        varchar rateable
        integer rating
        varchar title
        varchar comment
        boolean is_anonymous
        varchar merchant_reply
        timestamp merchant_reply_at
        varchar update_count
        timestamp review_updated_at
        timestamp created_at
        timestamp updated_at
    }
    rating_summaries {
        integer id
        integer merchant_id
        varchar rateable
        varchar summaryable_id
        varchar summaryable_type
        decimal average_rating
        integer total_ratings
        integer total_reviews
        integer rating_5
        integer rating_4
        integer rating_3
        integer rating_2
        integer rating_1
        integer rating_5_count
        integer rating_4_count
        integer rating_3_count
        integer rating_2_count
        integer rating_1_count
        timestamp created_at
        timestamp updated_at
    }
    review_media {
        integer id
        integer review_id
        varchar file_path
        varchar file_url
        varchar file_type
        varchar mime_type
        varchar original_name
        varchar file_size
        integer display_order
        timestamp created_at
        timestamp updated_at
    }
    review_histories {
        integer id
        integer rating_id
        varchar old_rating
        varchar old_title
        varchar old_comment
        json old_media
        varchar new_rating
        varchar new_title
        varchar new_comment
        json new_media
        integer updated_by
        timestamp created_at
        timestamp updated_at
    }
    users ||--o{ sessions : "id-user_id"
    users ||--o{ role_user : "id-user_id"
    roles ||--o{ role_user : "id-role_id"
    provinces ||--o{ cities : "id-province_id"
    cities ||--o{ districts : "id-city_id"
    districts ||--o{ villages : "id-district_id"
    provinces ||--o{ addresses : "id-province_id"
    cities ||--o{ addresses : "id-city_id"
    districts ||--o{ addresses : "id-district_id"
    villages ||--o{ addresses : "id-village_id"
    users ||--o{ merchants : "id-user_id"
    segmentations ||--o{ merchants : "id-segmentation_id"
    users ||--o{ merchants : "id-reviewed_by"
    categories ||--o{ categories : "id-parent_id"
    merchants ||--o{ products : "id-merchant_id"
    products ||--o{ product_variants : "id-product_id"
    products ||--o{ product_options : "id-product_id"
    product_options ||--o{ product_option_values : "id-product_option_id"
    product_variants ||--o{ product_variant_option_values : "id-product_variant_id"
    product_option_values ||--o{ product_variant_option_values : "id-product_option_value_id"
    categories ||--o{ categorizables : "id-category_id"
    users ||--o{ community_posts : "id-user_id"
    community_posts ||--o{ community_post_images : "id-post_id"
    merchants ||--o{ addons : "id-merchant_id"
    products ||--o{ addon_groups : "id-product_id"
    addon_groups ||--o{ addon_group_options : "id-addon_group_id"
    addons ||--o{ addon_group_options : "id-addon_id"
    community_posts ||--o{ post_comments : "id-post_id"
    users ||--o{ post_comments : "id-user_id"
    post_comments ||--o{ post_comments : "id-parent_id"
    users ||--o{ post_comments : "id-reply_to_user_id"
    merchants ||--o{ jasas : "id-merchant_id"
    users ||--o{ content_reports : "id-user_id"
    users ||--o{ content_reports : "id-reviewed_by"
    users ||--o{ content_reports : "id-forwarded_to"
    users ||--o{ content_reports : "id-forwarded_by"
    users ||--o{ events : "id-created_by"
    merchants ||--o{ event_merchants : "id-merchant_id"
    events ||--o{ event_merchants : "id-event_id"
    users ||--o{ event_merchants : "id-removed_by"
    merchants ||--o{ vouchers : "id-merchant_id"
    events ||--o{ vouchers : "id-event_id"
    addresses ||--o{ orders : "id-address_id"
    users ||--o{ orders : "id-user_id"
    merchants ||--o{ orders : "id-merchant_id"
    vouchers ||--o{ orders : "id-voucher_id"
    users ||--o{ voucher_usages : "id-user_id"
    vouchers ||--o{ voucher_usages : "id-voucher_id"
    orders ||--o{ voucher_usages : "id-order_id"
    users ||--o{ carts : "id-user_id"
    merchants ||--o{ carts : "id-merchant_id"
    carts ||--o{ cart_items : "id-cart_id"
    cart_items ||--o{ cart_item_addons : "id-cart_item_id"
    users ||--o{ user_activity_snapshots : "id-user_id"
    users ||--o{ admin_actions : "id-admin_id"
    users ||--o{ alerts : "id-assigned_to"
    users ||--o{ user_activity_metrics : "id-user_id"
    notifications ||--o{ notification_deliveries : "id-notification_id"
    users ||--o{ user_login_events : "id-user_id"
    orders ||--o{ product_order_items : "id-order_id"
    products ||--o{ product_order_items : "id-product_id"
    product_variants ||--o{ product_order_items : "id-product_variant_id"
    vouchers ||--o{ voucher_merchants : "id-voucher_id"
    merchants ||--o{ voucher_merchants : "id-merchant_id"
    product_order_items ||--o{ product_order_item_addons : "id-product_order_item_id"
    addons ||--o{ product_order_item_addons : "id-addon_id"
    orders ||--o{ payments : "id-order_id"
    merchants ||--o{ payouts : "id-merchant_id"
    merchants ||--o{ merchant_wallet_histories : "id-merchant_id"
    vouchers ||--o{ voucher_merchant_products : "id-voucher_id"
    merchants ||--o{ voucher_merchant_products : "id-merchant_id"
    products ||--o{ voucher_merchant_products : "id-product_id"
    users ||--o{ push_subscriptions : "id-user_id"
    content_reports ||--o{ report_appeals : "id-content_report_id"
    users ||--o{ report_appeals : "id-user_id"
    users ||--o{ report_appeals : "id-responded_by"
    jasas ||--o{ service_consultations : "id-jasa_id"
    users ||--o{ service_consultations : "id-customer_id"
    merchants ||--o{ service_consultations : "id-merchant_id"
    orders ||--o{ service_consultations : "id-order_id"
    service_consultations ||--o{ service_consultation_media : "id-service_consultation_id"
    service_consultations ||--o{ service_consultation_notes : "id-service_consultation_id"
    users ||--o{ service_consultation_notes : "id-sender_id"
    orders ||--o{ service_completion_evidences : "id-order_id"
    service_consultations ||--o{ consultation_messages : "id-service_consultation_id"
    users ||--o{ consultation_messages : "id-sender_id"
    consultation_messages ||--o{ consultation_message_media : "id-consultation_message_id"
    orders ||--o{ jasa_order_items : "id-order_id"
    jasas ||--o{ jasa_order_items : "id-jasa_id"
    service_consultations ||--o{ jasa_order_items : "id-service_consultation_id"
    users ||--o{ ratings : "id-user_id"
    merchants ||--o{ ratings : "id-merchant_id"
    orders ||--o{ ratings : "id-order_id"
    product_order_items ||--o{ ratings : "id-product_order_item_id"
    jasa_order_items ||--o{ ratings : "id-jasa_order_item_id"
    merchants ||--o{ rating_summaries : "id-merchant_id"
    ratings ||--o{ review_media : "id-review_id"
    ratings ||--o{ review_histories : "id-rating_id"
    users ||--o{ review_histories : "id-updated_by"
