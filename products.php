<?php
// Product BOMs. "used_parts" → deducted from inventory when an order is logged.
// "contains" → display only, no inventory impact.
const PRODUCTS = [
    '2 Foot Cable' => [
        'used_parts' => [
            'Terminals'            => 3,
            'Wire Seals'           => 3,
            '3 Pin Connectors'     => 1,
            'Full Cables'          => 1,
            'Envelopes'            => 1,
            'Small Shrink Tube'    => 1.5,
            'Large Shrink Tube'    => 1,
            'Large Cellophane Bags'=> 1,
        ],
        'contains' => ['Black Wire', 'Red Wire', 'Solder'],
        'image'    => '2_foot_cable.png',
    ],
    '4 Foot Cable' => [
        'used_parts' => [
            'Terminals'            => 3,
            'Wire Seals'           => 3,
            '3 Pin Connectors'     => 1,
            'Full Cables'          => 1,
            'Envelopes'            => 1,
            'Small Shrink Tube'    => 1.5,
            'Large Shrink Tube'    => 1,
            'Large Cellophane Bags'=> 1,
        ],
        'contains' => ['Black Wire', 'Red Wire', 'Solder'],
        'image'    => '4_foot_cable.png',
    ],
    'Short Cable' => [
        'used_parts' => [
            'Terminals'            => 3,
            'Wire Seals'           => 3,
            '3 Pin Connectors'     => 1,
            'Short Cables'         => 1,
            'Envelopes'            => 1,
            'Small Shrink Tube'    => 1.5,
            'Large Shrink Tube'    => 1,
            'Large Cellophane Bags'=> 1,
        ],
        'contains' => ['Black Wire', 'Red Wire', 'Solder'],
        'image'    => 'short_cable.png',
    ],
    'Rear Box' => [
        'used_parts' => [
            'Terminals'             => 3,
            '3 Pin Connectors'      => 1,
            'Audio Jacks'           => 1,
            'Envelopes'             => 1,
            'Small Shrink Tube'     => 0.5,
            'Box Lids'              => 1,
            'Rear Boxes'            => 1,
            'Small Cellophane Bags' => 1,
        ],
        'contains' => ['Black Wire', 'Red Wire', 'UV Resin', 'Thread Locker', 'Solder'],
        'image'    => 'rear_box.png',
    ],
    'Front Box' => [
        'used_parts' => [
            'Terminals'             => 3,
            '3 Pin Connectors'      => 1,
            'Audio Jacks'           => 1,
            'Envelopes'             => 1,
            'Small Shrink Tube'     => 0.5,
            'Box Lids'              => 1,
            'Front Boxes'           => 1,
            'Small Cellophane Bags' => 1,
        ],
        'contains' => ['Black Wire', 'Red Wire', 'UV Resin', 'Thread Locker', 'Solder'],
        'image'    => 'front_box.png',
    ],
    'Output Jack' => [
        'used_parts' => [
            '8 Pin Connectors'     => 1,
            'Terminals'            => 3,
            'Audio Jacks'          => 1,
            'Small Shrink Tube'    => 1.25,
            'Large Shrink Tube'    => 0.25,
            'Large Cellophane Bags'=> 1,
        ],
        'contains' => ['Red Wire', 'Black Wire', 'Mesh Wire Loom', 'Solder'],
        'image'    => 'output_jack.png',
    ],
    'Charge Box' => [
        'used_parts' => [
            '3 Pin Connectors'     => 1,
            'Audio Jacks'          => 1,
            'Terminals'            => 5,
            'USB Charge Boards'    => 1,
            '4 Pin Connectors'     => 1,
            'Small Shrink Tube'    => 0.5,
            'Charge Box Lids'      => 1,
            'Charge Boxes'         => 1,
            'Long Cellophane Bags' => 1,
        ],
        'contains' => ['Blue Wire', 'Yellow Wire', 'Green Wire', 'Black Wire', 'Red Wire', 'UV Resin', 'Mesh Wire Loom'],
        'image'    => 'charge_box.png',
    ],
];

// Part name → image filename in static/images/parts/
const PART_IMAGES = [
    'Terminals'             => 'terminals.png',
    'Wire Seals'            => 'wire_seals.png',
    'Audio Jacks'           => 'aux_ports.png',
    '3 Pin Connectors'      => 'connectors.png',
    '8 Pin Connectors'      => '8_pin_connectors.png',
    'Box Lids'              => 'box_lids.png',
    'Rear Boxes'            => 'rear_boxes.png',
    'Front Boxes'           => 'front_boxes.png',
    'Small Shrink Tube'     => 'small_shrink_tube.png',
    'Large Shrink Tube'     => 'large_shrink_tube.png',
    'Envelopes'             => 'envelopes.png',
    'Full Cables'           => 'full_cables.png',
    'Short Cables'          => 'short_cables.png',
    'Audio Jack Nuts'       => 'aux_port_nuts.png',
    'Large Cellophane Bags' => 'large_bags.png',
    'Small Cellophane Bags' => 'small_bags.png',
    'Long Cellophane Bags'  => 'long_bags.png',
    '4 Pin Connectors'      => '4_pin_connectors.png',
    'USB Charge Boards'     => 'usb_charge_board.png',
    'Charge Box Lids'       => 'charge_box_lids.png',
    'Charge Boxes'          => 'charge_boxes.png',
    // Ready-stock parts (images in products/ folder)
    '2 Foot Cable [Ready]'  => '2_foot_cable.png',
    '4 Foot Cable [Ready]'  => '4_foot_cable.png',
    'Short Cable [Ready]'   => 'short_cable.png',
    'Rear Box [Ready]'      => 'rear_box.png',
    'Front Box [Ready]'     => 'front_box.png',
    'Output Jack [Ready]'   => 'output_jack.png',
    'Charge Box [Ready]'    => 'charge_box.png',
];

// Maps product name → Part name that holds pre-made finished-goods stock
const PRODUCT_STOCK_PARTS = [
    '2 Foot Cable' => '2 Foot Cable [Ready]',
    '4 Foot Cable' => '4 Foot Cable [Ready]',
    'Short Cable'  => 'Short Cable [Ready]',
    'Rear Box'     => 'Rear Box [Ready]',
    'Front Box'    => 'Front Box [Ready]',
    'Output Jack'  => 'Output Jack [Ready]',
    'Charge Box'   => 'Charge Box [Ready]',
];

// All parts to seed into the DB
const SEED_PARTS = [
    'Terminals', 'Wire Seals', 'Audio Jacks', '3 Pin Connectors', '8 Pin Connectors',
    'Box Lids', 'Rear Boxes', 'Front Boxes', 'Small Shrink Tube', 'Large Shrink Tube',
    'Envelopes', 'Full Cables', 'Short Cables', 'Audio Jack Nuts',
    'Large Cellophane Bags', 'Small Cellophane Bags', 'Long Cellophane Bags',
    '4 Pin Connectors', 'USB Charge Boards', 'Charge Box Lids', 'Charge Boxes',
    '2 Foot Cable [Ready]', '4 Foot Cable [Ready]', 'Short Cable [Ready]',
    'Rear Box [Ready]', 'Front Box [Ready]', 'Output Jack [Ready]', 'Charge Box [Ready]',
];

const CHART_COLORS = [
    '#39ff14', '#00cfff', '#ff4444', '#ffaa00', '#cc00ff',
    '#00ff88', '#ff6eb4', '#ffe100', '#4fc3f7', '#a5d6a7',
    '#ff8f00', '#b0bec5', '#f48fb1', '#80cbc4', '#ce93d8',
    '#ef9a9a', '#80deea', '#c5e1a5',
];
