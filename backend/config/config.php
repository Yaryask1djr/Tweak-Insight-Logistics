<?php
// Tweak Insight Logistics Configuration
return [
    'app_name' => 'Tweak Insight Logistics',
    'currency' => 'NGN',
    'currency_symbol' => '₦',
    
    // Operating State & Cities
    'state' => 'Kano State',
    'supported_cities' => ['Kano'],
    
    // Key Operational Hubs in Kano
    'hubs' => [
        ['name' => 'Kano Municipal', 'zone' => 'Central', 'avg_turnaround' => '20-40 min'],
        ['name' => 'Fagge & Kantin Kwari', 'zone' => 'Commercial Wholesale', 'avg_turnaround' => '15-30 min'],
        ['name' => 'Sabon Gari Market', 'zone' => 'Retail & Electronics', 'avg_turnaround' => '20-35 min'],
        ['name' => 'Tarauni & Farm Centre', 'zone' => 'Commercial & Telecoms', 'avg_turnaround' => '25-45 min'],
        ['name' => 'Nassarawa & GRA', 'zone' => 'Corporate & Residential', 'avg_turnaround' => '20-40 min'],
        ['name' => 'Gwale & Dala', 'zone' => 'Historic & Residential', 'avg_turnaround' => '30-50 min'],
        ['name' => 'Bompai Industrial Area', 'zone' => 'Industrial & Manufacturing', 'avg_turnaround' => '25-45 min'],
        ['name' => 'Kumbotso & Challawa', 'zone' => 'Industrial Corridors', 'avg_turnaround' => '35-60 min'],
    ],

    // Pricing Architecture (Dynamic rates within Kano State)
    'pricing' => [
        'base_fare' => 500.00,       // Base dispatch fare for first 5km
        'base_km' => 5.0,
        'per_km_rate' => 50.00,      // Rate per additional km
        'base_weight_kg' => 3.0,      // Included weight in base fare
        'per_kg_rate' => 50.00,      // Rate per additional kg
        'fragile_surcharge' => 100.00,
        'perishable_surcharge' => 100.00,
        'driver_commission_pct' => 0.65, // 65% to driver; the remainder stays with the platform
    ]
];
