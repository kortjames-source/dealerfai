<?php
declare(strict_types=1);

function get_credit_question_tag_map(): array
{
    return [
        'annual_km' => ['low_annual_km', 'low_mileage', 'high_mileage', 'high_annual_km', 'accelerated_depreciation'],
        'driving_type' => ['urban_driving', 'highway_driving'],
        'gravel_exposure' => ['gravel_road_driving', 'rural_driving'],
        'road_conditions' => ['poor_roads', 'pothole_risk', 'road_hazard_region'],
        'overnight_parking' => ['garage_parked', 'outdoor_parking', 'street_parking', 'condo_or_apartment', 'urban_driving'],
        'regular_users' => ['multiple_drivers', 'pets_or_kids'],
        'food_drink' => ['food_or_drink_in_vehicle'],
        'appearance_priority' => ['price_sensitive', 'customer_appearance_focus'],
        'ownership_length' => ['short_term_ownership', 'medium_term_ownership', 'long_term_ownership', 'extended_ownership'],
        'life_protection' => ['no_life_insurance', 'has_dependents'],
        'disability_protection' => ['no_disability_insurance', 'physically_demanding_job', 'no_disability'],
        'critical_illness' => ['no_critical_illness_coverage', 'family_health_history', 'no_critical_illness'],
        'job_loss' => ['job_insecurity', 'no_job_loss'],
        'housing' => ['condo_or_apartment'],
        'province' => ['road_hazard_region', 'winter_region'],
        'postal_code' => ['road_hazard_region', 'winter_region'],
    ];
}
