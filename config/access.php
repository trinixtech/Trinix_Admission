<?php

/*
| Roles and permissions catalogue. `php artisan access:sync` creates anything missing.
| A permission's default roles are applied only when the permission or role is first created,
| so changes the Super Admin makes later are kept. super_admin always has every permission.
*/

return [
    'permissions' => [
        'organisation.view' => ['Organisation', 'View institution, locations, branches and courses'],
        'organisation.manage' => ['Organisation', 'Create and edit institution, locations, branches, courses and branch-course links'],
        'cycles.view' => ['Cycles', 'View academic years, cycles, offerings and rounds'],
        'cycles.manage' => ['Cycles', 'Create and edit academic years, cycles, offerings and rounds'],
        'rounds.close' => ['Cycles', 'Close an admission round'],
        'rounds.reopen' => ['Cycles', 'Reopen a closed admission round'],
        'users.view' => ['People', 'View users and their role assignments'],
        'users.manage' => ['People', 'Invite users, edit them, suspend them, assign and revoke roles'],
        'users.support' => ['People', 'Unlock accounts and resend invitations'],
        'roles.manage' => ['People', 'Change role permissions and role conflicts'],
        'teams.view' => ['Teams', 'View committees, admission cells and accounts teams'],
        'teams.manage' => ['Teams', 'Create and edit teams and their members'],
        'audit.view' => ['Audit', 'View the audit log'],
        'settings.manage' => ['System', 'Edit all system settings'],
        'settings.technical' => ['System', 'Edit technical system settings'],
        'system.health' => ['System', 'View system health'],
        'system.maintain' => ['System', 'See pending database updates and run them (Maintenance)'],
        'backups.manage' => ['System', 'Make, download and schedule backups (they hold all applicant data)'],
        'backups.restore' => ['System', 'Restore the database from a backup'],
        'imports.view' => ['Imports', 'View import batches, previews and error reports'],
        'imports.manage' => ['Imports', 'Upload, map, validate, exclude rows and cancel import batches'],
        'imports.approve' => ['Imports', 'Approve an import batch for writing (never one you uploaded)'],
        'applicants.view' => ['Applicants', 'View applicants and applications'],
        'applicants.edit' => ['Applicants', 'Correct application fields, with a reason'],
        'sources.manage' => ['Imports', 'Manage external sources and their API tokens'],
        'documents.view' => ['Documents', 'Open documents of applications in scope (every opening is logged)'],
        'documents.manage' => ['Documents', 'Import documents (ZIP, links) and upload one manually'],
        'stages.configure' => ['Selection', 'Set up selection stages and evaluation criteria'],
        'panels.manage' => ['Selection', 'Create panels and slots, assign members, schedule and reschedule candidates, mark attendance'],
        'evaluations.submit' => ['Selection', 'Enter and submit marks for candidates on your own panels'],
        'evaluations.reopen' => ['Selection', 'Reopen submitted marks with a reason and lock results'],
        'evaluations.lock' => ['Selection', 'Lock all marks of a stage, panel or day at once, and reopen locked results with a reason (admin)'],
        'evaluations.view' => ['Selection', 'See all marks and panel summaries in scope'],
        'merit.configure' => ['Merit', 'Draft merit formulas (components, weights, tie-breaks)'],
        'merit.manage' => ['Merit', 'Run merit lists, record committee decisions with a reason, export'],
        'merit.approve' => ['Merit', 'Approve merit formulas; approve and publish merit lists (never one you ran)'],
        'merit.view' => ['Merit', 'See merit lists and score breakdowns in scope'],
    ],

    'roles' => [
        'super_admin' => [
            'name' => 'Super Admin',
            'description' => 'System setup, users, roles, permissions, integrations and global audit.',
            'two_factor' => true,
            'permissions' => ['*'],
        ],
        'it_admin' => [
            'name' => 'IT Administrator',
            'description' => 'System health, technical settings and account support. No applicant data.',
            'two_factor' => true,
            'permissions' => ['system.health', 'system.maintain', 'settings.technical', 'users.view', 'users.support'],
        ],
        'central_admin' => [
            'name' => 'Central Admission Administrator',
            'description' => 'Cycles, offerings, import governance and central operations.',
            'two_factor' => true,
            'permissions' => [
                'organisation.view', 'organisation.manage', 'cycles.view', 'cycles.manage', 'rounds.close', 'rounds.reopen',
                'users.view', 'users.manage', 'users.support', 'teams.view', 'teams.manage', 'audit.view',
                'imports.view', 'imports.manage', 'imports.approve', 'applicants.view', 'applicants.edit',
                'documents.view', 'documents.manage', 'stages.configure', 'panels.manage', 'evaluations.submit', 'evaluations.reopen', 'evaluations.lock', 'evaluations.view',
                'merit.configure', 'merit.manage', 'merit.approve', 'merit.view',
            ],
        ],
        'branch_admin' => [
            'name' => 'Branch Administrator',
            'description' => 'Authorised branch-level operations.',
            'two_factor' => true,
            'permissions' => [
                'organisation.view', 'cycles.view', 'users.view', 'users.manage', 'users.support',
                'teams.view', 'teams.manage', 'audit.view', 'applicants.view',
                'documents.view', 'panels.manage', 'evaluations.submit', 'merit.view',
            ],
        ],
        'committee_chair' => [
            'name' => 'Committee Chairperson',
            'description' => 'Review and approve committee recommendations within delegated authority.',
            'two_factor' => false,
            'permissions' => ['organisation.view', 'cycles.view', 'teams.view', 'applicants.view', 'documents.view', 'evaluations.view', 'evaluations.reopen', 'evaluations.submit', 'merit.approve', 'merit.view'],
        ],
        'committee_member' => [
            'name' => 'Committee Member',
            'description' => 'Selection and committee decisions within assigned scope.',
            'two_factor' => false,
            'permissions' => ['organisation.view', 'cycles.view', 'teams.view', 'applicants.view', 'documents.view', 'evaluations.submit'],
        ],
        'admission_officer' => [
            'name' => 'Admission Cell Officer',
            'description' => 'Imports, applications, files, the interview schedule and merit lists (running, not approving).',
            'two_factor' => false,
            'permissions' => ['organisation.view', 'cycles.view', 'teams.view', 'imports.view', 'imports.manage', 'applicants.view', 'applicants.edit', 'documents.view', 'documents.manage', 'panels.manage', 'evaluations.view', 'merit.configure', 'merit.manage', 'merit.view'],
        ],
        'admission_staff' => [
            'name' => 'Admission Cell Staff',
            'description' => 'Document processing, applicant contact and assigned tasks.',
            'two_factor' => false,
            'permissions' => ['organisation.view', 'cycles.view', 'applicants.view', 'documents.view', 'panels.manage'],
        ],
        'faculty' => [
            'name' => 'Faculty / Panel Member',
            'description' => 'View assigned candidates and submit evaluations.',
            'two_factor' => false,
            'permissions' => ['evaluations.submit'],
        ],
        'reporting_officer' => [
            'name' => 'Reporting Officer',
            'description' => 'View approved reports within assigned scope.',
            'two_factor' => false,
            'permissions' => ['organisation.view', 'cycles.view', 'merit.view'],
        ],
    ],

    // Pairs of roles one person may not hold on overlapping scope.
    'conflicts' => [],

    // Default system settings: key => [value, description, technical]
    'settings' => [
        'session_idle_minutes' => [30, 'Minutes of inactivity before a user is signed out', true],
        'login_max_attempts' => [5, 'Failed sign-ins before an account is locked', true],
        'login_lock_minutes' => [15, 'Minutes an account stays locked', true],
        'invitation_expiry_hours' => [72, 'Hours an invitation link stays valid', true],
        'support_email' => [null, 'Email address shown to users who need help', false],
        'document_url_hosts' => [[], 'Websites documents may be downloaded from (host names, e.g. portal.example.org)', true],
        'import_categories' => [['GEN', 'SC', 'ST', 'OBC-NCL', 'EWS'], 'Category codes accepted in student imports, e.g. add KM for Kashmiri Migrant. In each import, map the portal\'s wording to one of these codes.', false],
    ],
];
