<?php

declare(strict_types=1);

return [
    'builder' => 'bricks',
    'structure' => 'flat',
    'class_setting' => '_cssGlobalClasses',
    'class_ref_mode' => 'id',
    'elements' => [
        'section' => [
            'native' => 'section',
            'container' => true,
            'keys' => [],
        ],
        'container' => [
            'native' => 'container',
            'container' => true,
            'keys' => [],
        ],
        'block' => [
            'native' => 'block',
            'container' => true,
            'keys' => [],
        ],
        'heading' => [
            'native' => 'heading',
            'container' => false,
            'keys' => ['text', 'tag'],
            'allowed_tags' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div'],
            'native_tags' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
            'custom_tag_setting' => 'customTag',
            'custom_tag_trigger' => 'custom',
            'default_tag' => 'h3',
        ],
        'text' => [
            'native' => 'text',
            'container' => false,
            'keys' => ['html'],
        ],
        'button' => [
            'native' => 'button',
            'container' => false,
            'keys' => ['text', 'link'],
        ],
        'image' => [
            'native' => 'image',
            'container' => false,
            'keys' => ['media_id', 'url', 'alt'],
            'default_size' => 'large',
        ],
        'icon' => [
            'native' => 'icon',
            'container' => false,
            'keys' => ['icon'],
        ],
        'list' => [
            'native' => 'list',
            'container' => false,
            'keys' => ['items'],
        ],
    ],
];
