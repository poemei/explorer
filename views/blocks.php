<?php
declare(strict_types=1);

if (!theme::render('head', get_defined_vars())) {
    require APPROOT . '/views/inc/head.php';
}

$blocks = is_array($blocks ?? null)
    ? $blocks
    : [];
?>

<style>
.explorer-blocks {
    max-width: 100%;
}

.explorer-blocks .blocks-navigation {
    margin: 1rem 0;
}

.explorer-blocks .blocks-table-wrap {
    width: 100%;
    overflow-x: auto;
}

.explorer-blocks table {
    width: 100%;
    border-collapse: collapse;
}

.explorer-blocks th,
.explorer-blocks td {
    padding: 0.65rem 0.75rem;
    text-align: left;
    vertical-align: top;
}

.explorer-blocks th {
    font-weight: 700;
}

.explorer-blocks .block-id {
    overflow-wrap: anywhere;
    word-break: break-word;
}

.explorer-blocks .empty {
    margin: 1rem 0;
}
</style>

<main class="explorer-blocks">
    <h1>Blocks</h1>

    <p class="blocks-navigation">
        <a href="/explorer">← Explorer</a>
    </p>

    <?php if ($blocks === []): ?>
        <p class="empty">
            No blocks are currently indexed.
        </p>
    <?php else: ?>
        <div class="blocks-table-wrap">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Height</th>
                        <th scope="col">Block ID</th>
                        <th scope="col">Timestamp</th>
                        <th scope="col">Transactions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($blocks as $entry): ?>
                        <?php
                        if (!is_array($entry)) {
                            continue;
                        }

                        $blockId = strtolower(
                            trim(
                                (string) (
                                    $entry['block_id']
                                    ?? ''
                                )
                            )
                        );

                        if (
                            preg_match(
                                '/^[0-9a-f]{64}$/',
                                $blockId
                            ) !== 1
                        ) {
                            continue;
                        }

                        $timestamp = trim(
                            (string) (
                                $entry['timestamp']
                                ?? ''
                            )
                        );

                        $timestampDisplay = $timestamp;

                        if (
                            $timestamp !== ''
                            && preg_match(
                                '/^[0-9]+$/',
                                $timestamp
                            ) === 1
                            && strlen($timestamp) <= 10
                        ) {
                            $unixTimestamp = (int) $timestamp;

                            if ($unixTimestamp >= 0) {
                                $timestampDisplay = gmdate(
                                    'Y-m-d H:i:s \U\T\C',
                                    $unixTimestamp
                                );
                            }
                        }
                        ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars(
                                    (string) (
                                        $entry['height']
                                        ?? ''
                                    ),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>
                            </td>

                            <td class="block-id">
                                <a href="/explorer/block/<?= rawurlencode(
                                    $blockId
                                ); ?>">
                                    <?= htmlspecialchars(
                                        $blockId,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ); ?>
                                </a>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $timestampDisplay,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    (string) (
                                        $entry['transaction_count']
                                        ?? ''
                                    ),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>

<?php
if (!theme::render('foot', get_defined_vars())) {
    require APPROOT . '/views/inc/foot.php';
}
