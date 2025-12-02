
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Daftar Produk</title>
  <style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #ddd; padding: 6px; vertical-align: top; }
    th { background: #f2f2f2; text-align: left; }
  </style>
</head>
<body>
  <h3 style="margin-bottom:10px;">Daftar Produk<</h3>
  <table>
    <thead>
      <tr>
        <th>No</th>
        <th>Produk</th>
        <th>Kategori</th>
        <th>Status</th>
        <th>Varian</th>
        <th>SKU</th>
        <th>Harga</th>
        <th>Stok</th>
        <th>Dibuat</th>
      </tr>
    </thead>
    <tbody>
      @php
        $no = 0;
        $prevProductId = null;
      @endphp
      @foreach($variants as $v)
        @php
          $p = $v->product;
          $cats = $p?->categories?->pluck('name')->implode(', ') ?? '-';

          // Gabungan kombinasi opsi: "Merah / L"
          $combo = '-';
          $ov = $v->relationLoaded('optionValues') ? $v->optionValues : collect();
          if ($ov->count() > 0) {
              // Urutkan berdasarkan product_option_id agar konsisten
              $sorted = $ov->sortBy(fn($x) => $x->product_option_id);
              $combo = $sorted->pluck('option_value')->implode(' / ');
          } elseif (!empty($v->variant_name ?? null)) {
              $combo = $v->variant_name;
          }

          // Penomoran per-produk (hanya saat product_id berubah)
          $noCell = '';
          if ($v->product_id !== $prevProductId) {
              $no++;
              $noCell = $no;
              $prevProductId = $v->product_id;
          }
        @endphp
        <tr>
          <td>{{ $noCell }}</td>
          <td>{{ $p?->name ?? '-' }}</td>
          <td>{{ $cats }}</td>
          <td>{{ $p?->status ?? '-' }}</td>
          <td>{{ $combo }}</td>
          <td>{{ $v->sku ?? '-' }}</td>
          <td>{{ number_format((float)($v->price ?? 0), 0, ',', '.') }}</td>
          <td>{{ (int)($v->stock ?? 0) }}</td>
          <td>{{ optional($p?->created_at)->format('Y-m-d H:i') }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>
</body>
</html>
