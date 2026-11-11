<?php

declare(strict_types=1);

return [
    'builder' => 'elementor',
    'structure' => 'nested',
    'class_setting' => '_css_classes',
    'class_ref_mode' => 'name',
    'elements' => [
        'section' => [
            'native' => 'container',
            'container' => true,
            'is_inner' => false,
            'keys' => [],
        ],
        'container' => [
            'native' => 'container',
            'container' => true,
            'is_inner' => true,
            'keys' => [],
        ],
        'block' => [
            'native' => 'container',
            'container' => true,
            'is_inner' => true,
            'keys' => [],
        ],
        'heading' => [
            'native' => 'heading',
            'widget_type' => 'heading',
            'container' => false,
            'keys' => ['text', 'tag'],
            'text_key' => 'title',
            'tag_key' => 'header_size',
            'allowed_tags' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div'],
            'native_tags' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
            'default_tag' => 'h3',
        ],
        'text' => [
            'native' => 'text-editor',
            'widget_type' => 'text-editor',
            'container' => false,
            'keys' => ['html'],
            'text_key' => 'editor',
        ],
        'button' => [
            'native' => 'button',
            'widget_type' => 'button',
            'container' => false,
            'keys' => ['text', 'link'],
            'text_key' => 'text',
            'link_key' => 'link',
        ],
        'image' => [
            'native' => 'image',
            'widget_type' => 'image',
            'container' => false,
            'keys' => ['media_id', 'url', 'alt'],
            'default_size' => 'large',
        ],
        'icon' => [
            'native' => 'icon',
            'widget_type' => 'icon',
            'container' => false,
            'keys' => ['icon'],
        ],
        'list' => [
            'native' => 'icon-list',
            'widget_type' => 'icon-list',
            'container' => false,
            'keys' => ['items'],
        ],
    ],
];
