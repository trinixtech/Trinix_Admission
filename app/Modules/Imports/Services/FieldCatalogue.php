<?php

namespace App\Modules\Imports\Services;

/**
 * The fixed list of system fields a portal file can be mapped to (decision D13: the mapping is configurable,
 * the fields are not). Keys are also the keys of the API push format.
 */
class FieldCatalogue
{
    public const ACADEMIC_LEVELS = [
        'class10' => 'Class 10',
        'class12' => 'Class 12',
        'graduation' => 'Graduation',
        'postgraduation' => 'Post-graduation',
    ];

    /** @return array<string, array{label: string, group: string, type: string, required?: bool, max?: int, min?: float, maxValue?: float}> */
    public static function fields(): array
    {
        $f = [
            'portal_application_no' => ['label' => 'Portal application number', 'group' => 'Identity', 'type' => 'code', 'required' => true, 'max' => 64],
            'course' => ['label' => 'Course', 'group' => 'Identity', 'type' => 'course', 'required' => true],
            'full_name' => ['label' => 'Full name', 'group' => 'Identity', 'type' => 'string', 'required' => true, 'max' => 255],

            'exam' => ['label' => 'Entrance exam', 'group' => 'Entrance', 'type' => 'exam', 'required' => true],
            'entrance_application_no' => ['label' => 'CAT/IPMAT application number', 'group' => 'Entrance', 'type' => 'code', 'required' => true, 'max' => 64],
            'entrance_score' => ['label' => 'Entrance score', 'group' => 'Entrance', 'type' => 'decimal', 'min' => -1000, 'maxValue' => 100000],
            'entrance_percentile' => ['label' => 'Overall percentile', 'group' => 'Entrance', 'type' => 'decimal', 'min' => 0, 'maxValue' => 100],

            'email' => ['label' => 'Email', 'group' => 'Contact', 'type' => 'email', 'required' => true],
            'mobile' => ['label' => 'Mobile', 'group' => 'Contact', 'type' => 'mobile', 'required' => true],
            'alternate_mobile' => ['label' => 'Alternate mobile', 'group' => 'Contact', 'type' => 'mobile'],
            'address' => ['label' => 'Address', 'group' => 'Contact', 'type' => 'string', 'max' => 1000],
            'city' => ['label' => 'City', 'group' => 'Contact', 'type' => 'string', 'max' => 100],
            'state' => ['label' => 'State', 'group' => 'Contact', 'type' => 'string', 'max' => 100],
            'pincode' => ['label' => 'PIN code', 'group' => 'Contact', 'type' => 'pincode'],

            'date_of_birth' => ['label' => 'Date of birth', 'group' => 'Personal', 'type' => 'date', 'required' => true],
            'gender' => ['label' => 'Gender', 'group' => 'Personal', 'type' => 'gender', 'required' => true],
            'nationality' => ['label' => 'Nationality', 'group' => 'Personal', 'type' => 'string', 'max' => 64],
            'domicile_state' => ['label' => 'State of domicile', 'group' => 'Personal', 'type' => 'string', 'max' => 64],
            'guardian_name' => ['label' => "Father's/guardian's name", 'group' => 'Personal', 'type' => 'string', 'max' => 255],

            'category' => ['label' => 'Category', 'group' => 'Category', 'type' => 'category', 'required' => true],
            'pwbd' => ['label' => 'PwBD (yes/no)', 'group' => 'Category', 'type' => 'bool'],
            'pwbd_type' => ['label' => 'PwBD type', 'group' => 'Category', 'type' => 'string', 'max' => 64],

            'work_experience_months' => ['label' => 'Work experience (months)', 'group' => 'Experience', 'type' => 'int', 'min' => 0, 'maxValue' => 600],
        ];

        foreach (self::ACADEMIC_LEVELS as $level => $name) {
            $f["{$level}_board"] = ['label' => "$name board/university", 'group' => 'Academics', 'type' => 'string', 'max' => 255];
            if (in_array($level, ['graduation', 'postgraduation'], true)) {
                $f["{$level}_qualification"] = ['label' => "$name degree", 'group' => 'Academics', 'type' => 'string', 'max' => 255];
            }
            $f["{$level}_year"] = ['label' => "$name year of passing", 'group' => 'Academics', 'type' => 'year'];
            $f["{$level}_marks_type"] = ['label' => "$name marks type (percentage/cgpa)", 'group' => 'Academics', 'type' => 'marks_type'];
            $f["{$level}_marks"] = ['label' => "$name marks", 'group' => 'Academics', 'type' => 'decimal', 'min' => 0, 'maxValue' => 100];
            $f["{$level}_cgpa_scale"] = ['label' => "$name CGPA scale", 'group' => 'Academics', 'type' => 'decimal', 'min' => 1, 'maxValue' => 100];
        }

        for ($i = 1; $i <= config('import.max_preferences'); $i++) {
            $f["preference_{$i}"] = ['label' => "Campus preference $i", 'group' => 'Preferences', 'type' => 'branch'];
        }

        // GD/PI centre preferences from the portal (Stage A, D26): cities, in order.
        for ($i = 1; $i <= 3; $i++) {
            $f["interview_centre_{$i}"] = ['label' => "GD/PI centre preference $i", 'group' => 'Preferences', 'type' => 'string', 'max' => 100];
        }

        return $f;
    }

    /** Sectional scores are mapped as "sectional.<Section name>", e.g. sectional.VARC. */
    public static function isSectional(string $key): bool
    {
        return (bool) preg_match('/^sectional\.[A-Za-z0-9 _&\-]{1,32}$/', $key);
    }

    public static function isKnown(string $key): bool
    {
        return array_key_exists($key, self::fields()) || self::isSectional($key);
    }

    /** Fields that must be present for a course of the given level (PG courses also need graduation marks). */
    public static function requiredFor(?string $courseLevel): array
    {
        $required = array_keys(array_filter(self::fields(), fn ($f) => $f['required'] ?? false));
        $required[] = 'class10_marks';
        $required[] = 'class12_marks';
        if ($courseLevel === 'PG') {
            $required[] = 'graduation_marks';
        }

        return $required;
    }
}
