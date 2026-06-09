<?php
class AdminManualController extends Controller
{
    public function index(): void
    {
        Auth::requireRole(['admin', 'rh', 'viewer']);
        $this->view->render('admin/manual', [], 'layouts/admin');
    }
}
