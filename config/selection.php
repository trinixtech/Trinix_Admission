<?php

return [
    // A starting set of stage names per course level (decision D24): loaded on request, then edited.
    // Criteria and marks are always entered by the admin.
    'suggested_stages' => [
        'PG' => [['WAT', 'Written Ability Test'], ['GD', 'Group Discussion'], ['PI', 'Personal Interview']],
        'Integrated' => [['WAT', 'Written Ability Test'], ['PI', 'Personal Interview']],
    ],

    'categories' => ['GEN', 'EWS', 'OBC-NCL', 'SC', 'ST', 'PwBD'],

    'contact_channels' => ['call_made', 'call_received', 'email_sent', 'email_received'],
    'contact_outcomes' => ['reached', 'no_answer', 'wrong_number', 'asked_to_reschedule', 'confirmed_attendance', 'declined', 'other'],

    'templates' => [
        'interview_scheduled' => [
            'subject' => 'Your {stage} schedule: {course} ({portal_no})',
            'body' => "Dear {name},\n\nYour {stage} for {course} is scheduled:\n\nDate and time: {when}\n{where}\n\nPlease reply to this email or call us if you cannot attend.\n\nAdmissions Office\n{institution}",
        ],
        'interview_rescheduled' => [
            'subject' => 'Changed {stage} schedule: {course} ({portal_no})',
            'body' => "Dear {name},\n\nYour {stage} for {course} has been moved to:\n\nDate and time: {when}\n{where}\n\nThe earlier time no longer applies.\n\nAdmissions Office\n{institution}",
        ],
        'interview_reminder' => [
            'subject' => 'Reminder: your {stage} tomorrow ({portal_no})',
            'body' => "Dear {name},\n\nThis is a reminder of your {stage} for {course}:\n\nDate and time: {when}\n{where}\n\nAdmissions Office\n{institution}",
        ],
    ],
];
