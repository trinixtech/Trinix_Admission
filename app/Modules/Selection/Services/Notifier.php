<?php

namespace App\Modules\Selection\Services;

use App\Modules\Applicants\Models\Application;
use App\Modules\Notifications\Mail\PlainMail;
use App\Modules\Notifications\Models\NotificationLog;
use App\Modules\Notifications\Models\NotificationTemplate;
use App\Modules\Organisation\Models\Institution;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Email to applicants from editable templates (D8: email only). Logs keep a hash of the address, never the address. */
class Notifier
{
    public static function template(string $key): NotificationTemplate
    {
        return NotificationTemplate::firstOrCreate(['key' => $key], config("selection.templates.$key"));
    }

    /** @return bool queued */
    public function send(string $key, Application $app, array $vars = []): bool
    {
        $app->loadMissing('applicant', 'course');
        $email = $app->applicant->email;
        $t = self::template($key);
        $vars = array_merge([
            '{name}' => $app->applicant->full_name, '{portal_no}' => $app->portal_application_no,
            '{course}' => $app->course->name, '{institution}' => Institution::value('name') ?? '',
        ], $vars);
        $status = 'queued';
        $error = null;
        if (! $email) {
            [$status, $error] = ['failed', 'No email address'];
        } else {
            try {
                Mail::to($email)->queue(new PlainMail(strtr($t->subject, $vars), strtr($t->body, $vars)));
            } catch (Throwable $e) {
                [$status, $error] = ['failed', class_basename($e)];
            }
        }
        NotificationLog::create([
            'template_key' => $key, 'application_id' => $app->id, 'to_hash' => hash('sha256', mb_strtolower((string) $email)),
            'status' => $status, 'error' => $error, 'sent_at' => $status === 'queued' ? now() : null,
        ]);

        return $status === 'queued';
    }
}
