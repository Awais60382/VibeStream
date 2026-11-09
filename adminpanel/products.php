<?php
require_once __DIR__ . '/admin-bootstrap.php';

$catalogPath = __DIR__ . '/../products.json';
$catalog = [];
$catalogError = '';
try {
    $catalogContents = file_get_contents($catalogPath);
    if ($catalogContents === false) {
        throw new RuntimeException('The product catalog could not be read.');
    }
    $catalogData = json_decode($catalogContents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($catalogData)) {
        throw new RuntimeException('The product catalog must contain a list.');
    }
    foreach ($catalogData as $product) {
        if (!is_array($product) || !isset($product['name'], $product['brand'], $product['cat'], $product['price'])) {
            throw new RuntimeException('The product catalog contains an incomplete product.');
        }
    }
    $catalog = $catalogData;
} catch (Throwable $error) {
    error_log('Admin product catalog could not be loaded: ' . $error->getMessage());
    $catalogError = 'The product catalog could not be loaded. Please check products.json.';
    http_response_code(500);
}

$query = trim((string)($_GET['q'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));
$categories = array_values(array_unique(array_map(
    static fn(array $product): string => (string)$product['cat'],
    $catalog
)));
$products = array_values(array_filter($catalog, static function (array $product) use ($query, $category): bool {
    $matchesCategory = $category === '' || strcasecmp((string)$product['cat'], $category) === 0;
    $haystack = implode(' ', [
        (string)$product['name'],
        (string)$product['brand'],
        (string)$product['cat'],
        (string)($product['badge'] ?? ''),
    ]);
    return $matchesCategory && ($query === '' || mb_stripos($haystack, $query, 0, 'UTF-8') !== false);
}));

$vsProductEscape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$vsProductMoney = static fn($value): string => '$' . number_format((float)$value, 2);

require_once __DIR__ . '/header.php';
?>
<style>
  .admin-products { max-width: 1440px; margin: 0 auto; }
  .admin-products-head { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; margin-bottom:1.25rem; }
  .admin-products-head h1 { margin:0; font-size:clamp(1.5rem,3vw,2rem); font-weight:750; color:var(--nx-text,#eaf0ff); }
  .admin-products-head p { margin:.35rem 0 0; color:var(--nx-muted,#8f9bd0); }
  .admin-products-count { color:var(--nx-cyan-ink,#00e5ff); font-weight:700; white-space:nowrap; }
  .admin-products-tools { display:flex; flex-wrap:wrap; gap:.75rem; margin-bottom:1rem; }
  .admin-products-tools input,.admin-products-tools select { min-height:44px; padding:.55rem .8rem; color:var(--nx-text,#eaf0ff); background:var(--nx-field,#0a0d1f); border:1px solid rgba(var(--nx-violet-rgb,138,92,255),.35); border-radius:10px; }
  .admin-products-tools input { flex:1 1 260px; }
  .admin-products-tools select { flex:0 1 200px; }
  .admin-product-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(230px,1fr)); gap:1rem; }
  .admin-product-card { overflow:hidden; background:var(--nx-card,rgba(10,13,31,.88)); border:1px solid rgba(var(--nx-violet-rgb,138,92,255),.3); border-radius:14px; box-shadow:0 10px 24px rgba(0,0,0,.12); }
  .admin-product-image { display:grid; place-items:center; height:170px; padding:1rem; background:rgba(127,140,170,.08); }
  .admin-product-image img { width:100%; height:100%; object-fit:contain; }
  .admin-product-copy { padding:1rem; }
  .admin-product-meta { display:flex; justify-content:space-between; gap:.5rem; color:var(--nx-muted,#8f9bd0); font-size:.8rem; }
  .admin-product-copy h2 { margin:.55rem 0; color:var(--nx-text,#eaf0ff); font-size:1.05rem; font-weight:700; }
  .admin-product-price { display:flex; align-items:baseline; gap:.55rem; }
  .admin-product-price strong { color:var(--nx-cyan-ink,#00e5ff); font-size:1.1rem; }
  .admin-product-price del { color:var(--nx-muted,#8f9bd0); font-size:.85rem; }
  .admin-product-rating { margin-top:.5rem; color:var(--nx-muted,#8f9bd0); font-size:.85rem; }
  .admin-product-badge { display:inline-block; margin-top:.65rem; padding:.2rem .55rem; border-radius:999px; background:rgba(var(--nx-cyan-rgb,0,229,255),.12); color:var(--nx-cyan-ink,#00e5ff); font-size:.75rem; font-weight:700; }
  @media(max-width:600px) { .admin-products-head { flex-direction:column; } }
</style>

<main class="admin-content p-3 p-lg-4">
  <div class="admin-products">
    <header class="admin-products-head">
      <div>
        <h1>Products</h1>
        <p>Store catalog shown on the Hero storefront.</p>
      </div>
      <?php if ($catalogError === ''): ?><span class="admin-products-count"><?php echo count($products); ?> of <?php echo count($catalog); ?> products</span><?php endif; ?>
    </header>

    <?php if ($catalogError !== ''): ?>
      <div class="alert alert-danger" role="alert"><?php echo $vsProductEscape($catalogError); ?></div>
    <?php else: ?>
      <form class="admin-products-tools" method="get" action="products.php">
        <input type="search" name="q" value="<?php echo $vsProductEscape($query); ?>" placeholder="Search products or brands" aria-label="Search products or brands">
        <select name="category" aria-label="Filter by category" onchange="this.form.submit()">
          <option value="">All categories</option>
          <?php foreach ($categories as $categoryOption): ?>
            <option value="<?php echo $vsProductEscape($categoryOption); ?>"<?php echo strcasecmp($category, $categoryOption) === 0 ? ' selected' : ''; ?>><?php echo $vsProductEscape($categoryOption); ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-primary" type="submit"><i class="bi bi-search" aria-hidden="true"></i> Search</button>
      </form>

      <?php if (!$products): ?>
        <div class="alert alert-secondary" role="status">No products match your search.</div>
      <?php else: ?>
        <section class="admin-product-grid" aria-label="Store products">
          <?php foreach ($products as $product): ?>
            <?php
            $image = (string)($product['img'] ?? '');
            $imageIsSafe = filter_var($image, FILTER_VALIDATE_URL) !== false
                && strtolower((string)parse_url($image, PHP_URL_SCHEME)) === 'https';
            ?>
            <article class="admin-product-card">
              <div class="admin-product-image">
                <?php if ($imageIsSafe): ?>
                  <img src="<?php echo $vsProductEscape($image); ?>" alt="<?php echo $vsProductEscape($product['name']); ?>" loading="lazy">
                <?php else: ?>
                  <i class="bi bi-bag" aria-hidden="true"></i>
                <?php endif; ?>
              </div>
              <div class="admin-product-copy">
                <div class="admin-product-meta">
                  <span><?php echo $vsProductEscape($product['brand']); ?></span>
                  <span><?php echo $vsProductEscape($product['cat']); ?></span>
                </div>
                <h2><?php echo $vsProductEscape($product['name']); ?></h2>
                <div class="admin-product-price">
                  <strong><?php echo $vsProductMoney($product['price']); ?></strong>
                  <?php if ((float)($product['old'] ?? 0) > (float)$product['price']): ?>
                    <del><?php echo $vsProductMoney($product['old']); ?></del>
                  <?php endif; ?>
                </div>
                <div class="admin-product-rating">
                  <i class="bi bi-star-fill" aria-hidden="true"></i>
                  <?php echo $vsProductEscape($product['rating'] ?? '—'); ?>
                  · <?php echo number_format((int)($product['reviews'] ?? 0)); ?> reviews
                </div>
                <?php if (trim((string)($product['badge'] ?? '')) !== ''): ?>
                  <span class="admin-product-badge"><?php echo $vsProductEscape($product['badge']); ?></span>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </section>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</main>
