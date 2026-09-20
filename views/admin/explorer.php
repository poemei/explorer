<?php

if (!theme::render('head', get_defined_vars())) {
    require APPROOT . '/views/inc/head.php';
}

/**
 * The idea here is to be able to set what Blockchain IP/URL to use as its primary chain server and a secondary as a fallback.
 * and demonstrate chain health things
 * it also must pass all Chaos MVC Module & Data Lifecyle requirements
*/
?>
<main class="container py-4">
    <h1>Chain Explorer administration</h1>
    <p><a href="/explorer">View Explorer</a></p>
    <?php if (!empty($data['error'])) : ?>
        <p role="alert"><?= htmlspecialchars($data['error'], ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <?php if (!empty($data['saved'])) : ?><p role="status">Chain configuration saved.</p><?php endif; ?>
    <?php if (!empty($data['installed'])) : ?><p role="status">SQL installed.</p><?php endif; ?>
    <?php if (!empty($data['deleted_data'])) : ?><p role="status">Module data deleted. Chain configuration preserved.</p><?php endif; ?>
<?php $state = (string) ($data['database_state'] ?? 'missing'); ?>
<?php if ($state === 'missing') : ?>
    <p>Install the module SQL before configuring Chain.</p>
    <form method="post" action="/admin/explorer">
        <?= $this->csrf_field(); ?>
        <input type="hidden" name="action" value="install_sql">
        <button type="submit">Install SQL</button>
    </form>
<?php elseif ($state === 'update') : ?>
    <p>Update SQL is required. Apply the signed Explorer update through Core's module updater.</p>
    <p><a href="/admin/modules">Open Core module updates</a></p>
<?php elseif ($state === 'invalid') : ?>
    <p role="alert">The database schema is incomplete, its version is invalid, or no migration path exists. Administrator recovery is required; installation will not overwrite existing tables.</p>
<?php else : ?>
    <h2>Chain connection</h2>
    <p>Configure the primary node and an optional fallback. Use numeric IPv4 or IPv6 addresses and the node's STNC RPC port, not a website URL.</p>
    <?php $configuration = $data['configuration'] ?? []; ?>
    <form method="post" action="/admin/explorer">
        <?= $this->csrf_field(); ?>
        <input type="hidden" name="action" value="save_configuration">
        <?php foreach (['primary' => 'Primary node', 'secondary' => 'Fallback node (optional)'] as $key => $label) : ?>
        <fieldset>
            <legend><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></legend>
            <label for="<?= $key; ?>_ip">IP address</label>
            <input id="<?= $key; ?>_ip" name="<?= $key; ?>_ip" type="text" maxlength="45" value="<?= htmlspecialchars((string) ($configuration[$key . '_ip'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" <?= $key === 'primary' ? 'required' : ''; ?>>
            <label for="<?= $key; ?>_port">STNC RPC port</label>
            <input id="<?= $key; ?>_port" name="<?= $key; ?>_port" type="number" min="1" max="65535" value="<?= htmlspecialchars((string) ($configuration[$key . '_port'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" <?= $key === 'primary' ? 'required' : ''; ?>>
        </fieldset>
        <?php endforeach; ?>
        <button type="submit">Save configuration</button>
    </form>
    <h2>Connection status</h2>
    <p role="status"><?= htmlspecialchars($data['chain']['message'], ENT_QUOTES, 'UTF-8'); ?></p>
    <?php if (($data['chain']['state'] ?? '') === 'online') : ?>
        <p>Responding node: <?= htmlspecialchars($data['chain']['source'], ENT_QUOTES, 'UTF-8'); ?></p>
        <dl>
        <?php foreach ($data['chain']['info'] as $label => $value) : ?>
            <dt><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></dt>
            <dd style="overflow-wrap:anywhere"><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?></dd>
        <?php endforeach; ?>
        </dl>
    <?php endif; ?>
    <h2>Data lifecycle</h2>
    <p>Delete Data clears module records and preserves connection settings. Nuke removes the module and its owned tables through Core.</p>
    <form method="post" action="/admin/explorer" onsubmit="return confirm('Delete module records? Chain configuration will be preserved.');">
        <?= $this->csrf_field(); ?>
        <input type="hidden" name="action" value="delete_data">
        <button type="submit">Delete Data</button>
    </form>
<?php endif; ?>
    <form method="post" action="/admin/uninstall" onsubmit="return confirm('Nuke this module?');">
        <?= $this->csrf_field(); ?>
        <input type="hidden" name="module" value="explorer">
        <button type="submit">Nuke</button>
    </form>
</main>
<?php
if (!theme::render('foot', get_defined_vars())) {
    require APPROOT . '/views/inc/foot.php';
}
