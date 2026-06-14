<?php
/**
 * FSL Collectible Cards — scenario math: one pack price; columns = sales outcomes.
 * Pack COGS per unit follows volume tiers (print units). Defaults + live JS recalc.
 */
set_include_path(get_include_path() . PATH_SEPARATOR . __DIR__);

ob_start();
session_start();

$pageTitle = 'Collectible Cards — Scenarios';
$additionalCss = [];

$fixed_one_time = [
    'label' => 'One-time fixed (art direction, templates, legal review, proofs)',
    'amount' => 2800,
];

/** Single retail price you commit to; all columns use this for pack revenue. */
$shared_launch = [
    'pack_price' => 22.00,
    'spoilage_pct' => 0.05,
    'processor_pct' => 0.03,
];

/**
 * Illustrative printer tiers: landed COGS per pack by total units ordered
 * (ceil(packs_sold × (1 + spoilage))). Replace with your quote ladder.
 */
$cogs_volume_tiers = [
    ['max_print_units' => 90, 'cogs_each' => 16.50],
    ['max_print_units' => 260, 'cogs_each' => 8.25],
    ['max_print_units' => 999999, 'cogs_each' => 5.10],
];

$scenarios = [
    'worst' => [
        'title' => 'Low sales',
        'tag' => 'danger',
        'story' => 'Fewer packs move; customization is light; shipping and fixed costs hurt more per pack sold.',
        'packs_sold' => 55,
        'custom_count' => 8,
        'custom_price_each' => 195.00,
        'custom_cost_each' => 135.00,
        'ship_total' => 520.00,
    ],
    'likely' => [
        'title' => 'Base case',
        'tag' => 'warning',
        'story' => 'Moderate turnout (FSL + invites); solid mix of packs and paid custom slots.',
        'packs_sold' => 220,
        'custom_count' => 32,
        'custom_price_each' => 185.00,
        'custom_cost_each' => 110.00,
        'ship_total' => 980.00,
    ],
    'best' => [
        'title' => 'Strong sales',
        'tag' => 'success',
        'story' => 'Strong pack demand; more custom work; shipping spread across more orders; print tier drops $/pack.',
        'packs_sold' => 520,
        'custom_count' => 55,
        'custom_price_each' => 175.00,
        'custom_cost_each' => 88.00,
        'ship_total' => 1650.00,
    ],
];

function pcards_pack_cogs_for_units(int $units, array $tiers): float
{
    foreach ($tiers as $t) {
        if ($units <= (int) $t['max_print_units']) {
            return (float) $t['cogs_each'];
        }
    }
    $last = $tiers[count($tiers) - 1];
    return (float) $last['cogs_each'];
}

/**
 * @param array<string, mixed> $s
 * @param array<string, float> $shared
 */
function pcards_calc_scenario(array $s, float $fixedAmount, array $shared, array $tiers): array
{
    $packPrice = (float) $shared['pack_price'];
    $spoil = (float) $shared['spoilage_pct'];
    $proc = (float) $shared['processor_pct'];

    $packRev = (int) $s['packs_sold'] * $packPrice;
    $printUnits = (int) ceil((int) $s['packs_sold'] * (1 + $spoil));
    $cogsEach = pcards_pack_cogs_for_units($printUnits, $tiers);
    $packCogs = $printUnits * $cogsEach;

    $customRev = (int) $s['custom_count'] * (float) $s['custom_price_each'];
    $customCogs = (int) $s['custom_count'] * (float) $s['custom_cost_each'];
    $rev = $packRev + $customRev;
    $processor = $rev * $proc;
    $varCost = $packCogs + $customCogs + (float) $s['ship_total'] + $processor;
    $contribution = $rev - $varCost;
    $netAfterFixed = $contribution - $fixedAmount;

    return [
        'print_units' => $printUnits,
        'implied_pack_cogs_each' => $cogsEach,
        'pack_rev' => $packRev,
        'pack_cogs' => $packCogs,
        'custom_rev' => $customRev,
        'custom_cogs' => $customCogs,
        'rev' => $rev,
        'processor' => $processor,
        'var_cost' => $varCost,
        'contribution' => $contribution,
        'net_after_fixed' => $netAfterFixed,
    ];
}

$fixedAmt = (float) $fixed_one_time['amount'];
$computed = [];
foreach (['worst', 'likely', 'best'] as $key) {
    $computed[$key] = pcards_calc_scenario($scenarios[$key], $fixedAmt, $shared_launch, $cogs_volume_tiers);
}

/**
 * @param mixed $value
 */
function pcards_in_attrs(string $scenario, string $field, $value, string $step, string $min = '0', string $max = ''): string
{
    $v = is_numeric($value) ? $value : 0;
    $maxAttr = $max !== '' ? ' max="' . htmlspecialchars($max, ENT_QUOTES, 'UTF-8') . '"' : '';
    return 'type="number" class="form-control form-control-sm pc-in text-right" '
        . 'data-scenario="' . htmlspecialchars($scenario, ENT_QUOTES, 'UTF-8') . '" '
        . 'data-field="' . htmlspecialchars($field, ENT_QUOTES, 'UTF-8') . '" '
        . 'value="' . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '" '
        . 'step="' . htmlspecialchars($step, ENT_QUOTES, 'UTF-8') . '" '
        . 'min="' . htmlspecialchars($min, ENT_QUOTES, 'UTF-8') . '" '
        . $maxAttr
        . ' inputmode="decimal"';
}

function pcards_shared_attrs(string $id, string $field, $value, string $step, string $min = '0', string $max = ''): string
{
    $v = is_numeric($value) ? $value : 0;
    $maxAttr = $max !== '' ? ' max="' . htmlspecialchars($max, ENT_QUOTES, 'UTF-8') . '"' : '';
    return 'id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" '
        . 'type="number" class="form-control pc-in pc-in-shared" '
        . 'data-field="' . htmlspecialchars($field, ENT_QUOTES, 'UTF-8') . '" '
        . 'value="' . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '" '
        . 'step="' . htmlspecialchars($step, ENT_QUOTES, 'UTF-8') . '" '
        . 'min="' . htmlspecialchars($min, ENT_QUOTES, 'UTF-8') . '" '
        . $maxAttr
        . ' inputmode="decimal"';
}

$_tiers_json = json_encode($cogs_volume_tiers);
$cogs_tiers_json = htmlspecialchars($_tiers_json !== false ? $_tiers_json : '[]', ENT_QUOTES, 'UTF-8');

include_once 'includes/header.php';
?>

<style>
    .pcards-page { max-width: 1040px; margin: 0 auto; }
    .pcards-page .hero {
        text-align: center;
        padding: 1.75rem 1rem 2rem;
        border-radius: 12px;
        background: linear-gradient(165deg, rgba(80, 40, 120, 0.45) 0%, rgba(20, 15, 35, 0.9) 50%, rgba(10, 30, 50, 0.85) 100%);
        border: 1px solid rgba(0, 212, 255, 0.25);
        box-shadow: 0 8px 40px rgba(0, 0, 0, 0.4);
        margin-bottom: 1.5rem;
    }
    .pcards-page .hero h1 {
        color: #00d4ff;
        text-shadow: 0 0 20px rgba(0, 212, 255, 0.35);
        font-size: 1.85rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }
    .pcards-page .hero .lead { color: #e8e8e8; font-size: 1.02rem; max-width: 44rem; margin: 0 auto 1rem; line-height: 1.55; }
    .pcards-page h2 {
        color: #ff6f61;
        font-size: 1.45rem;
        margin: 1.75rem 0 0.85rem;
        padding-bottom: 0.3rem;
        border-bottom: 2px solid rgba(255, 111, 97, 0.45);
    }
    .pcards-page h3 { color: #00d4ff; font-size: 1.1rem; margin: 1.25rem 0 0.6rem; }
    .pcards-page .section {
        background: rgba(255, 255, 255, 0.06);
        border-radius: 10px;
        padding: 1.15rem 1.35rem;
        margin-bottom: 1.1rem;
        border-left: 4px solid #ff6f61;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
    }
    .pcards-page .section p, .pcards-page .section li { color: #e0e0e0; line-height: 1.55; margin-bottom: 0.5rem; font-size: 0.95rem; }
    .pcards-page .section a { color: #00d4ff; }
    .pcards-page .section a:hover { color: #ff6f61; }
    .pcards-page .table-scenarios { font-size: 0.9rem; }
    .pcards-page .table-scenarios th { color: #00d4ff; border-top: none; white-space: nowrap; }
    .pcards-page .table-scenarios td { color: #e8e8e8; vertical-align: middle; }
    .pcards-page .table-scenarios .num { text-align: right; font-variant-numeric: tabular-nums; }
    .pcards-page .table-scenarios tfoot td { font-weight: 600; border-top: 2px solid rgba(255, 111, 97, 0.5); }
    .pcards-page .table-scenarios .pc-in {
        display: inline-block;
        width: 100%;
        max-width: 6.5rem;
        background: rgba(0, 0, 0, 0.35);
        border-color: rgba(0, 212, 255, 0.35);
        color: #fff;
    }
    .pcards-page .table-scenarios .pc-in:focus {
        border-color: #00d4ff;
        box-shadow: 0 0 0 0.15rem rgba(0, 212, 255, 0.2);
    }
    .pcards-page #in-fixed-amount,
    .pcards-page .pc-in-shared {
        max-width: 10rem;
        background: rgba(0, 0, 0, 0.35);
        border-color: rgba(0, 212, 255, 0.35);
        color: #fff;
    }
    .pcards-page .neg { color: #ff8a80; }
    .pcards-page .pos { color: #69f0ae; }
    .pcards-page .scenario-card {
        background: rgba(0, 0, 0, 0.22);
        border-radius: 10px;
        padding: 1rem 1.1rem;
        height: 100%;
        border: 1px solid rgba(255, 255, 255, 0.08);
    }
    .pcards-page .scenario-card h4 { font-size: 1rem; margin-bottom: 0.5rem; }
    .pcards-page .footnote { font-size: 0.85rem; color: #999; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid rgba(255, 255, 255, 0.08); }
    .pcards-page label.fixed-label { color: #00d4ff; font-weight: 600; margin-bottom: 0.25rem; }
    .pcards-page .tier-table { font-size: 0.88rem; }
    .pcards-page .tier-table th, .pcards-page .tier-table td { color: #e0e0e0; }
</style>

<div class="pcards-page" id="pcards-scenario-calc" data-cogs-tiers="<?= $cogs_tiers_json ?>">
    <div class="hero">
        <h1><i class="fas fa-calculator mr-2" aria-hidden="true"></i>Launch Edition — scenario math</h1>
        <p class="lead mb-0">Pick <strong>one pack retail price</strong> and shared print rules, then compare <strong>how outcomes change if actual pack sales (and custom/shipping) land low, on-target, or strong</strong>. Pack manufacturing cost <strong>moves with print volume</strong> via the tier ladder below—not three separate guesses at price.</p>
    </div>

    <div class="section">
        <h2 class="mt-0">How to read this page</h2>
        <ul class="mb-0">
            <li><strong>Not a promise of profit</strong> — internal planning only.</li>
            <li><strong>Columns = sales/results</strong> — mainly different <strong>packs sold</strong> (and custom slots / shipping you attach to that story).</li>
            <li><strong>Print units</strong> = ⌈packs sold × (1 + spoilage)⌉; that count picks the <strong>COGS per pack</strong> tier (quote ladder).</li>
            <li><strong>Tiers</strong> are placeholders—edit the <code>$cogs_volume_tiers</code> array in this file (and the JSON updates for JS automatically).</li>
        </ul>
    </div>

    <h2>Scenario stories</h2>
    <div class="row">
        <?php foreach (['worst', 'likely', 'best'] as $key):
            $s = $scenarios[$key];
            $badge = $s['tag'];
            ?>
        <div class="col-lg-4 mb-3">
            <div class="scenario-card">
                <h4><span class="badge badge-<?= htmlspecialchars($badge, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($s['title'], ENT_QUOTES, 'UTF-8') ?></span></h4>
                <p class="mb-0 small text-muted"><?= htmlspecialchars($s['story'], ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <h2>Shared assumptions (one price + print rules)</h2>
    <div class="section">
        <div class="form-row">
            <div class="form-group col-md-6 col-lg-3">
                <label class="fixed-label d-block" for="in-fixed-amount"><?= htmlspecialchars($fixed_one_time['label'], ENT_QUOTES, 'UTF-8') ?> ($)</label>
                <input id="in-fixed-amount" class="form-control pc-in pc-in-fixed" type="number" data-field="fixed_amount" step="50" min="0" value="<?= htmlspecialchars((string) $fixed_one_time['amount'], ENT_QUOTES, 'UTF-8') ?>" inputmode="numeric">
            </div>
            <div class="form-group col-md-6 col-lg-3">
                <label class="fixed-label d-block" for="in-shared-pack-price">Pack retail price ($)</label>
                <input <?= pcards_shared_attrs('in-shared-pack-price', 'pack_price', $shared_launch['pack_price'], '0.01', '0') ?>>
            </div>
            <div class="form-group col-md-6 col-lg-3">
                <label class="fixed-label d-block" for="in-shared-spoilage">Spoilage / overrun (% of packs sold)</label>
                <input <?= pcards_shared_attrs('in-shared-spoilage', 'spoilage_pct_percent', $shared_launch['spoilage_pct'] * 100, '0.5', '0', '100') ?>>
            </div>
            <div class="form-group col-md-6 col-lg-3">
                <label class="fixed-label d-block" for="in-shared-processor">Payment processing (% of revenue)</label>
                <input <?= pcards_shared_attrs('in-shared-processor', 'processor_pct_percent', $shared_launch['processor_pct'] * 100, '0.1', '0', '100') ?>>
            </div>
        </div>
        <h3 class="mt-3">Volume → pack COGS (illustrative tiers)</h3>
        <p class="small text-muted mb-2">Each column’s <strong>packs sold</strong> flows through here to set implied $/pack before variable COGS is totaled.</p>
        <div class="table-responsive">
            <table class="table table-sm table-dark tier-table mb-0">
                <thead>
                    <tr><th>Print units (ordered) ≤</th><th>Pack COGS each ($)</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($cogs_volume_tiers as $t): ?>
                    <tr>
                        <td><?= (int) $t['max_print_units'] >= 999999 ? 'Above prior cutoff (catch-all)' : '≤ ' . (int) $t['max_print_units'] ?></td>
                        <td>$<?= number_format((float) $t['cogs_each'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <h2>Per-scenario inputs (mostly sales &amp; attach)</h2>
    <div class="section p-0 overflow-hidden">
        <table class="table table-dark table-scenarios mb-0">
            <thead>
                <tr>
                    <th scope="col">Input</th>
                    <th scope="col" class="text-center">Low sales</th>
                    <th scope="col" class="text-center">Base</th>
                    <th scope="col" class="text-center">Strong sales</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Packs sold (Launch)</td>
                    <?php foreach (['worst', 'likely', 'best'] as $key): ?>
                    <td class="text-center"><input <?= pcards_in_attrs($key, 'packs_sold', $scenarios[$key]['packs_sold'], '1', '0') ?>></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td>Customization slots sold</td>
                    <?php foreach (['worst', 'likely', 'best'] as $key): ?>
                    <td class="text-center"><input <?= pcards_in_attrs($key, 'custom_count', $scenarios[$key]['custom_count'], '1', '0') ?>></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td>Customization price each ($)</td>
                    <?php foreach (['worst', 'likely', 'best'] as $key): ?>
                    <td class="text-center"><input <?= pcards_in_attrs($key, 'custom_price_each', $scenarios[$key]['custom_price_each'], '0.01', '0') ?>></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td>Customization cost (artist/time) each ($)</td>
                    <?php foreach (['worst', 'likely', 'best'] as $key): ?>
                    <td class="text-center"><input <?= pcards_in_attrs($key, 'custom_cost_each', $scenarios[$key]['custom_cost_each'], '0.01', '0') ?>></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td>Shipping total for Launch ($)</td>
                    <?php foreach (['worst', 'likely', 'best'] as $key): ?>
                    <td class="text-center"><input <?= pcards_in_attrs($key, 'ship_total', $scenarios[$key]['ship_total'], '1', '0') ?>></td>
                    <?php endforeach; ?>
                </tr>
            </tbody>
        </table>
    </div>

    <h2>Outputs (computed)</h2>
    <div class="section p-0 overflow-hidden">
        <table class="table table-dark table-scenarios mb-0">
            <thead>
                <tr>
                    <th scope="col">Line</th>
                    <th scope="col" class="text-right">Low sales</th>
                    <th scope="col" class="text-right">Base</th>
                    <th scope="col" class="text-right">Strong sales</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Pack revenue (packs × shared retail price)</td>
                    <?php foreach (['worst', 'likely', 'best'] as $key): ?>
                    <td class="num"><span id="out-<?= $key ?>-pack_rev">$<?= number_format($computed[$key]['pack_rev'], 2) ?></span></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td>Print units (⌈packs × (1 + spoilage)⌉)</td>
                    <?php foreach (['worst', 'likely', 'best'] as $key): ?>
                    <td class="num"><span id="out-<?= $key ?>-print_units"><?= (int) $computed[$key]['print_units'] ?></span></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td>Implied pack COGS each (tier)</td>
                    <?php foreach (['worst', 'likely', 'best'] as $key): ?>
                    <td class="num"><span id="out-<?= $key ?>-implied_pack_cogs_each">$<?= number_format($computed[$key]['implied_pack_cogs_each'], 2) ?></span></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td>Pack variable COGS (print units × implied $/pack)</td>
                    <?php foreach (['worst', 'likely', 'best'] as $key): ?>
                    <td class="num"><span id="out-<?= $key ?>-pack_cogs">$<?= number_format($computed[$key]['pack_cogs'], 2) ?></span></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td>Customization revenue</td>
                    <?php foreach (['worst', 'likely', 'best'] as $key): ?>
                    <td class="num"><span id="out-<?= $key ?>-custom_rev">$<?= number_format($computed[$key]['custom_rev'], 2) ?></span></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td>Customization variable cost</td>
                    <?php foreach (['worst', 'likely', 'best'] as $key): ?>
                    <td class="num"><span id="out-<?= $key ?>-custom_cogs">$<?= number_format($computed[$key]['custom_cogs'], 2) ?></span></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td>Shipping (lump)</td>
                    <?php foreach (['worst', 'likely', 'best'] as $key): ?>
                    <td class="num"><span id="out-<?= $key ?>-ship">$<?= number_format($scenarios[$key]['ship_total'], 2) ?></span></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td>Payment processing</td>
                    <?php foreach (['worst', 'likely', 'best'] as $key): ?>
                    <td class="num"><span id="out-<?= $key ?>-processor">$<?= number_format($computed[$key]['processor'], 2) ?></span></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td><strong>Total revenue</strong></td>
                    <?php foreach (['worst', 'likely', 'best'] as $key): ?>
                    <td class="num"><strong><span id="out-<?= $key ?>-rev">$<?= number_format($computed[$key]['rev'], 2) ?></span></strong></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td><strong>Total variable costs</strong></td>
                    <?php foreach (['worst', 'likely', 'best'] as $key): ?>
                    <td class="num"><strong><span id="out-<?= $key ?>-var_cost">$<?= number_format($computed[$key]['var_cost'], 2) ?></span></strong></td>
                    <?php endforeach; ?>
                </tr>
            </tbody>
            <tfoot>
                <tr>
                    <td>Contribution (revenue − variable)</td>
                    <?php foreach (['worst', 'likely', 'best'] as $key):
                        $v = $computed[$key]['contribution'];
                        $cls = $v < 0 ? 'neg' : 'pos';
                        ?>
                    <td class="num"><span id="out-<?= $key ?>-contribution" class="<?= $cls ?>">$<?= number_format($v, 2) ?></span></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td>Minus <?= htmlspecialchars($fixed_one_time['label'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="num"><span class="out-fixed-subtract">$<?= number_format($fixedAmt, 2) ?></span></td>
                    <td class="num"><span class="out-fixed-subtract">$<?= number_format($fixedAmt, 2) ?></span></td>
                    <td class="num"><span class="out-fixed-subtract">$<?= number_format($fixedAmt, 2) ?></span></td>
                </tr>
                <tr>
                    <td><strong>Illustrative net after fixed</strong></td>
                    <?php foreach (['worst', 'likely', 'best'] as $key):
                        $v = $computed[$key]['net_after_fixed'];
                        $cls = $v < 0 ? 'neg' : 'pos';
                        ?>
                    <td class="num"><strong><span id="out-<?= $key ?>-net_after_fixed" class="<?= $cls ?>">$<?= number_format($v, 2) ?></span></strong></td>
                    <?php endforeach; ?>
                </tr>
            </tfoot>
        </table>
    </div>

    <h2>Levers</h2>
    <div class="section">
        <ul class="mb-0">
            <li><strong>Actual pack sales</strong> — main driver of pack revenue and which print tier you land in.</li>
            <li><strong>Chosen retail price</strong> — set once; scenarios show margin if demand is soft vs strong.</li>
            <li><strong>Customization attach</strong> — slots and artist cost still move contribution column-by-column.</li>
            <li><strong>Tier ladder</strong> — when quotes change, update tiers so implied COGS tracks your printer’s MOQ breaks.</li>
        </ul>
    </div>

    <p class="footnote mb-0">Calculations run in your browser. Does not include tax, chargebacks, or pro rev-share pools.</p>
</div>

<script>
(function () {
    var KEYS = ['worst', 'likely', 'best'];

    function parseMoney(v) {
        var n = parseFloat(String(v).replace(/,/g, '').trim());
        return isFinite(n) ? n : 0;
    }

    function q(scenario, field) {
        return document.querySelector(
            '.pc-in[data-scenario="' + scenario + '"][data-field="' + field + '"]'
        );
    }

    function readShared() {
        function gid(id) {
            var el = document.getElementById(id);
            return el ? parseMoney(el.value) : 0;
        }
        return {
            pack_price: Math.max(0, gid('in-shared-pack-price')),
            spoilage_pct: Math.min(100, Math.max(0, gid('in-shared-spoilage'))) / 100,
            processor_pct: Math.min(100, Math.max(0, gid('in-shared-processor'))) / 100
        };
    }

    function readScenario(scenario) {
        function gv(field, fallback) {
            var el = q(scenario, field);
            if (!el) return fallback;
            var n = parseMoney(el.value);
            return isFinite(n) ? n : fallback;
        }
        return {
            packs_sold: Math.max(0, Math.floor(gv('packs_sold', 0))),
            custom_count: Math.max(0, Math.floor(gv('custom_count', 0))),
            custom_price_each: Math.max(0, gv('custom_price_each', 0)),
            custom_cost_each: Math.max(0, gv('custom_cost_each', 0)),
            ship_total: Math.max(0, gv('ship_total', 0))
        };
    }

    function packCogsEachFromUnits(units, tiers) {
        for (var i = 0; i < tiers.length; i++) {
            if (units <= tiers[i].max_print_units) return tiers[i].cogs_each;
        }
        return tiers[tiers.length - 1].cogs_each;
    }

    function calcScenario(s, sh, fixedAmount, tiers) {
        var packRev = s.packs_sold * sh.pack_price;
        var printUnits = Math.ceil(s.packs_sold * (1 + sh.spoilage_pct));
        var impliedEach = packCogsEachFromUnits(printUnits, tiers);
        var packCogs = printUnits * impliedEach;
        var customRev = s.custom_count * s.custom_price_each;
        var customCogs = s.custom_count * s.custom_cost_each;
        var rev = packRev + customRev;
        var processor = rev * sh.processor_pct;
        var varCost = packCogs + customCogs + s.ship_total + processor;
        var contribution = rev - varCost;
        var netAfterFixed = contribution - fixedAmount;
        return {
            print_units: printUnits,
            implied_pack_cogs_each: impliedEach,
            pack_rev: packRev,
            pack_cogs: packCogs,
            custom_rev: customRev,
            custom_cogs: customCogs,
            ship: s.ship_total,
            processor: processor,
            rev: rev,
            var_cost: varCost,
            contribution: contribution,
            net_after_fixed: netAfterFixed
        };
    }

    function fmtMoney(n) {
        return '$' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function setMoney(id, amount) {
        var el = document.getElementById(id);
        if (!el) return;
        el.textContent = fmtMoney(amount);
        if (id.indexOf('contribution') !== -1 || id.indexOf('net_after_fixed') !== -1) {
            el.classList.remove('neg', 'pos');
            el.classList.add(amount < 0 ? 'neg' : 'pos');
        }
    }

    function setInt(id, n) {
        var el = document.getElementById(id);
        if (el) el.textContent = String(Math.round(n));
    }

    function setPlainMoney(id, amount) {
        var el = document.getElementById(id);
        if (el) el.textContent = fmtMoney(amount);
    }

    var root = document.getElementById('pcards-scenario-calc');
    if (!root) return;

    var COGS_TIERS;
    try {
        COGS_TIERS = JSON.parse(root.getAttribute('data-cogs-tiers') || '[]');
    } catch (e) {
        COGS_TIERS = [];
    }

    function recalc() {
        var fixedEl = document.getElementById('in-fixed-amount');
        var fixedAmount = Math.max(0, parseMoney(fixedEl ? fixedEl.value : 0));
        var sh = readShared();

        var subs = document.querySelectorAll('.out-fixed-subtract');
        for (var i = 0; i < subs.length; i++) {
            subs[i].textContent = fmtMoney(fixedAmount);
        }

        KEYS.forEach(function (key) {
            var s = readScenario(key);
            var c = calcScenario(s, sh, fixedAmount, COGS_TIERS);
            setMoney('out-' + key + '-pack_rev', c.pack_rev);
            setInt('out-' + key + '-print_units', c.print_units);
            setPlainMoney('out-' + key + '-implied_pack_cogs_each', c.implied_pack_cogs_each);
            setMoney('out-' + key + '-pack_cogs', c.pack_cogs);
            setMoney('out-' + key + '-custom_rev', c.custom_rev);
            setMoney('out-' + key + '-custom_cogs', c.custom_cogs);
            setMoney('out-' + key + '-ship', c.ship);
            setMoney('out-' + key + '-processor', c.processor);
            setMoney('out-' + key + '-rev', c.rev);
            setMoney('out-' + key + '-var_cost', c.var_cost);
            setMoney('out-' + key + '-contribution', c.contribution);
            setMoney('out-' + key + '-net_after_fixed', c.net_after_fixed);
        });
    }

    root.addEventListener('input', recalc);
    root.addEventListener('change', recalc);
    root.addEventListener(
        'blur',
        function (e) {
            if (e.target.classList && e.target.classList.contains('pc-in')) {
                recalc();
            }
        },
        true
    );

    recalc();
})();
</script>

<?php
include_once 'includes/footer.php';
?>
