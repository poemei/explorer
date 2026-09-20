<?php
declare(strict_types=1);

final class explorer extends controller
{
    private const ADMIN_ACTIONS = [
        'install_sql',
        'delete_data',
        'save_configuration',
    ];

    public function index(array $params = []): void
    {
        $model = $this->model('explorer_model');

        $this->view('index', ['params' => $params, 'chain' => $model->chainStatus($model->databaseState())]);
    }

    public function admin(array $params = []): void
    {
        $this->require_admin(7);
        $model = $this->model('explorer_model');
        $state = $model->databaseState();
        $error = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->require_csrf();
            $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

            if (!in_array($action, self::ADMIN_ACTIONS, true)) {
                http_response_code(400);
                $this->error_page('Invalid module action.');
                return;
            }

            if ($action === 'install_sql') {
                if ($state !== 'missing') {
                    $this->error_page('Schema is already installed.');
                    return;
                }
                $model->installSchema();
                header('Location: /admin/explorer?installed=1');
                exit;
            }

            if ($state !== 'current') {
                $this->error_page('Complete the database lifecycle action first.');
                return;
            }

            if ($action === 'delete_data') {
                $model->deleteData();
                header('Location: /admin/explorer?deleted_data=1');
                exit;
            }
            if ($action === 'save_configuration') {
                try {
                    $model->saveConfiguration($_POST);
                    header('Location: /admin/explorer?saved=1');
                    exit;
                } catch (InvalidArgumentException $e) {
                    http_response_code(422);
                    $error = $e->getMessage();
                }
            }
        }

        $this->view('admin/explorer', [
            'database_state' => $state,
            'configuration' => $state === 'current' ? $model->configuration() : [],
            'chain' => $model->chainStatus($state),
            'error' => $error,
            'saved' => isset($_GET['saved']),
            'installed' => isset($_GET['installed']),
            'deleted_data' => isset($_GET['deleted_data']),
        ]);
    }
}
