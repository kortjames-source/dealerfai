<?php
declare(strict_types=1);

function get_scoring_tag_trigger_reference(): array
{
    return [
        'long_term_ownership' => [
            'Ownership length is 5+ years (numeric)',
            'Ownership length set to 5_6 / 7_plus',
        ],
        'extended_ownership' => [
            'Ownership length is 7+ years (numeric)',
            'Ownership length set to 7_plus',
        ],
        'medium_term_ownership' => [
            'Ownership length is 3-4 years (numeric)',
            'Ownership length set to 3_4',
        ],
        'short_term_ownership' => [
            'Ownership length is 1-2 years (numeric)',
            'Ownership length set to less_3',
        ],
        'low_annual_km' => [
            'Annual km under 15,000 (numeric)',
            'Annual km set to under_15k',
        ],
        'low_mileage' => [
            'Annual km under 15,000 (numeric)',
            'Annual km set to under_15k',
        ],
        'high_mileage' => [
            'Annual km 20,000+ (numeric)',
            'Annual km set to 20k_25k or 25k_plus',
        ],
        'high_annual_km' => [
            'Annual km 25,000+ (numeric)',
            'Annual km set to 25k_plus',
        ],
        'accelerated_depreciation' => [
            'Annual km 25,000+ (numeric)',
            'Annual km set to 25k_plus',
        ],
        'urban_driving' => [
            'Driving type is city or mix',
            'Overnight parking is street',
        ],
        'highway_driving' => [
            'Driving type is highway or mix',
        ],
        'gravel_road_driving' => [
            'Gravel exposure is sometimes or frequently',
        ],
        'rural_driving' => [
            'Gravel exposure is frequently',
        ],
        'poor_roads' => [
            'Road conditions are some or frequent',
        ],
        'pothole_risk' => [
            'Road conditions are frequent',
        ],
        'road_hazard_region' => [
            'Road conditions are frequent',
            'Province is MB or SK',
            'Postal code is Northern Ontario (P0L/P0M)',
        ],
        'winter_region' => [
            'Province is MB or SK',
            'Postal code is Northern Ontario (P0L/P0M)',
        ],
        'garage_parked' => [
            'Overnight parking is garage',
        ],
        'outdoor_parking' => [
            'Overnight parking is driveway',
        ],
        'street_parking' => [
            'Overnight parking is street',
            'Overnight parking is condo',
        ],
        'condo_or_apartment' => [
            'Housing is rent / renting / rent (with roommates)',
            'Overnight parking is condo',
        ],
        'multiple_drivers' => [
            'Regular users includes multiple_drivers',
        ],
        'pets_or_kids' => [
            'Regular users includes kids or pets',
        ],
        'food_or_drink_in_vehicle' => [
            'Food/drink usage is sometimes or often',
        ],
        'price_sensitive' => [
            'Appearance priority is not_important',
        ],
        'customer_appearance_focus' => [
            'Appearance priority is very_important',
        ],
        'no_life_insurance' => [
            'Life protection = no',
        ],
        'has_dependents' => [
            'Life protection = yes',
        ],
        'no_disability_insurance' => [
            'Disability protection = yes',
        ],
        'physically_demanding_job' => [
            'Disability protection = yes',
        ],
        'no_disability' => [
            'Disability protection = no',
        ],
        'no_critical_illness_coverage' => [
            'Critical illness = yes',
        ],
        'family_health_history' => [
            'Critical illness = yes',
        ],
        'no_critical_illness' => [
            'Critical illness = no',
        ],
        'job_insecurity' => [
            'Job loss = yes',
        ],
        'loan_term_long' => [
            'Deal term is 72 months or longer',
        ],
        'no_job_loss' => [
            'Job loss = no',
        ],
        'no_highway_or_gravel' => [
            'Default when no highway or gravel driving was flagged',
        ],
        'luxury_or_premium_make' => [
            'Vehicle make is Land Rover, Range Rover, Mercedes, BMW, Lexus, Audi, Porsche, Cadillac, Infiniti',
        ],
        'new_vehicle' => [
            'Deal vehicle condition is new',
        ],
        'used_vehicle' => [
            'Deal vehicle condition is used',
        ],
    ];
}
