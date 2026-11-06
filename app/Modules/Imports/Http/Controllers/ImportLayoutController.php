<?php

namespace App\Modules\Imports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Imports\Services\ImportLayouts;
use App\Support\Compat;
use App\Support\Printable;
use App\Support\Spreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Imports → Import templates: the admin's student and faculty templates, the template file and its instructions. */
class ImportLayoutController extends Controller
{
    public function __construct(private ImportLayouts $layouts) {}

    /** Students: imports.manage edits, imports.view sees. Faculty: users.manage. */
    private function authorizeKind(string $kind, bool $edit): void
    {
        abort_unless(in_array($kind, ImportLayouts::KINDS, true), 404);
        $actor = $this->actor();
        if ($kind === 'faculty') {
            $this->access()->authorizeAnywhere($actor, 'users.manage');
        } elseif ($edit || ! $this->access()->hasAnywhere($actor, 'imports.manage')) {
            $this->access()->authorizeAnywhere($actor, $edit ? 'imports.manage' : 'imports.view');
        }
    }

    public function show(string $kind): JsonResponse
    {
        $this->authorizeKind($kind, false);

        return response()->json(['data' => ['columns' => $this->layouts->columns($kind), 'types' => ImportLayouts::CUSTOM_TYPES, 'notes' => $this->layouts->notes($kind),
            'format' => Compat::writeXlsx() && config('exports.format') !== 'csv' ? 'xlsx' : 'csv']]);
    }

    public function update(Request $request, string $kind): JsonResponse
    {
        $this->authorizeKind($kind, true);
        $data = $request->validate([
            'columns' => ['required', 'array', 'max:200'],
            'columns.*' => ['array'],
            'columns.*.field' => ['sometimes', 'string', 'max:64'],
            'columns.*.custom' => ['nullable', 'string', 'max:64'],
            'columns.*.header' => ['nullable', 'string', 'max:200'],
            'columns.*.include' => ['sometimes', 'boolean'],
            'columns.*.type' => ['sometimes', 'string', 'max:16'],
            'columns.*.options' => ['sometimes', 'array', 'max:60'],
            'columns.*.options.*' => ['nullable', 'string', 'max:100'],
            'columns.*.required' => ['sometimes', 'boolean'],
            'columns.*.help' => ['nullable', 'string', 'max:300'],
        ]);

        return response()->json(['data' => ['columns' => $this->layouts->save($kind, $data['columns'], $this->actor())], 'message' => 'Template saved. Download it again to get the new columns.']);
    }

    /** The template: an empty sheet with the headings, and (in Excel) a second sheet with the instructions. */
    public function template(string $kind): Response
    {
        $this->authorizeKind($kind, false);
        $headers = array_map(fn ($c) => $c['header'], $this->layouts->included($kind));
        $name = ($kind === 'faculty' ? 'faculty' : 'student').'-import-template';
        if (! Compat::writeXlsx() || config('exports.format') === 'csv') {
            return Spreadsheet::download($name, $headers, []); // a CSV holds one table: the instructions are a separate page
        }
        $notes = array_map(fn ($n) => [$n, '', '', ''], $this->layouts->notes($kind));

        return Spreadsheet::sheets($name, [
            ['title' => 'Template', 'columns' => $headers, 'rows' => []],
            ['title' => 'Instructions', 'columns' => ['Column', 'Required', 'What to enter', 'Example'],
                'rows' => [...$this->layouts->instructionRows($kind), ['', '', '', ''], ['How to fill it', '', '', ''], ...$notes]],
        ]);
    }

    /** The instructions as a printout (PDF, or a page to print when the server cannot make PDFs). */
    public function instructions(string $kind): Response
    {
        $this->authorizeKind($kind, false);
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $title = $kind === 'faculty' ? 'Faculty import template: instructions' : 'Student import template: instructions';
        $rows = '';
        foreach ($this->layouts->instructionRows($kind) as $i => [$col, $req, $what, $ex]) {
            $rows .= '<tr><td>'.($i + 1).'</td><td><b>'.$e($col).'</b></td><td>'.$e($req).'</td><td>'.$e($what).'</td><td>'.$e($ex).'</td></tr>';
        }
        $notes = implode('', array_map(fn ($n) => '<li>'.$e($n).'</li>', $this->layouts->notes($kind)));
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>'.$e($title).'</title><style>'
            .'body{font-family:DejaVu Sans,Arial,sans-serif;font-size:11px;color:#111}h1{font-size:16px;margin:0 0 6px}'
            .'table{width:100%;border-collapse:collapse;margin-top:10px}th,td{border:1px solid #999;padding:4px 5px;text-align:left;vertical-align:top}'
            .'th{background:#eee}ul{padding-left:18px}</style></head><body>'
            .'<h1>'.$e($title).'</h1><p>'.$e(config('app.name')).' · '.$e(now(config('app.display_timezone'))->format('d-m-Y')).'</p><ul>'.$notes.'</ul>'
            .'<table><thead><tr><th>#</th><th>Column heading</th><th>Required</th><th>What to enter</th><th>Example</th></tr></thead><tbody>'.$rows.'</tbody></table>'
            .'</body></html>';

        return Printable::response($html, ($kind === 'faculty' ? 'faculty' : 'student').'-import-instructions');
    }
}
