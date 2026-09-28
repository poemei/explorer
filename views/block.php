<?php
declare(strict_types=1);

if (!theme::render('head', get_defined_vars())) {
    require APPROOT . '/views/inc/head.php';
}

$blockId = is_string($block_id ?? null)
    ? $block_id
    : '';

$data = is_array($block ?? null)
    ? $block
    : [];

$previousBlockId = is_string(
    $data['previous_block_id'] ?? null
)
    ? $data['previous_block_id']
    : '';

$timestamp = is_string($data['timestamp'] ?? null)
    ? $data['timestamp']
    : '';

$timestampDisplay = $timestamp;

if (
    $timestamp !== ''
    && preg_match('/^[0-9]+$/', $timestamp) === 1
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

<style>
.explorer-block {
    max-width: 100%;
}

.explorer-block dl {
    display: grid;
    grid-template-columns: minmax(10rem, 14rem) minmax(0, 1fr);
    gap: 0.65rem 1rem;
    margin: 1rem 0;
}

.explorer-block dt {
    font-weight: 700;
}

.explorer-block dd {
    margin: 0;
    min-width: 0;
}

.explorer-block .block-value {
    overflow-wrap: anywhere;
    word-break: break-word;
}

.explorer-block .block-navigation {
    margin: 1rem 0;
}

@media (max-width: 700px) {
    .explorer-block dl {
        grid-template-columns: 1fr;
        gap: 0.2rem;
    }

    .explorer-block dd {
        margin-bottom: 0.75rem;
    }
}
</style>

<main class="explorer-block">
    <h1>Block</h1>

    <p class="block-navigation">
        <a href="/explorer">← Explorer</a>
    </p>

    <dl>
        <dt>Block ID</dt>
        <dd class="block-value">
            <?= htmlspecialchars(
                $blockId,
                ENT_QUOTES,
                'UTF-8'
            ); ?>
        </dd>

        <dt>Height</dt>
        <dd>
            <?= htmlspecialchars(
                (string) ($data['height'] ?? ''),
                ENT_QUOTES,
                'UTF-8'
            ); ?>
        </dd>

        <dt>Version</dt>
        <dd>
            <?= htmlspecialchars(
                (string) ($data['version'] ?? ''),
                ENT_QUOTES,
                'UTF-8'
            ); ?>
        </dd>

        <dt>Timestamp</dt>
        <dd>
            <?= htmlspecialchars(
                $timestampDisplay,
                ENT_QUOTES,
                'UTF-8'
            ); ?>
        </dd>

        <dt>Previous Block</dt>
        <dd class="block-value">
            <?php if (
                preg_match(
                    '/^[0-9a-f]{64}$/',
                    $previousBlockId
                ) === 1
                && preg_match(
                    '/^0{64}$/',
                    $previousBlockId
                ) !== 1
            ): ?>
                <a href="/explorer/block/<?= rawurlencode(
                    $previousBlockId
                ); ?>">
                    <?= htmlspecialchars(
                        $previousBlockId,
                        ENT_QUOTES,
                        'UTF-8'
                    ); ?>
                </a>
            <?php else: ?>
                <?= htmlspecialchars(
                    $previousBlockId,
                    ENT_QUOTES,
                    'UTF-8'
                ); ?>
            <?php endif; ?>
        </dd>

        <dt>Network ID</dt>
        <dd class="block-value">
            <?= htmlspecialchars(
                (string) ($data['network_id'] ?? ''),
                ENT_QUOTES,
                'UTF-8'
            ); ?>
        </dd>

        <dt>Target</dt>
        <dd class="block-value">
            <?= htmlspecialchars(
                (string) ($data['target'] ?? ''),
                ENT_QUOTES,
                'UTF-8'
            ); ?>
        </dd>

        <dt>Work Nonce</dt>
        <dd>
            <?= htmlspecialchars(
                (string) ($data['work_nonce'] ?? ''),
                ENT_QUOTES,
                'UTF-8'
            ); ?>
        </dd>

        <dt>Transaction Count</dt>
        <dd>
            <?= htmlspecialchars(
                (string) (
                    $data['transaction_count']
                    ?? ''
                ),
                ENT_QUOTES,
                'UTF-8'
            ); ?>
        </dd>

        <dt>Transaction Commitment</dt>
        <dd class="block-value">
            <?= htmlspecialchars(
                (string) (
                    $data['transaction_commitment']
                    ?? ''
                ),
                ENT_QUOTES,
                'UTF-8'
            ); ?>
        </dd>

        <dt>Body Length</dt>
        <dd>
            <?= htmlspecialchars(
                (string) ($data['body_length'] ?? ''),
                ENT_QUOTES,
                'UTF-8'
            ); ?>
            bytes
        </dd>
    </dl>
</main>

<?php
if (!theme::render('foot', get_defined_vars())) {
    require APPROOT . '/views/inc/foot.php';
}
