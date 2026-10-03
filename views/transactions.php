<?php
if (!theme::render('head', get_defined_vars())) { require APPROOT . '/views/inc/head.php'; }
$transactions = is_array($data['transactions'] ?? null) ? $data['transactions'] : [];
?>
<main class="container py-5">
    <h1>Transactions</h1>
    <p><a href="/explorer">← Explorer</a></p>
    <?php if (($data['available'] ?? false) !== true) : ?>
        <p role="status">Transaction index is currently unavailable.</p>
    <?php elseif ($transactions === []) : ?>
        <p>No transactions are currently indexed.</p>
    <?php else : ?>
        <div style="overflow-x:auto">
            <table>
                <thead><tr><th>Transaction ID</th><th>Block</th><th>Height</th><th>Position</th><th>Type</th></tr></thead>
                <tbody>
                <?php foreach ($transactions as $transaction) : ?>
                    <tr>
                        <td style="overflow-wrap:anywhere"><a href="/explorer/transaction/<?= rawurlencode((string) $transaction['transaction_id']); ?>"><?= htmlspecialchars((string) $transaction['transaction_id'], ENT_QUOTES, 'UTF-8'); ?></a></td>
                        <td style="overflow-wrap:anywhere"><a href="/explorer/block/<?= rawurlencode((string) $transaction['block_id']); ?>"><?= htmlspecialchars((string) $transaction['block_id'], ENT_QUOTES, 'UTF-8'); ?></a></td>
                        <td><?= htmlspecialchars((string) $transaction['block_height'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars((string) $transaction['transaction_position'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars((string) $transaction['transaction_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>
<?php if (!theme::render('foot', get_defined_vars())) { require APPROOT . '/views/inc/foot.php'; }
