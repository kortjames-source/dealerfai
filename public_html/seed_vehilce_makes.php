<?php
include 'db.php';

// Drop and recreate the vehicle_makes table
$db->exec("DROP TABLE IF EXISTS vehicle_makes");

$db->exec("
    CREATE TABLE vehicle_makes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        make_name VARCHAR(50) NOT NULL UNIQUE
    )
");

// Seed makes
$makes = [
    'Mercedes',
    'Smart',
    'Tesla',
    'Alfa Romeo',
    'Jaguar',
    'Land Rover',
    'Porsche',
    'VW',
    'Audi',
    'Volvo',
    'Lexus',
    'Infiniti',
    'Chevrolet',
    'Ford',
    'GMC',
    'Chrysler',
    'Dodge',
    'Jeep',
    'Ram',
    'Buick',
    'Cadillac',
    'Honda',
    'Toyota',
    'Nissan',
    'Mazda',
    'Hyundai',
    'Kia',
    'Subaru',
    'Mitsubishi'
];

$stmt = $db->prepare("INSERT INTO vehicle_makes (make_name) VALUES (?)");

foreach ($makes as $make) {
    $stmt->execute([$make]);
}

echo "✅ vehicle_makes table rebuilt and seeded successfully.\n";
?>
