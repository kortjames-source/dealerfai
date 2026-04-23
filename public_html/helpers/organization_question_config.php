<?php
if (!function_exists('organization_column_exists')) {
    function organization_column_exists(PDO $db, string $column): bool
    {
        static $cache = [];
        if (array_key_exists($column, $cache)) {
            return $cache[$column];
        }
        try {
            $stmt = $db->prepare("
                SELECT COUNT(*)
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'organizations'
                  AND COLUMN_NAME = ?
            ");
            $stmt->execute([$column]);
            $cache[$column] = (int)$stmt->fetchColumn() > 0;
        } catch (PDOException $ex) {
            $cache[$column] = false;
        }
        return $cache[$column];
    }
}

function get_credit_app_question_catalog(): array
{
    return [
        'step1' => [
            ['id' => 'full_name', 'label' => 'Full Name'],
            ['id' => 'email', 'label' => 'Email'],
            ['id' => 'phone', 'label' => 'Phone'],
            ['id' => 'address', 'label' => 'Address'],
            ['id' => 'city', 'label' => 'City'],
            ['id' => 'province', 'label' => 'Province'],
            ['id' => 'postal_code', 'label' => 'Postal Code'],
            ['id' => 'housing', 'label' => 'Rent or Own'],
            ['id' => 'years_at_address', 'label' => 'Time at Address (years)'],
            ['id' => 'monthly_payment', 'label' => 'Mortgage/Rent Payment'],
            ['id' => 'has_cosigner', 'label' => 'Add a Co-Signer?'],
            ['id' => 'co_full_name', 'label' => 'Co-Applicant Full Name'],
            ['id' => 'co_email', 'label' => 'Co-Applicant Email'],
            ['id' => 'co_phone', 'label' => 'Co-Applicant Phone'],
            ['id' => 'co_address', 'label' => 'Co-Applicant Address'],
            ['id' => 'co_city', 'label' => 'Co-Applicant City'],
            ['id' => 'co_province', 'label' => 'Co-Applicant Province'],
            ['id' => 'co_postal_code', 'label' => 'Co-Applicant Postal Code'],
            ['id' => 'co_housing', 'label' => 'Co-Applicant Rent or Own'],
            ['id' => 'co_years_at_address', 'label' => 'Co-Applicant Time at Address'],
            ['id' => 'co_monthly_payment', 'label' => 'Co-Applicant Mortgage/Rent Payment'],
        ],
        'step2' => [
            ['id' => 'employer', 'label' => 'Employer'],
            ['id' => 'work_address', 'label' => 'Work Address'],
            ['id' => 'position', 'label' => 'Position/Title'],
            ['id' => 'employment_length', 'label' => 'Employment Length'],
            ['id' => 'income', 'label' => 'Annual Income'],
            ['id' => 'other_income', 'label' => 'Other Income'],
            ['id' => 'other_income_source', 'label' => 'Other Income Source'],
            ['id' => 'prev_employer', 'label' => 'Previous Employer'],
            ['id' => 'prev_phone', 'label' => 'Previous Employer Phone'],
            ['id' => 'prev_address', 'label' => 'Previous Employer Address'],
            ['id' => 'prev_length', 'label' => 'Previous Employment Length'],
            ['id' => 'co_employer', 'label' => 'Co-Applicant Employer'],
            ['id' => 'co_work_address', 'label' => 'Co-Applicant Work Address'],
            ['id' => 'co_position', 'label' => 'Co-Applicant Position/Title'],
            ['id' => 'co_employment_length', 'label' => 'Co-Applicant Employment Length'],
            ['id' => 'co_income', 'label' => 'Co-Applicant Annual Income'],
            ['id' => 'co_other_income', 'label' => 'Co-Applicant Other Income'],
            ['id' => 'co_other_income_source', 'label' => 'Co-Applicant Other Income Source'],
            ['id' => 'co_prev_employer', 'label' => 'Co-Applicant Previous Employer'],
            ['id' => 'co_prev_phone', 'label' => 'Co-Applicant Previous Employer Phone'],
            ['id' => 'co_prev_address', 'label' => 'Co-Applicant Previous Employer Address'],
            ['id' => 'co_prev_length', 'label' => 'Co-Applicant Previous Employment Length'],
        ],
        'step3' => [
            ['id' => 'annual_km', 'label' => 'Annual Kilometres'],
            ['id' => 'driving_type', 'label' => 'Driving Type'],
            ['id' => 'gravel_exposure', 'label' => 'Gravel Exposure'],
            ['id' => 'road_conditions', 'label' => 'Road Conditions'],
            ['id' => 'overnight_parking', 'label' => 'Overnight Parking'],
            ['id' => 'regular_users', 'label' => 'Regular Users'],
            ['id' => 'food_drink', 'label' => 'Food/Drink in Vehicle'],
            ['id' => 'appearance_priority', 'label' => 'Appearance Priority'],
            ['id' => 'ownership_length', 'label' => 'Ownership Length'],
            ['id' => 'life_protection', 'label' => 'Life Protection'],
            ['id' => 'disability_protection', 'label' => 'Disability Protection'],
            ['id' => 'critical_illness', 'label' => 'Critical Illness'],
            ['id' => 'job_loss', 'label' => 'Job Loss Protection'],
        ],
    ];
}

/**
 * Central option catalog for built-in (non-custom) credit-app answers.
 * This is used by:
 * - application_step3.php (rendering selects/checkboxes)
 * - admin_scoring_action_rules.php (showing presets for rule creation)
 *
 * Return format:
 *   [
 *     'field_key' => [
 *       'type' => 'select'|'multiselect',
 *       'options' => [
 *         ['value' => '...', 'label' => '...'],
 *         ...
 *       ],
 *     ],
 *   ]
 */
function get_credit_app_answer_option_catalog(): array
{
    return [
        // Step 3 (usage_data)
        'annual_km' => [
            'type' => 'select',
            'options' => [
                ['value' => 'under_15k', 'label' => 'Under 15,000 km'],
                ['value' => '15k_20k', 'label' => '15,000-20,000 km'],
                ['value' => '20k_25k', 'label' => '20,000-25,000 km'],
                ['value' => '25k_plus', 'label' => '25,000+ km'],
            ],
        ],
        'driving_type' => [
            'type' => 'select',
            'options' => [
                ['value' => 'city', 'label' => 'Mostly city'],
                ['value' => 'mix', 'label' => 'Mix of city & highway'],
                ['value' => 'highway', 'label' => 'Mostly highway'],
            ],
        ],
        'gravel_exposure' => [
            'type' => 'select',
            'options' => [
                ['value' => 'rarely', 'label' => 'Rarely / Never'],
                ['value' => 'sometimes', 'label' => 'Sometimes'],
                ['value' => 'frequently', 'label' => 'Frequently'],
            ],
        ],
        'road_conditions' => [
            'type' => 'select',
            'options' => [
                ['value' => 'smooth', 'label' => 'Mostly smooth, well-maintained roads'],
                ['value' => 'some', 'label' => 'Some potholes, construction zones, or road debris'],
                ['value' => 'frequent', 'label' => 'Frequent potholes, rough pavement, or construction-related debris'],
            ],
        ],
        'overnight_parking' => [
            'type' => 'select',
            'options' => [
                ['value' => 'garage', 'label' => 'Garage'],
                ['value' => 'driveway', 'label' => 'Driveway'],
                ['value' => 'street', 'label' => 'Street'],
                ['value' => 'condo', 'label' => 'Apartment / condo lot'],
            ],
        ],
        'regular_users' => [
            'type' => 'multiselect',
            'options' => [
                ['value' => 'just_me', 'label' => 'Just me'],
                ['value' => 'multiple_drivers', 'label' => 'Multiple drivers'],
                ['value' => 'kids', 'label' => 'Kids'],
                ['value' => 'pets', 'label' => 'Pets'],
            ],
        ],
        'food_drink' => [
            'type' => 'select',
            'options' => [
                ['value' => 'rarely', 'label' => 'Rarely'],
                ['value' => 'sometimes', 'label' => 'Sometimes'],
                ['value' => 'often', 'label' => 'Often'],
            ],
        ],
        'appearance_priority' => [
            'type' => 'select',
            'options' => [
                ['value' => 'not_important', 'label' => 'Not important'],
                ['value' => 'somewhat_important', 'label' => 'Somewhat important'],
                ['value' => 'very_important', 'label' => 'Very important'],
            ],
        ],
        'ownership_length' => [
            'type' => 'select',
            'options' => [
                ['value' => 'less_3', 'label' => 'Less than 3 years'],
                ['value' => '3_4', 'label' => '3-4 years'],
                ['value' => '5_6', 'label' => '5-6 years'],
                ['value' => '7_plus', 'label' => '7+ years'],
            ],
        ],
        'life_protection' => [
            'type' => 'select',
            'options' => [
                ['value' => 'yes', 'label' => 'Yes'],
                ['value' => 'no', 'label' => 'No'],
            ],
        ],
        'disability_protection' => [
            'type' => 'select',
            'options' => [
                ['value' => 'yes', 'label' => 'Yes'],
                ['value' => 'no', 'label' => 'No'],
            ],
        ],
        'critical_illness' => [
            'type' => 'select',
            'options' => [
                ['value' => 'yes', 'label' => 'Yes'],
                ['value' => 'no', 'label' => 'No'],
            ],
        ],
        'job_loss' => [
            'type' => 'select',
            'options' => [
                ['value' => 'yes', 'label' => 'Yes'],
                ['value' => 'no', 'label' => 'No'],
            ],
        ],
    ];
}

function normalize_credit_app_question_config($config): array
{
    $normalized = [
        'step1' => ['hidden' => []],
        'step2' => ['hidden' => []],
        'step3' => ['hidden' => []],
    ];
    if (!is_array($config)) {
        return $normalized;
    }
    foreach (array_keys($normalized) as $step) {
        $hidden = [];
        if (isset($config[$step]['hidden']) && is_array($config[$step]['hidden'])) {
            $hidden = $config[$step]['hidden'];
        } elseif (isset($config[$step]) && is_array($config[$step])) {
            $hidden = $config[$step];
        }
        $hidden = array_values(array_unique(array_filter($hidden, 'is_string')));
        $normalized[$step]['hidden'] = $hidden;
    }
    return $normalized;
}

function credit_app_question_is_visible(array $config, string $step, string $id): bool
{
    $hidden = $config[$step]['hidden'] ?? [];
    return !in_array($id, $hidden, true);
}

function credit_app_question_config_is_default(array $config): bool
{
    foreach (['step1', 'step2', 'step3'] as $step) {
        if (!empty($config[$step]['hidden'])) {
            return false;
        }
    }
    return true;
}

function build_credit_app_question_config_from_post(array $catalog, array $post): array
{
    $hiddenByStep = [
        'step1' => [],
        'step2' => [],
        'step3' => [],
    ];
    foreach ($hiddenByStep as $step => $_) {
        $shown = $post[$step] ?? [];
        foreach ($catalog[$step] ?? [] as $question) {
            $id = $question['id'];
            if (!isset($shown[$id])) {
                $hiddenByStep[$step][] = $id;
            }
        }
    }
    return normalize_credit_app_question_config($hiddenByStep);
}

function get_org_credit_app_question_config(PDO $db, int $orgId): array
{
    $default = normalize_credit_app_question_config(null);
    if ($orgId <= 0) {
        return $default;
    }
    if (!organization_column_exists($db, 'credit_app_question_config')) {
        return $default;
    }
    $stmt = $db->prepare("SELECT credit_app_question_config FROM organizations WHERE id = ?");
    $stmt->execute([$orgId]);
    $raw = $stmt->fetchColumn();
    if (!$raw) {
        return $default;
    }
    $decoded = json_decode($raw, true);
    return normalize_credit_app_question_config($decoded);
}
