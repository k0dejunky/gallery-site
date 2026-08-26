<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Models\SiteTemplate;

class SiteEditorController extends Controller
{
    public function __construct(Request $request)
    {
        parent::__construct($request);
        Auth::requirePermission('site_editor');
    }

    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    private function scope(): string
    {
        $s = $this->request->query('scope', $this->request->post('scope', SiteTemplate::SCOPE_USER));
        return in_array($s, [SiteTemplate::SCOPE_USER, SiteTemplate::SCOPE_ADMIN], true) ? $s : SiteTemplate::SCOPE_USER;
    }

    public function editor(): void
    {
        SiteTemplate::seedDefaults();
        $scope = $this->scope();
        $templates = SiteTemplate::all($scope);
        $active = SiteTemplate::active($scope);
        $siteUrl = $scope === SiteTemplate::SCOPE_ADMIN ? url('/admin') : url('/galleries');
        $this->viewAdmin('site_editor', [
            'templates'      => $templates,
            'activeTemplate' => $active,
            'siteUrl'        => $siteUrl,
            'scope'          => $scope,
        ]);
    }

    public function templates(): void
    {
        $scope = $this->scope();
        $this->json([
            'templates' => SiteTemplate::all($scope),
            'active'    => SiteTemplate::active($scope),
            'scope'     => $scope,
        ]);
    }

    public function save(): void
    {
        $name = trim((string) $this->request->post('name', ''));
        $description = trim((string) $this->request->post('description', ''));
        $config = $this->request->post('config', '[]');
        $scope = $this->scope();

        if ($name !== '') {
            $id = SiteTemplate::create($name, $description, $config, $scope);
            $this->json(['ok' => true, 'id' => $id]);
            return;
        }

        $active = SiteTemplate::active($scope);
        if ($active === null) {
            $this->json(['error' => 'No active template to update. Enter a name to create one.'], 422);
            return;
        }
        SiteTemplate::update((int) $active['id'], $active['name'], $description !== '' ? $description : $active['description'], $config);
        $this->json(['ok' => true, 'id' => (int) $active['id'], 'updated' => true]);
    }

    public function update(int $id): void
    {
        $template = SiteTemplate::find($id);
        if ($template === null) { $this->json(['error' => 'Not found.'], 404); return; }
        $name = trim((string) $this->request->post('name', $template['name']));
        $description = trim((string) $this->request->post('description', ''));
        $config = $this->request->post('config', $template['config_json']);
        SiteTemplate::update($id, $name, $description, $config);
        $this->json(['ok' => true]);
    }

    public function delete(int $id): void
    {
        SiteTemplate::delete($id);
        $this->json(['ok' => true]);
    }

    public function activate(int $id): void
    {
        SiteTemplate::activate($id);
        $this->json(['ok' => true]);
    }

    public function deactivate(): void
    {
        $scope = $this->scope();
        SiteTemplate::deactivateAll($scope);
        $this->json(['ok' => true]);
    }
}
