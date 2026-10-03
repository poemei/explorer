<?php

if (!theme::render('head', get_defined_vars())) {
    require APPROOT . '/views/inc/head.php';
}
?>
<main class="container py-4">
    <h1>Chain Explorer administration</h1>

    <p><a href="/explorer">View Explorer</a></p>

    <?php if (!empty($data['error'])) : ?>
        <p role="alert"><?= htmlspecialchars($data['error'], ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <?php if (!empty($data['saved'])) : ?><p role="status">Chain configuration saved.</p><?php endif; ?>
    <?php if (!empty($data['stratum_saved'])) : ?><p role="status">Stratum configuration saved.</p><?php endif; ?>
    <?php if (!empty($data['installed'])) : ?><p role="status">SQL installed.</p><?php endif; ?>
    <?php if (!empty($data['updated'])) : ?><p role="status">SQL updated.</p><?php endif; ?>
    <?php if (!empty($data['deleted_data'])) : ?><p role="status">Module data deleted. Chain and Stratum configuration preserved.</p><?php endif; ?>

<?php $state = (string) ($data['database_state'] ?? 'missing'); ?>

<?php if ($state === 'missing') : ?>
    <p>Install the module SQL before configuring Explorer.</p>
    <form method="post" action="/admin/explorer">
        <?= $this->csrf_field(); ?>
        <input type="hidden" name="action" value="install_sql">
        <button type="submit">Install SQL</button>
    </form>
<?php elseif ($state === 'update') : ?>
    <p>Update SQL is required before Explorer can continue.</p>
    <form method="post" action="/admin/explorer">
        <?= $this->csrf_field(); ?>
        <input type="hidden" name="action" value="update_sql">
        <button type="submit">Update SQL</button>
    </form>
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
        <button type="submit">Save Chain configuration</button>
    </form>

    <h2>Chain status</h2>
    <p role="status"><?= htmlspecialchars((string) ($data['chain']['message'] ?? 'Blockchain status unavailable.'), ENT_QUOTES, 'UTF-8'); ?></p>
    <?php if (($data['chain']['state'] ?? '') === 'online') : ?>
        <p>Responding node: <?= htmlspecialchars((string) ($data['chain']['source'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
        <dl>
        <?php foreach (($data['chain']['info'] ?? []) as $label => $value) : ?>
            <dt><?= htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8'); ?></dt>
            <dd style="overflow-wrap:anywhere"><?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); ?></dd>
        <?php endforeach; ?>
        </dl>
    <?php elseif (!empty($data['chain']['diagnostics']) && is_array($data['chain']['diagnostics'])) : ?>
        <h3>Chain diagnostics</h3>
        <dl>
        <?php foreach ($data['chain']['diagnostics'] as $endpoint => $diagnostic) : ?>
            <?php if (!is_array($diagnostic)) { continue; } ?>
            <dt><?= htmlspecialchars(ucfirst((string) $endpoint) . ' node', ENT_QUOTES, 'UTF-8'); ?></dt>
            <dd>
                <strong>Result:</strong> <?= htmlspecialchars((string) ($diagnostic['result'] ?? 'unknown'), ENT_QUOTES, 'UTF-8'); ?>
                <?php if (isset($diagnostic['status'])) : ?><br><strong>STNC status:</strong> <?= htmlspecialchars((string) $diagnostic['status'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
                <?php if (!empty($diagnostic['detail'])) : ?><br><strong>Detail:</strong> <?= htmlspecialchars((string) $diagnostic['detail'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
            </dd>
        <?php endforeach; ?>
        </dl>
    <?php endif; ?>

    <hr>
    <h2>Stratum</h2>
    <p>Configure the read-only STN-Stratum status API used by Explorer to observe mining infrastructure.</p>
    <?php $stratumConfiguration = $data['stratum_configuration'] ?? []; ?>
    <form method="post" action="/admin/explorer">
        <?= $this->csrf_field(); ?>
        <input type="hidden" name="action" value="save_stratum_configuration">
        <fieldset>
            <legend>Stratum API</legend>
            <label for="stratum_host">Host</label>
            <input id="stratum_host" name="stratum_host" type="text" maxlength="255" value="<?= htmlspecialchars((string) ($stratumConfiguration['stratum_host'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
            <label for="stratum_port">Port</label>
            <input id="stratum_port" name="stratum_port" type="number" min="1" max="65535" value="<?= htmlspecialchars((string) ($stratumConfiguration['stratum_port'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
        </fieldset>
        <button type="submit">Save Stratum configuration</button>
    </form>

    <h2>Stratum status</h2>
    <p role="status"><?= htmlspecialchars((string) ($data['stratum']['message'] ?? 'Stratum status unavailable.'), ENT_QUOTES, 'UTF-8'); ?></p>
    <?php if (($data['stratum']['state'] ?? '') === 'online') : ?>
        <?php $stratumInfo = is_array($data['stratum']['info'] ?? null) ? $data['stratum']['info'] : []; ?>
        <dl>
            <dt>Service</dt><dd><?= htmlspecialchars((string) ($stratumInfo['service'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></dd>
            <dt>Status</dt><dd><?= htmlspecialchars((string) ($stratumInfo['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></dd>
            <dt>Chain connected</dt><dd><?= !empty($stratumInfo['chain_connected']) ? 'Yes' : 'No'; ?></dd>
            <dt>Chain host</dt><dd><?= htmlspecialchars((string) ($stratumInfo['chain_host'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></dd>
            <dt>Chain port</dt><dd><?= htmlspecialchars((string) ($stratumInfo['chain_port'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></dd>
            <dt>Work available</dt><dd><?= !empty($stratumInfo['work_available']) ? 'Yes' : 'No'; ?></dd>
            <dt>Connected miners</dt><dd><?= htmlspecialchars((string) ($stratumInfo['miners'] ?? '0'), ENT_QUOTES, 'UTF-8'); ?></dd>
            <dt>Job ID</dt><dd style="overflow-wrap:anywhere"><?= htmlspecialchars((string) ($stratumInfo['job_id'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></dd>
            <dt>Base ID</dt><dd style="overflow-wrap:anywhere"><?= htmlspecialchars((string) ($stratumInfo['base_id'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></dd>
            <dt>Target</dt><dd style="overflow-wrap:anywhere"><?= htmlspecialchars((string) ($stratumInfo['target'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></dd>
            <dt>Uptime</dt><dd><?= htmlspecialchars((string) ($stratumInfo['uptime_seconds'] ?? '0'), ENT_QUOTES, 'UTF-8'); ?> seconds</dd>
        </dl>
    <?php endif; ?>

    <hr>
    <h2>Data lifecycle</h2>
    <p>Delete Data clears module records and preserves Chain and Stratum connection settings. Nuke removes the module and its owned tables through Core.</p>
    <form method="post" action="/admin/explorer" onsubmit="return confirm('Delete module records? Chain and Stratum configuration will be preserved.');">
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
