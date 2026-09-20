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
        <dl>
        <?php foreach ($chain['info'] as $label => $value) : ?>
            <dt><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></dt>
            <dd style="overflow-wrap:anywhere"><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?></dd>
        <?php endforeach; ?>
        </dl>
    <?php else : ?>
        <p>Please try again later. No current Chain data could be retrieved.</p>
    <?php endif; ?>
</main>
<?php
if (!theme::render('foot', get_defined_vars())) {
    require APPROOT . '/views/inc/foot.php';
}
