<?php
// Copy to config.php (install.php does this for you) and fill in your details.
return [
    'app_name' => 'DevTech Staff Portal',
    'company'  => 'DevTech Hub Ventures',
    'address'  => 'Ikechy Plaza, Plot 947 Corridor Layout, Shop 17/18 Monaque Junction, Enugu State',
    'domain'   => 'devtech.ng',
    'base_url' => '',              // e.g. https://portal.example.com (used in email links)
    'timezone' => 'Africa/Lagos',
    'currency' => '₦',
    'debug'    => false,

    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => '',
        'user' => '',
        'pass' => '',
    ],

    // Email notifications. Leave host empty to use PHP mail().
    'smtp' => [
        'enabled'    => false,
        'host'       => '',        // e.g. mail.yourdomain.com
        'port'       => 465,       // 465 = SSL, 587 = STARTTLS
        'secure'     => 'ssl',     // ssl | tls | none
        'user'       => '',
        'pass'       => '',
        'from_email' => '',        // e.g. portal@devtech.ng
        'from_name'  => 'DevTech Staff Portal',
    ],

    'upload_max_mb'   => 8,
    'upload_max_files'=> 6,
    'poll_seconds'    => 10,

    'locations' => [
        'Donbrowno College', 'Donbrowno Primary', 'Tenderlinks College', 'Tenderlinks Primary',
        'Mayday School', 'Soar High', 'Brightland School', 'Office', 'Other (specify in remarks)',
    ],
    'expense_categories' => [
        'Transportation', 'Work materials / items bought', 'Logistics / delivery',
        'Data / airtime', 'Feeding', 'Repairs / parts', 'Other',
    ],
    'credit_types' => ['Daily allowance', 'Work budget', 'Top-up'],
    'daily_allowance_default' => 0,
];
