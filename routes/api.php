<?php

use App\Modules\Access\Http\Controllers\FacultyImportController;
use App\Modules\Access\Http\Controllers\InvitationController;
use App\Modules\Access\Http\Controllers\MeController;
use App\Modules\Access\Http\Controllers\RoleController;
use App\Modules\Access\Http\Controllers\UserController;
use App\Modules\Applicants\Http\Controllers\ApplicationController;
use App\Modules\Audit\Http\Controllers\AuditLogController;
use App\Modules\Cycles\Http\Controllers\AcademicYearController;
use App\Modules\Cycles\Http\Controllers\AdmissionCycleController;
use App\Modules\Cycles\Http\Controllers\AdmissionRoundController;
use App\Modules\Cycles\Http\Controllers\CourseOfferingController;
use App\Modules\Documents\Http\Controllers\DocumentController;
use App\Modules\Documents\Http\Controllers\DocumentTypeController;
use App\Modules\Imports\Http\Controllers\ImportBatchController;
use App\Modules\Imports\Http\Controllers\IntegrationController;
use App\Modules\Imports\Http\Controllers\SourceController;
use App\Modules\Imports\Http\Middleware\AuthenticateSource;
use App\Modules\Merit\Http\Controllers\MeritFormulaController;
use App\Modules\Merit\Http\Controllers\MeritListController;
use App\Modules\Organisation\Http\Controllers\BranchController;
use App\Modules\Organisation\Http\Controllers\BranchCourseController;
use App\Modules\Organisation\Http\Controllers\CourseController;
use App\Modules\Organisation\Http\Controllers\InstitutionController;
use App\Modules\Organisation\Http\Controllers\LocationController;
use App\Modules\Selection\Http\Controllers\AutoAllocationController;
use App\Modules\Selection\Http\Controllers\EvaluationController;
use App\Modules\Selection\Http\Controllers\PanelController;
use App\Modules\Selection\Http\Controllers\StageController;
use App\Modules\System\Http\Controllers\HealthController;
use App\Modules\System\Http\Controllers\MaintenanceController;
use App\Modules\System\Http\Controllers\SettingController;
use App\Modules\Teams\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

// Sign-in, sign-out, password reset and two-factor routes come from Laravel Fortify under the same /api/v1 prefix.

Route::prefix('v1')->group(function () {
    Route::post('set-password', [InvitationController::class, 'accept'])->middleware('throttle:10,1')->name('invitation.accept');

    // Portal push (source token, not a user session).
    Route::prefix('integration')->middleware([AuthenticateSource::class, 'throttle:source-push'])->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)->group(function () {
        Route::post('import-batches', [IntegrationController::class, 'push']);
        Route::get('import-batches/{batch}', [IntegrationController::class, 'show']);
        Route::post('document-batches', [IntegrationController::class, 'pushDocuments']);
    });

    Route::middleware(['auth:sanctum', 'account.ready', 'throttle:api'])->group(function () {
        Route::get('me', [MeController::class, 'show'])->name('me.show');
        Route::patch('me', [MeController::class, 'update'])->name('me.update');

        // Organisation
        Route::get('institution', [InstitutionController::class, 'show']);
        Route::put('institution', [InstitutionController::class, 'update']);
        Route::apiResource('locations', LocationController::class)->except('destroy');
        Route::apiResource('branches', BranchController::class)->except('destroy');
        Route::apiResource('courses', CourseController::class)->except('destroy');
        Route::get('branch-courses', [BranchCourseController::class, 'index']);
        Route::put('branch-courses', [BranchCourseController::class, 'update']);

        // Cycles and rounds
        Route::get('academic-years', [AcademicYearController::class, 'index']);
        Route::post('academic-years', [AcademicYearController::class, 'store']);
        Route::patch('academic-years/{academicYear}', [AcademicYearController::class, 'update']);
        Route::apiResource('admission-cycles', AdmissionCycleController::class)->except('destroy')
            ->parameters(['admission-cycles' => 'admissionCycle']);
        Route::post('admission-cycles/{admissionCycle}/open', [AdmissionCycleController::class, 'open']);
        Route::post('admission-cycles/{admissionCycle}/close', [AdmissionCycleController::class, 'close']);
        Route::get('admission-cycles/{admissionCycle}/offerings', [CourseOfferingController::class, 'index']);
        Route::post('admission-cycles/{admissionCycle}/offerings', [CourseOfferingController::class, 'store']);
        Route::patch('offerings/{courseOffering}', [CourseOfferingController::class, 'update']);
        Route::get('admission-cycles/{admissionCycle}/rounds', [AdmissionRoundController::class, 'index']);
        Route::post('admission-cycles/{admissionCycle}/rounds', [AdmissionRoundController::class, 'store']);
        Route::get('rounds/{round}', [AdmissionRoundController::class, 'show']);
        Route::patch('rounds/{round}', [AdmissionRoundController::class, 'update']);
        Route::post('rounds/{round}/activate', [AdmissionRoundController::class, 'activate']);
        Route::put('rounds/{round}/offerings', [AdmissionRoundController::class, 'updateOfferings']);
        Route::post('rounds/{round}/close', [AdmissionRoundController::class, 'close']);
        Route::post('rounds/{round}/reopen', [AdmissionRoundController::class, 'reopen']);

        // People
        Route::post('faculty-imports', [FacultyImportController::class, 'preview']);
        Route::post('faculty-imports/{token}/confirm', [FacultyImportController::class, 'confirm'])->where('token', '[0-9A-Za-z]{26}');
        Route::apiResource('users', UserController::class)->except('destroy');
        Route::post('users/{user}/suspend', [UserController::class, 'suspend']);
        Route::post('users/{user}/reactivate', [UserController::class, 'reactivate']);
        Route::post('users/{user}/unlock', [UserController::class, 'unlock']);
        Route::post('users/{user}/resend-invite', [UserController::class, 'resendInvite']);
        Route::get('users/{user}/assignments', [UserController::class, 'assignments']);
        Route::post('users/{user}/assignments', [UserController::class, 'storeAssignment']);
        Route::post('assignments/{assignment}/revoke', [UserController::class, 'revokeAssignment']);
        Route::get('roles', [RoleController::class, 'index']);
        Route::get('permissions', [RoleController::class, 'permissions']);
        Route::put('roles/{role}/permissions', [RoleController::class, 'updatePermissions']);
        Route::get('role-conflicts', [RoleController::class, 'conflicts']);
        Route::put('role-conflicts', [RoleController::class, 'updateConflicts']);

        // Teams
        Route::apiResource('teams', TeamController::class)->except('destroy');
        Route::get('teams/{team}/members', [TeamController::class, 'members']);
        Route::post('teams/{team}/members', [TeamController::class, 'addMember']);
        Route::post('team-members/{teamMember}/remove', [TeamController::class, 'removeMember']);

        // Audit
        Route::get('audit-logs', [AuditLogController::class, 'index']);
        Route::get('audit-logs/{id}', [AuditLogController::class, 'show'])->whereNumber('id');

        // Imports
        Route::get('import-fields', [SourceController::class, 'fields']);
        Route::get('sources', [SourceController::class, 'index']);
        Route::post('sources', [SourceController::class, 'store']);
        Route::patch('sources/{source}', [SourceController::class, 'update']);
        Route::get('sources/{source}/tokens', [SourceController::class, 'tokens']);
        Route::post('sources/{source}/tokens', [SourceController::class, 'createToken']);
        Route::post('source-tokens/{id}/revoke', [SourceController::class, 'revokeToken'])->whereNumber('id');
        Route::get('sources/{source}/mapping-templates', [SourceController::class, 'templates']);
        Route::post('sources/{source}/mapping-templates', [SourceController::class, 'storeTemplate']);
        Route::patch('mapping-templates/{template}', [SourceController::class, 'updateTemplate']);
        Route::get('import-batches', [ImportBatchController::class, 'index']);
        Route::post('import-batches', [ImportBatchController::class, 'store']);
        Route::get('import-batches/{batch}', [ImportBatchController::class, 'show']);
        Route::get('import-batches/{batch}/sample', [ImportBatchController::class, 'sample']);
        Route::put('import-batches/{batch}/mapping', [ImportBatchController::class, 'mapping']);
        Route::post('import-batches/{batch}/validate', [ImportBatchController::class, 'validateBatch']);
        Route::get('import-batches/{batch}/records', [ImportBatchController::class, 'records']);
        Route::get('import-batches/{batch}/errors', [ImportBatchController::class, 'errors']);
        Route::post('import-batches/{batch}/exclusions', [ImportBatchController::class, 'exclusions']);
        Route::post('import-batches/{batch}/approve', [ImportBatchController::class, 'approve']);
        Route::post('import-batches/{batch}/cancel', [ImportBatchController::class, 'cancel']);
        Route::post('import-batches/{batch}/retry', [ImportBatchController::class, 'retry']);

        // Applicants
        Route::get('applications', [ApplicationController::class, 'index']);
        Route::get('applications/{application}', [ApplicationController::class, 'show']);
        Route::post('applications/{application}/corrections', [ApplicationController::class, 'correct']);

        // Documents
        Route::get('document-types', [DocumentTypeController::class, 'index']);
        Route::post('document-types', [DocumentTypeController::class, 'store']);
        Route::post('document-types/suggested', [DocumentTypeController::class, 'suggested']);
        Route::patch('document-types/{documentType}', [DocumentTypeController::class, 'update']);
        Route::post('document-imports', [DocumentController::class, 'import']);
        Route::get('applications/{application}/documents', [DocumentController::class, 'index']);
        Route::post('applications/{application}/documents', [DocumentController::class, 'upload']);
        Route::post('document-versions/{version}/link', [DocumentController::class, 'link']);

        // Selection: stages, panels, slots, candidates
        Route::get('selection-stages', [StageController::class, 'index']);
        Route::post('selection-stages', [StageController::class, 'store']);
        Route::post('selection-stages/suggested', [StageController::class, 'suggest']);
        Route::post('selection-stages/copy', [StageController::class, 'copy']);
        Route::patch('selection-stages/{stage}', [StageController::class, 'update']);
        Route::get('panels', [PanelController::class, 'index']);
        Route::post('panels', [PanelController::class, 'store']);
        Route::get('panels/{panel}', [PanelController::class, 'show']);
        Route::patch('panels/{panel}', [PanelController::class, 'update']);
        Route::get('panels/{panel}/eligible-members', [PanelController::class, 'candidatesForMembership']);
        Route::post('panels/{panel}/members', [PanelController::class, 'addMember']);
        Route::delete('panel-members/{member}', [PanelController::class, 'removeMember'])->whereNumber('member');
        Route::post('panels/{panel}/slots', [PanelController::class, 'addSlot']);
        Route::post('panels/auto-allocate', [AutoAllocationController::class, 'previewCandidates']);
        Route::post('panels/auto-allocate/{token}/confirm', [AutoAllocationController::class, 'confirmCandidates']);
        Route::post('panels/auto-assign-faculty', [AutoAllocationController::class, 'previewFaculty']);
        Route::post('panels/auto-assign-faculty/{token}/confirm', [AutoAllocationController::class, 'confirmFaculty']);
        Route::post('panels/{panel}/bulk-schedule', [PanelController::class, 'bulkSchedule']);
        Route::get('panels/{panel}/summary', [PanelController::class, 'summary']);
        Route::post('interview-slots/{slot}/candidates', [PanelController::class, 'schedule']);
        Route::post('interview-candidates/{candidate}/reschedule', [PanelController::class, 'reschedule']);
        Route::post('interview-candidates/{candidate}/cancel', [PanelController::class, 'cancel']);
        Route::post('interview-candidates/{candidate}/attendance', [PanelController::class, 'attendance']);
        Route::post('interview-candidates/{candidate}/lock', [PanelController::class, 'lock']);
        Route::post('interview-candidates/{candidate}/conflict', [PanelController::class, 'conflict']);
        Route::post('interview-candidates/send-on', [PanelController::class, 'sendOn']);
        Route::post('interview-candidates/swap', [PanelController::class, 'swap']);

        // Selection: marking (panel members)
        Route::get('my-interviews', [EvaluationController::class, 'myDay']);
        Route::get('my-interviews/slots/{slotUid}', [EvaluationController::class, 'slot']);
        Route::put('interview-candidates/{candidate}/evaluation', [EvaluationController::class, 'save']);
        Route::post('interview-candidates/{candidate}/evaluation/submit', [EvaluationController::class, 'submit']);
        Route::post('evaluations/{evaluation}/reopen', [EvaluationController::class, 'reopen']);

        // Merit
        Route::get('merit-formulas', [MeritFormulaController::class, 'index']);
        Route::post('merit-formulas', [MeritFormulaController::class, 'store']);
        Route::put('merit-formulas/{formula}', [MeritFormulaController::class, 'update']);
        Route::post('merit-formulas/{formula}/approve', [MeritFormulaController::class, 'approve']);
        Route::post('merit-formulas/{formula}/preview', [MeritFormulaController::class, 'preview']);
        Route::get('merit-lists', [MeritListController::class, 'index']);
        Route::post('merit-lists', [MeritListController::class, 'run']);
        Route::get('merit-lists/{list}', [MeritListController::class, 'show']);
        Route::get('merit-lists/{list}/entries', [MeritListController::class, 'entries']);
        Route::get('merit-lists/{list}/entries/{application}', [MeritListController::class, 'breakdown']);
        Route::post('merit-lists/{list}/resolve-tie', [MeritListController::class, 'resolveTie']);
        Route::post('merit-lists/{list}/approve', [MeritListController::class, 'approve']);
        Route::post('merit-lists/{list}/publish', [MeritListController::class, 'publish']);
        Route::post('merit-lists/{list}/check', [MeritListController::class, 'check']);
        Route::get('merit-lists/{list}/compare', [MeritListController::class, 'compare']);
        Route::get('merit-lists/{list}/export', [MeritListController::class, 'export']);
        Route::get('applications/{application}/merit', [MeritListController::class, 'forApplication']);

        // System
        Route::get('system/health', [HealthController::class, 'show']);
        Route::get('system/maintenance', [MaintenanceController::class, 'show']);
        Route::post('system/maintenance/migrate', [MaintenanceController::class, 'migrate']);
        Route::get('system/settings', [SettingController::class, 'index']);
        Route::patch('system/settings/{setting}', [SettingController::class, 'update']);
    });
});
