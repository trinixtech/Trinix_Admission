<?php

namespace App\Modules\Documents\Services;

use App\Modules\Applicants\Models\Application;
use App\Modules\Applicants\Services\ApplicationRecord;
use App\Modules\Documents\Models\ApplicationDocument;
use App\Modules\Documents\Models\DocumentRequirement;
use App\Support\Condition;

/** The document list of one application: what is required (given its details) and what has arrived. */
class DocumentChecklist
{
    public static function facts(Application $app): array
    {
        $app->loadMissing('course');

        return ApplicationRecord::snapshot($app) + ['course_level' => $app->course->level];
    }

    /** @return list<array<string, mixed>> */
    public function for(Application $app): array
    {
        $facts = self::facts($app);
        $docs = ApplicationDocument::with(['documentType', 'currentVersion', 'versions' => fn ($q) => $q->orderByDesc('version')])
            ->where('application_id', $app->id)->get()->keyBy('document_type_id');
        $rows = [];
        $reqs = DocumentRequirement::with('documentType')->where('course_id', $app->course_id)
            ->where('admission_cycle_id', $app->admission_cycle_id)->orderBy('sort_order')->get();

        foreach ($reqs as $req) {
            $applies = Condition::holds($req->condition, $facts);
            $doc = $docs->pull($req->document_type_id);
            if (! $applies && ! $doc) {
                continue;
            }
            $rows[] = $this->row($req->documentType, $doc, $applies && $req->required, $applies ? Condition::describe($req->condition) : null);
        }
        foreach ($docs as $doc) { // received but not in the requirements
            $rows[] = $this->row($doc->documentType, $doc, false, null);
        }

        return $rows;
    }

    /** Required documents that are missing (no file at all). */
    public function missing(Application $app): array
    {
        return array_values(array_filter($this->for($app), fn ($r) => $r['required'] && $r['status'] === 'missing'));
    }

    private function row($type, ?ApplicationDocument $doc, bool $required, ?string $when): array
    {
        return [
            'document_type' => ['uid' => $type->uid, 'code' => $type->code, 'name' => $type->name],
            'required' => $required,
            'condition' => $when,
            'uid' => $doc?->uid,
            'status' => $doc?->status ?? 'missing',
            'current' => $doc?->currentVersion ? self::version($doc->currentVersion) : null,
            'versions' => $doc ? $doc->versions->map(fn ($v) => self::version($v))->values()->all() : [],
        ];
    }

    public static function version($v): array
    {
        return [
            'uid' => $v->uid, 'version' => $v->version, 'filename' => $v->original_filename, 'mime_type' => $v->mime_type,
            'size_bytes' => $v->size_bytes, 'source' => $v->source, 'scan_status' => $v->scan_status,
            'created_at' => $v->created_at?->toIso8601String(), 'superseded' => $v->superseded_at !== null,
        ];
    }
}
