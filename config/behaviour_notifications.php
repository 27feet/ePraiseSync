<?php

return [
    'enable_legacy_rules' => false,

    'year_group_fallback_emails' => [
        'Year 7' => 'year7@hanleycastlshs.org.uk',
        'Year 8' => 'year8@hanleycastlshs.org.uk',
        'Year 9' => 'year9@hanleycastlshs.org.uk',
        'Year 10' => 'year10@hanleycastlshs.org.uk',
        'Year 11' => 'year11@hanleycastlshs.org.uk',
        'Year 12' => 'year12@hanleycastlshs.org.uk',
        'Year 13' => 'year13@hanleycastlshs.org.uk',
    ],

    // Used if the map above does not contain the year group.
    // Use {year} as a placeholder for the numeric year.
    'year_group_fallback_pattern' => 'year{year}@hanleycastlshs.org.uk',
];
