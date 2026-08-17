<?php

namespace App\Services\Moderation;

use App\Models\ContentReport;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

class ContentModerationService
{
    public const PRODUCT_ARCHIVE_ACTIONS = ['archive_product', 'product_archived'];

    public function activeProductSanctionQuery(int $productId): Builder
    {
        return ContentReport::query()
            ->whereIn('reportable_type', [
                Product::class,
                (new Product())->getMorphClass(),
            ])
            ->where('reportable_id', $productId)
            ->whereIn('action_taken', self::PRODUCT_ARCHIVE_ACTIONS)
            ->whereNotIn('status', ['dismissed'])
            ->whereDoesntHave('appeals', function ($q) {
                $q->where('status', 'accepted');
            });
    }

    public function getActiveProductSanctionReport(Product $product): ?ContentReport
    {
        return $this->activeProductSanctionQuery($product->id)
            ->latest('reviewed_at')
            ->latest('id')
            ->first();
    }

    public function hasActiveProductSanction(Product $product): bool
    {
        return $this->activeProductSanctionQuery($product->id)->exists();
    }

    public function productModerationBlockMeta(Product $product): ?array
    {
        $report = $this->getActiveProductSanctionReport($product);
        if (!$report) {
            return null;
        }

        $hasPendingAppeal = $report->appeals()->where('status', 'pending')->exists();
        $hasExistingAppeal = $report->appeals()->exists();

        return [
            'blocked' => true,
            'report_id' => $report->id,
            'admin_note' => $report->admin_note,
            'action_taken' => $report->action_taken,
            'can_appeal' => !$hasExistingAppeal,
            'has_pending_appeal' => $hasPendingAppeal,
        ];
    }

    public function productPublishBlockedMessage(): string
    {
        return 'Anda tidak dapat mempublish produk ini karena terkena pelanggaran. Ajukan sanggahan jika Anda merasa tindakan ini tidak tepat.';
    }
}
