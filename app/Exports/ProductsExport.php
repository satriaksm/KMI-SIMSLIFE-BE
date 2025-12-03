<?php

namespace App\Exports;

use App\Models\Product;
use App\Models\ProductVariant;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class ProductsExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize, WithColumnFormatting
{
    use Exportable;

    protected int $merchantId;
    protected array $filters;

    // ✅ Penomoran per-produk
    private ?int $lastProductId = null;
    private int $productNo = 0;

    public function __construct(int $merchantId, array $filters = [])
    {
        $this->merchantId = $merchantId;
        $this->filters = $filters;
    }

    public function query()
    {
        $productsTable = (new Product)->getTable();

        $q = ProductVariant::query()
            ->select([
                'product_variants.id',
                'product_variants.product_id',
                'product_variants.sku',
                'product_variants.price',
                'product_variants.stock',
            ])
            ->with([
                // Produk & kategori
                'product' => function ($p) {
                    $p->select('id', 'merchant_id', 'name', 'status', 'created_at');
                },
                'product.categories:id,name',
                // Opsi varian untuk gabungan kombinasi
                'optionValues.option:id,option_name',
            ])
            ->whereHas('product', function ($p) {
                $p->where('merchant_id', $this->merchantId);
            })
            // join ke products agar bisa sort pakai kolom produk
            ->join($productsTable, $productsTable . '.id', '=', 'product_variants.product_id')
            ->addSelect('product_variants.*');

        $data = $this->filters;

        // Search
        if (!empty($data['q'])) {
            $term = trim($data['q']);
            $q->where(function ($w) use ($term, $productsTable) {
                $w->where('product_variants.sku', 'like', "%{$term}%")
                    ->orWhere('product_variants.name', 'like', "%{$term}%")
                    ->orWhere($productsTable . '.name', 'like', "%{$term}%")
                    ->orWhere($productsTable . '.slug', 'like', "%{$term}%")
                    ->orWhere($productsTable . '.description', 'like', "%{$term}%");
            });
        }

        // Filters
        if (!empty($data['status'])) {
            $q->where($productsTable . '.status', $data['status']);
        }

        if (!empty($data['category_id'])) {
            $q->whereHas('product.categories', fn($wc) => $wc->where('categories.id', $data['category_id']));
        }

        if (isset($data['min_price']) || isset($data['max_price'])) {
            $min = $data['min_price'] ?? 0;
            $max = $data['max_price'] ?? PHP_INT_MAX;
            $q->whereBetween('product_variants.price', [$min, $max]);
        }

        if (isset($data['min_stock']) || isset($data['max_stock'])) {
            $minS = $data['min_stock'] ?? 0;
            $maxS = $data['max_stock'] ?? PHP_INT_MAX;
            $q->whereBetween('product_variants.stock', [$minS, $maxS]);
        }

        // ✅ Sorting: dikelompokkan per-produk (pakai subquery agregat untuk price/stock)
        switch ($data['sort_by'] ?? 'newest') {
            case 'oldest':
                $q->orderBy($productsTable . '.created_at', 'asc');
                break;
            case 'name_asc':
                $q->orderBy($productsTable . '.name', 'asc');
                break;
            case 'name_desc':
                $q->orderBy($productsTable . '.name', 'desc');
                break;
            case 'price_asc':
                $q->orderByRaw('COALESCE((SELECT MIN(price) FROM product_variants pv WHERE pv.product_id = ' . $productsTable . '.id), 0) ASC');
                break;
            case 'price_desc':
                $q->orderByRaw('COALESCE((SELECT MAX(price) FROM product_variants pv WHERE pv.product_id = ' . $productsTable . '.id), 0) DESC');
                break;
            case 'stock_asc':
                $q->orderByRaw('COALESCE((SELECT SUM(stock) FROM product_variants pv WHERE pv.product_id = ' . $productsTable . '.id), 0) ASC');
                break;
            case 'stock_desc':
                $q->orderByRaw('COALESCE((SELECT SUM(stock) FROM product_variants pv WHERE pv.product_id = ' . $productsTable . '.id), 0) DESC');
                break;
            case 'newest':
            default:
                $q->orderBy($productsTable . '.created_at', 'desc');
                break;
        }

        // Urutan varian dalam satu produk (tambahan)
        $q->orderBy('product_variants.price', 'asc')->orderBy('product_variants.id', 'asc');

        return $q;
    }

    public function headings(): array
    {
        return ['No', 'Produk', 'Kategori', 'Status', 'Varian', 'SKU', 'Harga', 'Stok', 'Dibuat'];
    }

    public function map($variant): array
    {
        // Penomoran hanya saat product_id berubah
        $numberCell = '';
        if ($variant->product_id !== $this->lastProductId) {
            $this->productNo++;
            $this->lastProductId = $variant->product_id;
            $numberCell = $this->productNo;
        }

        $product = $variant->product;
        $cats = $product?->categories?->pluck('name')->implode(', ') ?? '-';

        // Gabungan nama opsi kombinasi (contoh: "Merah / L")
        $combo = '-';
        if ($variant->relationLoaded('optionValues') && $variant->optionValues->count() > 0) {
            // Urutkan berdasarkan option id agar konsisten
            $sorted = $variant->optionValues->sortBy(fn($ov) => $ov->product_option_id);
            $combo = $sorted->pluck('option_value')->implode(' / ');
        } elseif (!empty($variant->variant_name ?? null)) {
            $combo = $variant->variant_name;
        }

        return [
            $numberCell,                                   // No (hanya baris pertama per-produk)
            $product?->name ?? '-',                        // Produk
            $cats,                                         // Kategori
            $product?->status ?? '-',                      // Status
            $combo,                                        // Varian (gabungan opsi)
            $variant->sku ?? '-',                          // SKU
            (float) $variant->price,                       // Harga
            (int) ($variant->stock ?? 0),                  // Stok
            optional($product?->created_at)->format('Y-m-d H:i'), // Dibuat
        ];
    }

    public function columnFormats(): array
    {
        return [
            'G' => NumberFormat::FORMAT_NUMBER, // Harga
            'H' => NumberFormat::FORMAT_NUMBER, // Stok
        ];
    }
}
