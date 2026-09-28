<?php
declare(strict_types=1);

final class explorer extends controller
{
    private const ADMIN_ACTIONS = [
        'install_sql',
        'delete_data',
        'save_configuration',
        'save_stratum_configuration',
    ];

    public function index(array $params = []): void
    {
        $model = $this->model('explorer_model');

        $this->view('index', [
            'params' => $params,
            'chain' => $model->chainStatus(
                $model->databaseState()
            ),
            'latest_block' => $model->latestBlock(),
        ]);
    }
	
    public function blocks(array $params = []): void
    {
        $model = $this->model('explorer_model');
        $state = $model->databaseState();

        if ($state !== 'current') {
            $this->view('blocks', [
                'params' => $params,
                'blocks' => [],
                'sync' => [
                    'state' => 'unavailable',
                    'indexed' => 0,
                ],
            ]);
            return;
        }

        try {
            $sync = $model->syncBlocks();
            $blocks = $model->blocks();
        } catch (Throwable $exception) {
            $sync = [
                'state' => 'unavailable',
                'indexed' => 0,
            ];
            $blocks = [];
        }

        $this->view('blocks', [
            'params' => $params,
            'blocks' => $blocks,
            'sync' => $sync,
        ]);
    }

    public function block(array $params = []): void
    {
        $blockId = is_string($params[0] ?? null)
            ? strtolower(trim($params[0]))
            : '';

        if (
            preg_match(
                '/^[0-9a-f]{64}$/',
                $blockId
            ) !== 1
        ) {
            http_response_code(404);
            $this->error_page('Block not found.');
            return;
        }

        $model = $this->model('explorer_model');
        $result = $model->blockById($blockId);
        $state = (string) ($result['state'] ?? '');

        if ($state === 'not_found') {
            http_response_code(404);
            $this->error_page('Block not found.');
            return;
        }

        if ($state === 'invalid') {
            http_response_code(404);
            $this->error_page('Block not found.');
            return;
        }

        if ($state !== 'found') {
            http_response_code(503);
            $this->error_page(
                (string) (
                    $result['message']
                    ?? 'Explorer data unavailable.'
                )
            );
            return;
        }

        $this->view('block', [
            'block_id' => $blockId,
            'block' => $result['block'],
        ]);
    }

    public function admin(array $params = []): void
    {
        $this->require_admin(7);

        $model = $this->model('explorer_model');
        $state = $model->databaseState();
        $error = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->require_csrf();

            $action = is_string($_POST['action'] ?? null)
                ? $_POST['action']
                : '';

            if (!in_array($action, self::ADMIN_ACTIONS, true)) {
                http_response_code(400);
                $this->error_page('Invalid module action.');
                return;
            }

            if ($action === 'install_sql') {
                if ($state !== 'missing') {
                    $this->error_page(
                        'Schema is already installed.'
                    );
                    return;
                }

                $model->installSchema();

                header(
                    'Location: /admin/explorer?installed=1'
                );
                exit;
            }

            if ($state !== 'current') {
                $this->error_page(
                    'Complete the database lifecycle action first.'
                );
                return;
            }

            if ($action === 'delete_data') {
                $model->deleteData();

                header(
                    'Location: /admin/explorer?deleted_data=1'
                );
                exit;
            }

            if ($action === 'save_configuration') {
                try {
                    $model->saveConfiguration($_POST);

                    header(
                        'Location: /admin/explorer?saved=1'
                    );
                    exit;
                } catch (InvalidArgumentException $e) {
                    http_response_code(422);
                    $error = $e->getMessage();
                }
            }

            if ($action === 'save_stratum_configuration') {
                try {
                    $model->saveStratumConfiguration($_POST);

                    header(
                        'Location: /admin/explorer?stratum_saved=1'
                    );
                    exit;
                } catch (InvalidArgumentException $e) {
                    http_response_code(422);
                    $error = $e->getMessage();
                }
            }
        }

        $this->view('admin/explorer', [
            'database_state' => $state,

            'configuration' => $state === 'current'
                ? $model->configuration()
                : [],

            'chain' => $model->chainStatus($state),

            'stratum_configuration' => $state === 'current'
                ? $model->stratumConfiguration()
                : [],

            'stratum' => $model->stratumStatus($state),

            'error' => $error,
            'saved' => isset($_GET['saved']),
            'stratum_saved' => isset(
                $_GET['stratum_saved']
            ),
            'installed' => isset($_GET['installed']),
            'deleted_data' => isset(
                $_GET['deleted_data']
            ),
        ]);
    }
}