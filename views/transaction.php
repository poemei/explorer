<?php
if (!theme::render('head', get_defined_vars())) { require APPROOT . '/views/inc/head.php'; }
$transaction = is_array($transaction ?? null) ? $transaction : [];
?>
<main class="container py-5">
    <h1>Transaction</h1>
    <p><a href="/explorer/transactions">← Transactions</a></p>
    <dl>
        <dt>Transaction ID</dt>
        <dd style="overflow-wrap:anywhere"><?= htmlspecialchars((string) ($transaction['transaction_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></dd>
        <dt>Accepted Block Height</dt>
        <dd><?= htmlspecialchars((string) ($transaction['block_height'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></dd>
        <dt>Accepted Block ID</dt>
        <dd style="overflow-wrap:anywhere"><a href="/explorer/block/<?= rawurlencode((string) ($transaction['block_id'] ?? '')); ?>"><?= htmlspecialchars((string) ($transaction['block_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></a></dd>
        <dt>Transaction Position</dt>
        <dd><?= htmlspecialchars((string) ($transaction['transaction_position'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></dd>
        <?php if (($transaction['transaction_type'] ?? null) !== null) : ?>
            <dt>Transaction Type</dt>
            <dd><?= htmlspecialchars((string) $transaction['transaction_type'], ENT_QUOTES, 'UTF-8'); ?></dd>
        <?php endif; ?>
    </dl>
</main>
<?php if (!theme::render('foot', get_defined_vars())) { require APPROOT . '/views/inc/foot.php'; }
