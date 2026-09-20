<?php
declare(strict_types=1);

final class explorer extends controller
{
    private const ADMIN_ACTIONS = [
        'install_sql',
        'update_sql',
        'delete_data',
    ];

    public function index(array $params = []): void
    {
        $model = $this->model('explorer_model');

        if ($model->databaseState() !== 'current') {
            http_response_code(503);
            $this->error_page('This module is temporarily unavailable.');
        }

        $this->view('index', ['params' => $params]);
    }

    public function admin(array $params = []): void
    {
        $this->require_admin(7);
        $model = $this->model('explorer_model');
        $state = $model->databaseState();

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->require_csrf();
            $action = (string) ($_POST['action'] ?? '');

            if (!in_array($action, self::ADMIN_ACTIONS, true)) {
                http_response_code(400);
                $this->error_page('Invalid module action.');
            }

            if ($action === 'install_sql') {
                if ($state !== 'missing') {
                    $this->error_page('Schema is already installed.');
                }
                $model->installSchema();
                header('Location: /admin/explorer?installed=1');
                exit;
            }

            if ($action === 'update_sql') {
                if ($state !== 'update') {
                    $this->error_page('No schema update is pending.');
                }
                $model->updateSchema();
                header('Location: /admin/explorer?updated=1');
                exit;
            }

            if ($state !== 'current') {
                $this->error_page('Complete the database lifecycle action first.');
            }

            if ($action === 'delete_data') {
                $model->deleteData();
                header('Location: /admin/explorer?deleted_data=1');
                exit;
            }
        }

        $this->view('admin/explorer', [
            'database_state' => $state,
            'installed' => isset($_GET['installed']),
            'updated' => isset($_GET['updated']),
            'deleted_data' => isset($_GET['deleted_data']),
        ]);
    }
}
