<?php

namespace App\Modules\Imports\Console;

use Illuminate\Console\Command;

/**
 * Writes a CSV shaped like a portal export, with made-up applicants, until the institute supplies a
 * real one. --vary changes some values (as a later round's export would) using the same numbers.
 */
class MakeSampleFile extends Command
{
    protected $signature = 'imports:make-sample {path} {--rows=100} {--start=1} {--course=MBA-IB} {--prefix=PRT} {--vary=0 : percent of rows whose percentile changes}
        {--seed=42} {--branches=BR1,BR2 : campus codes for preferences}';

    protected $description = 'Write a sample portal-style CSV with dummy applicants';

    public const HEADERS = [
        'Application No', 'Programme', 'Candidate Name', 'Entrance Reg No', 'Percentile', 'Email ID', 'Mobile No',
        'DOB', 'Gender', 'Category', 'PwD', '10th %', '12th %', 'Graduation %', 'Degree', 'Work Ex (Months)',
        'Campus Pref 1', 'Campus Pref 2', 'GDPI Centre 1', 'Remarks',
    ];

    public function handle(): int
    {
        mt_srand((int) $this->option('seed'));
        $first = ['Aarav', 'Diya', 'Ishaan', 'Ananya', 'Kabir', 'Meera', 'Rohan', 'Saanvi', 'Vivaan', 'Priya', 'Arjun', 'Kavya'];
        $last = ['Sharma', 'Reddy', 'Iyer', 'Gupta', 'Nair', 'Das', 'Khan', 'Patel', 'Singh', 'Rao', 'Mehta', 'Bose'];
        $cats = ['General', 'OBC', 'SC', 'ST', 'EWS'];
        $branches = array_filter(explode(',', (string) $this->option('branches')));
        $course = strtoupper((string) $this->option('course'));
        $isPg = $course !== 'IPM';
        $vary = (int) $this->option('vary');

        $out = fopen($this->argument('path'), 'w');
        fputcsv($out, self::HEADERS);
        $start = (int) $this->option('start');
        for ($i = $start; $i < $start + (int) $this->option('rows'); $i++) {
            $name = $first[$i % count($first)].' '.$last[intdiv($i, count($first)) % count($last)];
            $percentile = 70 + (($i * 37) % 2999) / 100;
            if ($vary && ($i % 100) < $vary) {
                $percentile = min(99.99, $percentile + 0.5);
            }
            $prefs = $branches;
            if ($i % 2 && count($prefs) > 1) {
                $prefs = array_reverse($prefs);
            }
            fputcsv($out, [
                sprintf('%s%s%07d', $this->option('prefix'), $isPg ? 'M' : 'I', $i),
                $course === 'MBA-IB' ? 'MBA(IB)' : ($course === 'MBA-BA' ? 'MBA(BA)' : 'IPM'),
                $name,
                sprintf('%s%08d', $isPg ? 'CAT' : 'IPM', 10000000 + $i),
                number_format($percentile, 2, '.', ''),
                strtolower(str_replace(' ', '.', $name)).$i.'@example.test',
                (string) (9000000000 + $i),
                sprintf('%02d-%02d-%d', 1 + $i % 28, 1 + $i % 12, $isPg ? 1998 + $i % 6 : 2006 + $i % 2),
                $i % 2 ? 'F' : 'M',
                $cats[$i % count($cats)],
                $i % 50 === 0 ? 'Yes' : 'No',
                number_format(60 + ($i * 7) % 40, 1, '.', ''),
                number_format(60 + ($i * 11) % 40, 1, '.', ''),
                $isPg ? number_format(55 + ($i * 13) % 45, 1, '.', '') : '',
                $isPg ? 'B.Com' : '',
                $isPg ? (string) (($i * 5) % 48) : '',
                $prefs[0] ?? '',
                $prefs[1] ?? '',
                ['New Delhi', 'Kolkata', 'Mumbai', 'Bangalore', 'Chennai'][$i % 5],
                $i % 25 === 0 ? 'Sports quota claimed' : '',
            ]);
        }
        fclose($out);
        $this->info("Wrote {$this->option('rows')} rows to {$this->argument('path')}.");

        return self::SUCCESS;
    }

    /** The column and value mapping that fits this sample (used as the demo template). */
    public static function mapping(): array
    {
        return [
            'column_map' => [
                'portal_application_no' => 'Application No', 'course' => 'Programme', 'full_name' => 'Candidate Name',
                'entrance_application_no' => 'Entrance Reg No', 'entrance_percentile' => 'Percentile', 'email' => 'Email ID',
                'mobile' => 'Mobile No', 'date_of_birth' => 'DOB', 'gender' => 'Gender', 'category' => 'Category', 'pwbd' => 'PwD',
                'class10_marks' => '10th %', 'class12_marks' => '12th %', 'graduation_marks' => 'Graduation %',
                'graduation_qualification' => 'Degree', 'work_experience_months' => 'Work Ex (Months)',
                'preference_1' => 'Campus Pref 1', 'preference_2' => 'Campus Pref 2', 'interview_centre_1' => 'GDPI Centre 1',
            ],
            'value_map' => [
                'course' => ['MBA(IB)' => 'MBA-IB', 'MBA(BA)' => 'MBA-BA'],
                'gender' => ['M' => 'Male', 'F' => 'Female', 'T' => 'Transgender'],
                'category' => ['General' => 'GEN', 'OBC' => 'OBC-NCL'],
            ],
        ];
    }
}
