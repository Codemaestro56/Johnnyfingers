<?php
// config/courses_config.php

const CURRENCY_SYMBOL = '£';
const CURRENCY_CODE   = 'EUR';

$COURSES = [
    'wash_repair' => [
        'title'     => 'Washing Machine Repair Track',
        'price_eur' => 299,
        'cents'     => 29900,
        'lessons'   => 4,
        'image'     => 'https://images.unsplash.com/photo-1626806819282-2c1dc01a5e0c?auto=format&fit=crop&w=800&q=80',
        'videos'    => [
            'https://www.youtube.com/embed/ScMzIvxBSi4',
            'https://www.youtube.com/embed/ScMzIvxBSi4',
            'https://www.youtube.com/embed/ScMzIvxBSi4',
            'https://www.youtube.com/embed/ScMzIvxBSi4',
            'https://www.youtube.com/embed/ScMzIvxBSi4',
            'https://www.youtube.com/embed/ScMzIvxBSi4'
        ]
    ],
    'dryer_repair' => [
        'title'     => 'Tumble Dryer Repair Track',
        'price_eur' => 299,
        'cents'     => 29900,
        'lessons'   => 4,
        'image'     => 'https://images.unsplash.com/photo-1582735689369-4fe89db7114c?auto=format&fit=crop&w=800&q=80',
        'videos'    => [
            'https://www.youtube.com/embed/ScMzIvxBSi4',
            'https://www.youtube.com/embed/ScMzIvxBSi4',
            'https://www.youtube.com/embed/ScMzIvxBSi4',
            'https://www.youtube.com/embed/ScMzIvxBSi4',
            'https://www.youtube.com/embed/ScMzIvxBSi4'
        ]
    ],
    'cooker_repair' => [
        'title'     => 'Electric Cooker & Oven Track',
        'price_eur' => 299,
        'cents'     => 29900,
        'lessons'   => 4,
        'image'     => 'https://images.unsplash.com/photo-1556911220-e15b29be8c8f?auto=format&fit=crop&w=800&q=80',
        'videos'    => [
            'https://www.youtube.com/embed/ScMzIvxBSi4',
            'https://www.youtube.com/embed/ScMzIvxBSi4',
            'https://www.youtube.com/embed/ScMzIvxBSi4',
            'https://www.youtube.com/embed/ScMzIvxBSi4'
        ]
    ]
];

$QUIZZES = [
    'wash_repair-QUIZ' => [
        'course_code' => 'wash_repair',
        'questions' => [
            [
                'q' => 'What is the safest first step before starting a washing machine repair?',
                'options' => ['Disconnect power and unplug the machine', 'Open the drum while plugged in', 'Start the spin cycle', 'Pour water into the drum'],
                'answer' => 0
            ],
            [
                'q' => 'Which tool is commonly used to remove the back panel?',
                'options' => ['Phillips screwdriver', 'Hammer', 'Wrench', 'Pliers'],
                'answer' => 0
            ],
            [
                'q' => 'A persistent burning smell usually indicates:',
                'options' => ['Electrical fault or overheated motor', 'Low water level', 'Good ventilation', 'Proper grounding'],
                'answer' => 0
            ]
        ],
        'passing_percent' => 70
    ]
];