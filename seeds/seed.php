<?php
/**
 * KIMTECH Admission Portal — Database Seeder
 * Run: php seeds/seed.php
 */
require_once __DIR__ . '/../includes/db.php';

echo "=== KIMTECH Admission Portal Seeder ===\n\n";

try {
    $pdo = getDB();

    // -----------------------------------------------------------
    // 1. Default Admin
    // -----------------------------------------------------------
    $adminHash = password_hash('admin123', PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("INSERT IGNORE INTO admins (username, password_hash, full_name, role) VALUES (?, ?, ?, ?)");
    $stmt->execute(['admin', $adminHash, 'System Administrator', 'superadmin']);
    echo "✓ Admin account created (admin / admin123)\n";

    // -----------------------------------------------------------
    // 2. System Settings
    // -----------------------------------------------------------
    $settings = [
        ['school_name',           'KIKAM TECHNICAL INSTITUTE (KIMTECH)'],
        ['school_address',        'Kikam, Western Region, Ghana'],
        ['school_phone',          '+233 0552290973'],
        ['school_email',          'info@kimtech.edu.gh'],
        ['academic_year',         '2026/2027'],
        ['principal_name',        'The Principal'],
        ['registration_deadline', '2026-09-30 23:59:59'],
        ['helpline_number',       '+233 0552290973'],
        ['cssps_year',            '2026'],
        ['reporting_date',        '15th September, 2026'],
        ['school_logo_path',      'assets/img/logo.png'],
        ['principal_signature_path', ''],
        ['prospectus_boarder_path',  ''],
        ['prospectus_day_path',      ''],
    ];
    $stmtS = $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_val) VALUES (?, ?)");
    foreach ($settings as $s) {
        $stmtS->execute($s);
    }
    echo "✓ System settings seeded\n";

    // -----------------------------------------------------------
    // 3. Sample Students (20 placement records)
    // -----------------------------------------------------------
    $programs   = ['General Science', 'General Arts', 'Business', 'Technical', 'Visual Arts', 'Home Economics'];
    $residency  = ['Boarder', 'Day'];
    $genders    = ['Male', 'Female'];
    $aggregates = ['6', '8', '10', '12', '14', '16', '18', '20', '24'];
    $regions    = ['Western', 'Ashanti', 'Greater Accra', 'Eastern', 'Central', 'Volta', 'Northern'];

    $names = [
        ['Kwame Asante Boateng', 'Male'],
        ['Akosua Mensah Darko', 'Female'],
        ['Kofi Agyemang Prempeh', 'Male'],
        ['Abena Owusu Sarpong', 'Female'],
        ['Yaw Kusi Appiah', 'Male'],
        ['Ama Adutwum Frimpong', 'Female'],
        ['Kwesi Antwi Bonsu', 'Male'],
        ['Adwoa Boateng Nyarko', 'Female'],
        ['Fiifi Dadzie Quaye', 'Male'],
        ['Efua Asante Wiredu', 'Female'],
        ['Kobby Yeboah Asare', 'Male'],
        ['Akua Forson Kumah', 'Female'],
        ['Nana Ofori Atta', 'Male'],
        ['Afua Acheampong Sefa', 'Female'],
        ['Ato Mensah Larbi', 'Male'],
        ['Maame Serwah Boadi', 'Female'],
        ['Kwabena Baah Kyei', 'Male'],
        ['Esinam Kpodo Agbeko', 'Female'],
        ['Bright Owusu Darko', 'Male'],
        ['Celestina Appiah Kuffour', 'Female'],
    ];

    $stmtI = $pdo->prepare("INSERT IGNORE INTO students 
        (index_number, enrolment_code, full_name, gender, program, residency, aggregate, region) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

    $baseIndex = 100000000001;
    foreach ($names as $i => $student) {
        $indexNo = strval($baseIndex + $i);
        $code    = strval(rand(10000, 99999));
        $prog    = $programs[array_rand($programs)];
        $res     = $residency[array_rand($residency)];
        $agg     = $aggregates[array_rand($aggregates)];
        $reg     = $regions[array_rand($regions)];
        $stmtI->execute([$indexNo, $code, $student[0], $student[1], $prog, $res, $agg, $reg]);
    }
    echo "✓ 20 sample student placement records seeded\n";

    // -----------------------------------------------------------
    // 4. Default Boarding Houses
    // -----------------------------------------------------------
    $defaultHouses = [
        ['St. George House', 'Male', 60],
        ['St. Patrick House', 'Male', 60],
        ['St. Mary House', 'Female', 50],
        ['St. Theresa House', 'Female', 50],
    ];
    $stmtH = $pdo->prepare("INSERT IGNORE INTO houses (name, gender, capacity) VALUES (?, ?, ?)");
    foreach ($defaultHouses as $house) {
        $stmtH->execute($house);
    }
    echo "✓ Default boarding houses seeded\n\n";

    echo "=== Seeding Complete! ===\n";
    echo "Admin login: admin / admin123\n";
    echo "Visit: http://kimtech.myonlineadmission.com/admin\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
