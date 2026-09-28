<?php

if (!theme::render('head', get_defined_vars())) {
    require APPROOT . '/views/inc/head.php';
}
?>
<main class="container py-5">
    <h1>Chain Explorer</h1>
    <?php $chain = $data['chain'] ?? ['state' => 'unconfigured', 'message' => 'Blockchain not configured.']; ?>
    <p role="status"><?= htmlspecialchars($chain['message'], ENT_QUOTES, 'UTF-8'); ?></p>
    <?php if ($chain['state'] === 'unconfigured') : ?>
        <p>Chain information will appear here once the site administrator completes setup.</p>
    <?php elseif ($chain['state'] === 'online') : ?>
        <h2>Chain status</h2>
        <?php if ($chain['source'] === 'secondary') : ?>
            <p>Using the fallback node.</p>
        <?php endif; ?>
        <?php if (is_array($chain['info'] ?? null)) : ?>
            <dl>
            <?php foreach ($chain['info'] as $label => $value) : ?>
                <dt><?= htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8'); ?></dt>
                <dd style="overflow-wrap:anywhere"><?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); ?></dd>
            <?php endforeach; ?>
            </dl>
        <?php else : ?>
            <p>Current Chain data is unavailable.</p>
        <?php endif; ?>

        <?php
        $latestBlock = $data['latest_block'] ?? [
            'available' => false,
            'height' => null,
            'block_id' => null,
        ];
        ?>
        <?php if ($latestBlock['available'] === true) : ?>
            <h2>Latest Block</h2>
            <dl>
                <dt>Height</dt>
                <dd><?= htmlspecialchars((string) $latestBlock['height'], ENT_QUOTES, 'UTF-8'); ?></dd>

                <dt>Block ID</dt>
                <dd style="overflow-wrap:anywhere">
                    <a href="/explorer/block/<?= rawurlencode((string) $latestBlock['block_id']); ?>">
                        <?= htmlspecialchars((string) $latestBlock['block_id'], ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                </dd>
            </dl>
        <?php endif; ?>
    <?php else : ?>
        <p>Please try again later. No current Chain data could be retrieved.</p>
    <?php endif; ?>
</main>
<?php
if (!theme::render('foot', get_defined_vars())) {
    require APPROOT . '/views/inc/foot.php';
}
