<?php

declare(strict_types=1);

/**
 * Admin — analytics (PROJECT_RULES.md §7 Phase 7 "Revenue analytics,
 * date-range filters, sales charts, best sellers, customer metrics").
 *
 * The bar chart is hand-rolled inline SVG rather than a charting library —
 * consistent with the rest of this codebase's vanilla-JS, no-framework
 * approach, and there is exactly one chart on one page, which does not
 * justify a dependency.
 */

$pageTitle = 'Analytics';
require_once __DIR__ . '/../includes/admin-header.php';

use App\Analytics\AnalyticsRepository;
use App\Support\View;

$analytics = new AnalyticsRepository();

// Date range: default to the last 30 days, validated against a sane format
// so a malformed query string cannot reach the SQL layer as anything but a
// plain date string bound by prepared statements.
$today = date('Y-m-d');
$from  = (string) ($_GET['from'] ?? date('Y-m-d', strtotime('-29 days')));
$to    = (string) ($_GET['to'] ?? $today);

$dateRe = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match($dateRe, $from)) {
    $from = date('Y-m-d', strtotime('-29 days'));
}
if (!preg_match($dateRe, $to)) {
    $to = $today;
}
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
// Cap the range so a chart request cannot ask for years of daily rows.
if ((strtotime($to) - strtotime($from)) > 366 * 86400) {
    $from = date('Y-m-d', strtotime($to . ' -366 days'));
}

$daily     = $analytics->dailyRevenue($from, $to);
$summary   = $analytics->summary($from, $to);
$bestSellers = $analytics->bestSellers($from, $to, 10);
$customerMetrics = $analytics->customerMetrics($from, $to);
$topCustomers = $analytics->topCustomers(10);

// --- Inline SVG bar chart for daily revenue ---
$chartWidth  = 760;
$chartHeight = 200;
$barGap      = 3;
$barCount    = count($daily);
$barWidth    = $barCount > 0 ? max(2, ($chartWidth / $barCount) - $barGap) : 0;
$maxRevenue  = max(1.0, ...array_map(static fn(array $d): float => (float) $d['revenue'], $daily));
?>

<h1 class="page-heading">Analytics</h1>
<p class="page-subheading">Store performance at a glance</p>

<form method="get" action="analytics.php" class="shop-filters">
    <div class="filter-row">
        <label for="from">From</label>
        <input type="date" id="from" name="from" value="<?= View::e($from) ?>" class="filter-input" style="max-width:170px;">
        <label for="to">To</label>
        <input type="date" id="to" name="to" value="<?= View::e($to) ?>" class="filter-input" style="max-width:170px;">
        <button type="submit" class="btn">Apply</button>
        <a href="analytics.php" class="btn btn-outline" style="background:var(--color-primary);">Last 30 Days</a>
    </div>
</form>

<div class="value-grid">
    <div class="value-card">
        <h3>Orders</h3>
        <p class="stat-number"><?= $summary['orders'] ?></p>
    </div>
    <div class="value-card">
        <h3>Revenue</h3>
        <p class="stat-number stat-success"><?= View::money($summary['revenue']) ?></p>
    </div>
    <div class="value-card">
        <h3>Avg. Order Value</h3>
        <p class="stat-number"><?= View::money($summary['average_order_value']) ?></p>
    </div>
    <div class="value-card">
        <h3>Discounts Given</h3>
        <p class="stat-number stat-danger"><?= View::money($summary['discount_given']) ?></p>
    </div>
    <div class="value-card">
        <h3>New Customers</h3>
        <p class="stat-number"><?= $customerMetrics['new_customers'] ?></p>
    </div>
    <div class="value-card">
        <h3>Repeat Purchase Rate</h3>
        <p class="stat-number"><?= $customerMetrics['repeat_rate'] ?>%</p>
        <p class="muted"><?= $customerMetrics['repeat_customers'] ?> of <?= $customerMetrics['ordering_customers'] ?> customers</p>
    </div>
</div>

<div class="admin-card admin-card-wide mt-16">
    <h2 class="card-title">Revenue by Day</h2>

    <?php if ($barCount === 0 || $maxRevenue <= 1.0 && $summary['orders'] === 0): ?>
        <p class="muted">No orders in this range.</p>
    <?php else: ?>
        <div class="chart-scroll">
            <svg viewBox="0 0 <?= $chartWidth ?> <?= $chartHeight + 24 ?>" class="revenue-chart" role="img"
                 aria-label="Daily revenue from <?= View::e($from) ?> to <?= View::e($to) ?>">
                <?php foreach ($daily as $i => $day): ?>
                    <?php
                    $barHeight = $maxRevenue > 0 ? ((float) $day['revenue'] / $maxRevenue) * ($chartHeight - 10) : 0;
                    $x = $i * ($barWidth + $barGap);
                    $y = $chartHeight - $barHeight;
                    ?>
                    <rect x="<?= round($x, 1) ?>" y="<?= round($y, 1) ?>" width="<?= round($barWidth, 1) ?>" height="<?= round($barHeight, 1) ?>"
                          fill="<?= (float) $day['revenue'] > 0 ? 'var(--color-primary)' : 'var(--color-border)' ?>" rx="1.5">
                        <title><?= View::e($day['date']) ?>: <?= View::e(number_format((float) $day['revenue'], 2)) ?> (<?= $day['orders'] ?> orders)</title>
                    </rect>
                <?php endforeach; ?>
            </svg>
        </div>
        <p class="muted">Hover a bar for the exact date, revenue and order count. Tallest bar &#8776; &#2547;<?= number_format($maxRevenue, 2) ?>.</p>
    <?php endif; ?>
</div>

<div class="admin-split mt-16">
    <div class="admin-card admin-card-wide">
        <h2 class="card-title">Best Sellers</h2>
        <?php if ($bestSellers === []): ?>
            <p class="muted">No sales in this range.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Product</th>
                            <th scope="col">Units Sold</th>
                            <th scope="col">Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bestSellers as $row): ?>
                            <tr>
                                <td>
                                    <?php if ($row['slug'] !== null): ?>
                                        <a href="../product.php?slug=<?= urlencode($row['slug']) ?>"><?= View::e($row['name']) ?></a>
                                    <?php else: ?>
                                        <?= View::e($row['name']) ?> <small class="muted">(removed)</small>
                                    <?php endif; ?>
                                </td>
                                <td><?= $row['quantity'] ?></td>
                                <td><?= View::money($row['revenue']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="admin-card">
        <h2 class="card-title">Top Customers (all-time)</h2>
        <?php if ($topCustomers === []): ?>
            <p class="muted">No orders yet.</p>
        <?php else: ?>
            <ul class="plain-list">
                <?php foreach ($topCustomers as $customer): ?>
                    <li class="movement-item">
                        <strong><?= View::e($customer['name']) ?></strong><br>
                        <small class="muted"><?= View::e($customer['email']) ?></small><br>
                        <?= View::money($customer['total_spent']) ?> &middot; <?= $customer['orders'] ?> order<?= $customer['orders'] === 1 ? '' : 's' ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
