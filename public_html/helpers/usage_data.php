<?php

declare(strict_types=1);

if (!function_exists('dealerfai_normalize_usage_data')) {
    function dealerfai_normalize_usage_data(array $usage): array
    {
        $normalized = $usage;

        $ownershipLength = strtolower(trim((string)($normalized['ownership_length'] ?? '')));
        if ($ownershipLength === '') {
            $legacyOwnership = strtolower(trim((string)($normalized['ownership_period'] ?? '')));
            $ownershipMap = [
                'less_3' => 'less_3',
                '1_2' => 'less_3',
                'short_term' => 'less_3',
                '3_4' => '3_4',
                'medium_term' => '3_4',
                '4_plus' => '5_6',
                '5_6' => '5_6',
                'long_term' => '7_plus',
                '7_plus' => '7_plus',
            ];
            if (isset($ownershipMap[$legacyOwnership])) {
                $normalized['ownership_length'] = $ownershipMap[$legacyOwnership];
            }
        }

        $drivingType = strtolower(trim((string)($normalized['driving_type'] ?? '')));
        if ($drivingType === '') {
            $highwayDriving = strtolower(trim((string)($normalized['highway_driving'] ?? '')));
            if ($highwayDriving === 'yes') {
                $normalized['driving_type'] = 'highway';
            } elseif ($highwayDriving === 'no') {
                $normalized['driving_type'] = 'city';
            }
        }

        $gravelExposure = strtolower(trim((string)($normalized['gravel_exposure'] ?? '')));
        if ($gravelExposure === '') {
            $gravelDriving = strtolower(trim((string)($normalized['gravel_driving'] ?? '')));
            if ($gravelDriving === 'yes') {
                $normalized['gravel_exposure'] = 'sometimes';
            } elseif ($gravelDriving === 'no') {
                $normalized['gravel_exposure'] = 'rarely';
            }
        }

        $roadConditions = strtolower(trim((string)($normalized['road_conditions'] ?? '')));
        if ($roadConditions === '') {
            $poorRoads = strtolower(trim((string)($normalized['poor_road_conditions'] ?? '')));
            if ($poorRoads === 'yes') {
                $normalized['road_conditions'] = 'frequent';
            } elseif ($poorRoads === 'no') {
                $normalized['road_conditions'] = 'smooth';
            }
        }

        $overnightParking = strtolower(trim((string)($normalized['overnight_parking'] ?? '')));
        if ($overnightParking === '') {
            $outdoorParking = strtolower(trim((string)($normalized['outdoor_parking'] ?? '')));
            if ($outdoorParking === 'yes') {
                $normalized['overnight_parking'] = 'driveway';
            } elseif ($outdoorParking === 'no') {
                $normalized['overnight_parking'] = 'garage';
            }
        }

        $regularUsers = $normalized['regular_users'] ?? [];
        if (!is_array($regularUsers)) {
            $regularUsers = array_filter(array_map('trim', explode(',', (string)$regularUsers)));
        }
        $regularUsers = array_values(array_unique(array_filter(array_map(
            static fn($v): string => strtolower(trim((string)$v)),
            $regularUsers
        ))));
        if (empty($regularUsers)) {
            $petsOrKids = strtolower(trim((string)($normalized['pets_or_kids'] ?? '')));
            if ($petsOrKids === 'yes') {
                $regularUsers = ['kids'];
            } elseif ($petsOrKids === 'no') {
                $regularUsers = ['just_me'];
            }
        }
        $normalized['regular_users'] = $regularUsers;

        $foodDrink = strtolower(trim((string)($normalized['food_drink'] ?? '')));
        if ($foodDrink === '') {
            $legacyFoodDrink = strtolower(trim((string)($normalized['food_or_drink_in_vehicle'] ?? '')));
            if ($legacyFoodDrink === 'yes') {
                $normalized['food_drink'] = 'sometimes';
            } elseif ($legacyFoodDrink === 'no') {
                $normalized['food_drink'] = 'rarely';
            }
        }

        unset(
            $normalized['ownership_period'],
            $normalized['highway_driving'],
            $normalized['gravel_driving'],
            $normalized['poor_road_conditions'],
            $normalized['pets_or_kids'],
            $normalized['outdoor_parking'],
            $normalized['food_or_drink_in_vehicle']
        );

        return $normalized;
    }
}

if (!function_exists('dealerfai_decode_usage_data')) {
    function dealerfai_decode_usage_data(?string $usageJson): array
    {
        if ($usageJson === null || trim($usageJson) === '') {
            return [];
        }
        $decoded = json_decode($usageJson, true);
        if (!is_array($decoded)) {
            return [];
        }
        return dealerfai_normalize_usage_data($decoded);
    }
}

if (!function_exists('dealerfai_build_customer_profile_summary')) {
    function dealerfai_build_customer_profile_summary(array $app): string
    {
        $usage = dealerfai_decode_usage_data($app['usage_data'] ?? null);
        $hasUsageData = !empty($usage);
        $details = [];

        $ownership = strtolower(trim((string)($usage['ownership_length'] ?? (!$hasUsageData ? ($app['ownership_length'] ?? '') : ''))));
        $ownershipMap = [
            'less_3' => 'Plans to keep the vehicle under 3 years',
            '3_4' => 'Plans to keep the vehicle 3-4 years',
            '5_6' => 'Plans to keep the vehicle 5-6 years',
            '7_plus' => 'Plans to keep the vehicle 7+ years',
        ];
        if ($ownership !== '' && isset($ownershipMap[$ownership])) {
            $details[] = $ownershipMap[$ownership];
        }

        $annualKm = strtolower(trim((string)($usage['annual_km'] ?? (!$hasUsageData ? ($app['annual_km'] ?? '') : ''))));
        $annualKmMap = [
            'under_15k' => 'Drives under 15,000 km/year',
            '15k_20k' => 'Drives around 15,000-20,000 km/year',
            '20k_25k' => 'Drives around 20,000-25,000 km/year',
            '25k_plus' => 'Drives 25,000+ km/year',
        ];
        if ($annualKm !== '' && isset($annualKmMap[$annualKm])) {
            $details[] = $annualKmMap[$annualKm];
        }

        $drivingType = strtolower(trim((string)($usage['driving_type'] ?? '')));
        $drivingMap = [
            'city' => 'Drives mostly in the city',
            'mix' => 'Drives a mix of city and highway',
            'highway' => 'Drives mostly on highways',
        ];
        if ($drivingType !== '' && isset($drivingMap[$drivingType])) {
            $details[] = $drivingMap[$drivingType];
        }

        $gravelExposure = strtolower(trim((string)($usage['gravel_exposure'] ?? '')));
        $gravelMap = [
            'rarely' => 'Rarely drives on gravel roads',
            'sometimes' => 'Drives on gravel roads sometimes',
            'frequently' => 'Drives on gravel roads frequently',
        ];
        if ($gravelExposure !== '' && isset($gravelMap[$gravelExposure])) {
            $details[] = $gravelMap[$gravelExposure];
        }

        $roadConditions = strtolower(trim((string)($usage['road_conditions'] ?? '')));
        $roadMap = [
            'smooth' => 'Usually drives on smooth roads',
            'some' => 'Encounters rough roads sometimes',
            'frequent' => 'Encounters rough roads frequently',
        ];
        if ($roadConditions !== '' && isset($roadMap[$roadConditions])) {
            $details[] = $roadMap[$roadConditions];
        }

        $parking = strtolower(trim((string)($usage['overnight_parking'] ?? '')));
        $parkingMap = [
            'garage' => 'Parks overnight in a garage',
            'driveway' => 'Parks overnight in a driveway',
            'street' => 'Parks overnight on the street',
            'condo' => 'Parks overnight at an apartment or condo lot',
        ];
        if ($parking !== '' && isset($parkingMap[$parking])) {
            $details[] = $parkingMap[$parking];
        }

        $regularUsers = $usage['regular_users'] ?? [];
        if (!is_array($regularUsers)) {
            $regularUsers = array_filter(array_map('trim', explode(',', (string)$regularUsers)));
        }
        $regularUsers = array_values(array_unique(array_map(
            static fn($v): string => strtolower(trim((string)$v)),
            array_filter($regularUsers, static fn($v): bool => trim((string)$v) !== '')
        )));
        if (in_array('multiple_drivers', $regularUsers, true)) {
            $details[] = 'Vehicle is shared by multiple drivers';
        }
        if (in_array('kids', $regularUsers, true) || in_array('pets', $regularUsers, true)) {
            $details[] = 'Kids or pets ride in the vehicle';
        }
        if (in_array('just_me', $regularUsers, true)) {
            $details[] = 'Primary driver is just me';
        }

        $foodDrink = strtolower(trim((string)($usage['food_drink'] ?? '')));
        $foodMap = [
            'rarely' => 'Rarely eats or drinks in the vehicle',
            'sometimes' => 'Sometimes eats or drinks in the vehicle',
            'often' => 'Often eats or drinks in the vehicle',
        ];
        if ($foodDrink !== '' && isset($foodMap[$foodDrink])) {
            $details[] = $foodMap[$foodDrink];
        }

        $appearance = strtolower(trim((string)($usage['appearance_priority'] ?? '')));
        $appearanceMap = [
            'not_important' => 'Appearance is not a priority',
            'somewhat_important' => 'Appearance is somewhat important',
            'very_important' => 'Appearance is a high priority',
        ];
        if ($appearance !== '' && isset($appearanceMap[$appearance])) {
            $details[] = $appearanceMap[$appearance];
        }

        $details = array_values(array_unique(array_filter($details, static fn($x): bool => $x !== '')));
        if (count($details) > 6) {
            $details = array_slice($details, 0, 6);
        }

        return implode('; ', $details);
    }
}
