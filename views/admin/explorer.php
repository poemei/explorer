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
    <h1>Chain Explorer</h1>
<?php $state = (string) ($data['database_state'] ?? 'missing'); ?>
<?php if ($state === 'missing') : ?>
    <form method="post" action="/admin/{{SLUG}}">
        <?= $this->csrf_field(); ?>
        <input type="hidden" name="action" value="install_sql">
        <button type="submit">Install SQL</button>
    </form>
<?php elseif ($state === 'update') : ?>
    <form method="post" action="/admin/{{SLUG}}">
        <?= $this->csrf_field(); ?>
        <input type="hidden" name="action" value="update_sql">
        <button type="submit">Update SQL</button>
    </form>
<?php elseif ($state === 'invalid') : ?>
    <p role="alert">The database schema version is invalid or no migration path exists.</p>
<?php else : ?>
    <p>Module administration.</p>
    <form method="post" action="/admin/{{SLUG}}" onsubmit="return confirm('Delete all module data?');">
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
