<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\DesignContext;

final class StarterPreset
{
    public const NAME = 'starter';

    /**
     * Return the canonical Ashlar 4-layer Starter Preset definition (Overview §6).
     * Every component/base class reads ONLY primitives; island classes rebind primitives to vocabulary tokens.
     *
     * @return array{
     *   colors: list<array{name: string, css_var: string, value: string, role: string}>,
     *   variables: list<array{name: string, value: string, layer: string, role: string}>,
     *   classes: list<array{name: string, kind: string, purpose: string, declarations: array<string, string>}>
     * }
     */
    public static function definition(): array
    {
        return [
            'colors' => [
                [
                    'name' => 'brand-primary',
                    'css_var' => '--brand-primary',
                    'value' => '#1e40af',
                    'role' => 'Primary brand color',
                ],
                [
                    'name' => 'brand-secondary',
                    'css_var' => '--brand-secondary',
                    'value' => '#0f172a',
                    'role' => 'Secondary dark brand color',
                ],
                [
                    'name' => 'brand-accent',
                    'css_var' => '--brand-accent',
                    'value' => '#f59e0b',
                    'role' => 'Highlight and call-to-action accent color',
                ],
                [
                    'name' => 'brand-light',
                    'css_var' => '--brand-light',
                    'value' => '#ffffff',
                    'role' => 'Light neutral base color',
                ],
                [
                    'name' => 'brand-surface-alt',
                    'css_var' => '--brand-surface-alt',
                    'value' => '#f8fafc',
                    'role' => 'Subtle recessed surface tint',
                ],
                [
                    'name' => 'brand-muted',
                    'css_var' => '--brand-muted',
                    'value' => '#475569',
                    'role' => 'Muted supporting text color',
                ],
                [
                    'name' => 'brand-border',
                    'css_var' => '--brand-border',
                    'value' => '#e2e8f0',
                    'role' => 'Neutral structural border color',
                ],
            ],
            'variables' => [
                // Layer 2: Scale
                [
                    'name' => '--space-xs',
                    'value' => '0.5rem',
                    'layer' => 'scale',
                    'role' => 'Extra-small spacing step',
                ],
                [
                    'name' => '--space-s',
                    'value' => '0.75rem',
                    'layer' => 'scale',
                    'role' => 'Small spacing step',
                ],
                [
                    'name' => '--space-m',
                    'value' => 'clamp(1rem, 2vw, 1.5rem)',
                    'layer' => 'scale',
                    'role' => 'Medium spacing step',
                ],
                [
                    'name' => '--space-l',
                    'value' => 'clamp(1.5rem, 3vw, 2.5rem)',
                    'layer' => 'scale',
                    'role' => 'Large spacing step',
                ],
                [
                    'name' => '--space-xl',
                    'value' => 'clamp(2.5rem, 5vw, 4rem)',
                    'layer' => 'scale',
                    'role' => 'Extra-large spacing step',
                ],
                [
                    'name' => '--space-section',
                    'value' => 'clamp(4rem, 8vw, 7rem)',
                    'layer' => 'scale',
                    'role' => 'Vertical section padding scale',
                ],
                [
                    'name' => '--text-sm',
                    'value' => '0.875rem',
                    'layer' => 'scale',
                    'role' => 'Small supporting text size',
                ],
                [
                    'name' => '--text-base',
                    'value' => '1rem',
                    'layer' => 'scale',
                    'role' => 'Body copy font size',
                ],
                [
                    'name' => '--text-lg',
                    'value' => 'clamp(1.125rem, 1.5vw, 1.25rem)',
                    'layer' => 'scale',
                    'role' => 'Lead paragraph / card heading size',
                ],
                [
                    'name' => '--text-xl',
                    'value' => 'clamp(1.5rem, 2.5vw, 2rem)',
                    'layer' => 'scale',
                    'role' => 'Section subheading size',
                ],
                [
                    'name' => '--text-2xl',
                    'value' => 'clamp(2rem, 3.5vw, 2.75rem)',
                    'layer' => 'scale',
                    'role' => 'Section H2 heading size',
                ],
                [
                    'name' => '--text-display',
                    'value' => 'clamp(2.5rem, 5vw, 4rem)',
                    'layer' => 'scale',
                    'role' => 'Hero display H1 heading size',
                ],
                [
                    'name' => '--line-normal',
                    'value' => '1.6',
                    'layer' => 'scale',
                    'role' => 'Standard body line-height',
                ],
                [
                    'name' => '--line-tight',
                    'value' => '1.15',
                    'layer' => 'scale',
                    'role' => 'Tight heading line-height',
                ],
                [
                    'name' => '--radius-s',
                    'value' => '0.375rem',
                    'layer' => 'scale',
                    'role' => 'Small border radius',
                ],
                [
                    'name' => '--radius-m',
                    'value' => '0.75rem',
                    'layer' => 'scale',
                    'role' => 'Medium card border radius',
                ],
                [
                    'name' => '--radius-l',
                    'value' => '1.25rem',
                    'layer' => 'scale',
                    'role' => 'Large container border radius',
                ],
                [
                    'name' => '--container-max',
                    'value' => '80rem',
                    'layer' => 'scale',
                    'role' => 'Maximum content container width',
                ],

                // Layer 3: Vocabulary (Bricks category: surface)
                [
                    'name' => '--bg-base',
                    'value' => 'var(--brand-light)',
                    'layer' => 'vocabulary',
                    'role' => 'Default page surface background',
                ],
                [
                    'name' => '--bg-sunken',
                    'value' => 'var(--brand-surface-alt)',
                    'layer' => 'vocabulary',
                    'role' => 'Recessed / alternating section surface',
                ],
                [
                    'name' => '--bg-elevated',
                    'value' => 'var(--brand-light)',
                    'layer' => 'vocabulary',
                    'role' => 'Elevated card surface background',
                ],
                [
                    'name' => '--bg-brand',
                    'value' => 'var(--brand-primary)',
                    'layer' => 'vocabulary',
                    'role' => 'Primary brand island surface',
                ],
                [
                    'name' => '--bg-dark',
                    'value' => 'var(--brand-secondary)',
                    'layer' => 'vocabulary',
                    'role' => 'Dark high-contrast island surface',
                ],
                [
                    'name' => '--fg-default',
                    'value' => 'var(--brand-secondary)',
                    'layer' => 'vocabulary',
                    'role' => 'Primary reading text color',
                ],
                [
                    'name' => '--fg-muted',
                    'value' => 'var(--brand-muted)',
                    'layer' => 'vocabulary',
                    'role' => 'Secondary muted text color',
                ],
                [
                    'name' => '--fg-on-brand',
                    'value' => 'var(--brand-light)',
                    'layer' => 'vocabulary',
                    'role' => 'Foreground text on brand surface',
                ],
                [
                    'name' => '--fg-on-dark',
                    'value' => 'var(--brand-light)',
                    'layer' => 'vocabulary',
                    'role' => 'Foreground text on dark surface',
                ],
                [
                    'name' => '--border-subtle',
                    'value' => 'var(--brand-border)',
                    'layer' => 'vocabulary',
                    'role' => 'Subtle card and section divider border',
                ],
                [
                    'name' => '--action-primary',
                    'value' => 'var(--brand-primary)',
                    'layer' => 'vocabulary',
                    'role' => 'Primary interactive button surface',
                ],
                [
                    'name' => '--action-primary-fg',
                    'value' => 'var(--brand-light)',
                    'layer' => 'vocabulary',
                    'role' => 'Primary interactive button label color',
                ],

                // Layer 4: Primitives (what classes actually read)
                [
                    'name' => '--color-bg',
                    'value' => 'var(--bg-base)',
                    'layer' => 'primitives',
                    'role' => 'Active surface background read by classes',
                ],
                [
                    'name' => '--color-fg',
                    'value' => 'var(--fg-default)',
                    'layer' => 'primitives',
                    'role' => 'Active foreground text color read by classes',
                ],
                [
                    'name' => '--color-fg-muted',
                    'value' => 'var(--fg-muted)',
                    'layer' => 'primitives',
                    'role' => 'Active muted foreground color read by classes',
                ],
                [
                    'name' => '--color-border',
                    'value' => 'var(--border-subtle)',
                    'layer' => 'primitives',
                    'role' => 'Active border color read by classes',
                ],
                [
                    'name' => '--color-action',
                    'value' => 'var(--action-primary)',
                    'layer' => 'primitives',
                    'role' => 'Active primary button background primitive',
                ],
                [
                    'name' => '--color-action-fg',
                    'value' => 'var(--action-primary-fg)',
                    'layer' => 'primitives',
                    'role' => 'Active primary button text primitive',
                ],
                [
                    'name' => '--gap',
                    'value' => 'var(--space-m)',
                    'layer' => 'primitives',
                    'role' => 'Default flex/grid gap primitive',
                ],
                [
                    'name' => '--padding-x',
                    'value' => 'var(--space-m)',
                    'layer' => 'primitives',
                    'role' => 'Horizontal component/container padding primitive',
                ],
                [
                    'name' => '--padding-y',
                    'value' => 'var(--space-m)',
                    'layer' => 'primitives',
                    'role' => 'Vertical component padding primitive',
                ],
                [
                    'name' => '--section-padding-y',
                    'value' => 'var(--space-section)',
                    'layer' => 'primitives',
                    'role' => 'Vertical section padding primitive',
                ],
                [
                    'name' => '--radius',
                    'value' => 'var(--radius-m)',
                    'layer' => 'primitives',
                    'role' => 'Default border radius primitive',
                ],
                [
                    'name' => '--max-width',
                    'value' => 'var(--container-max)',
                    'layer' => 'primitives',
                    'role' => 'Container max-width primitive',
                ],
                [
                    'name' => '--font-size-body',
                    'value' => 'var(--text-base)',
                    'layer' => 'primitives',
                    'role' => 'Body font size primitive',
                ],
                [
                    'name' => '--font-size-lg',
                    'value' => 'var(--text-lg)',
                    'layer' => 'primitives',
                    'role' => 'Lead/card title font size primitive',
                ],
                [
                    'name' => '--font-size-xl',
                    'value' => 'var(--text-xl)',
                    'layer' => 'primitives',
                    'role' => 'Subheading font size primitive',
                ],
                [
                    'name' => '--font-size-2xl',
                    'value' => 'var(--text-2xl)',
                    'layer' => 'primitives',
                    'role' => 'Section heading font size primitive',
                ],
                [
                    'name' => '--font-size-display',
                    'value' => 'var(--text-display)',
                    'layer' => 'primitives',
                    'role' => 'Hero display font size primitive',
                ],
                [
                    'name' => '--line-height-body',
                    'value' => 'var(--line-normal)',
                    'layer' => 'primitives',
                    'role' => 'Body line-height primitive',
                ],
                [
                    'name' => '--line-height-heading',
                    'value' => 'var(--line-tight)',
                    'layer' => 'primitives',
                    'role' => 'Heading line-height primitive',
                ],
            ],
            'classes' => [
                // Base classes
                [
                    'name' => 'section',
                    'kind' => 'base',
                    'purpose' => 'Standard full-width page section wrapper',
                    'declarations' => [
                        'background-color' => 'var(--color-bg)',
                        'color' => 'var(--color-fg)',
                        'padding-block' => 'var(--section-padding-y)',
                        'padding-inline' => 'var(--padding-x)',
                    ],
                ],
                [
                    'name' => 'container',
                    'kind' => 'base',
                    'purpose' => 'Centered inner content container',
                    'declarations' => [
                        'width' => '100%',
                        'max-width' => 'var(--max-width)',
                        'margin-inline' => 'auto',
                        'display' => 'flex',
                        'flex-direction' => 'column',
                        'gap' => 'var(--gap)',
                    ],
                ],

                // Component classes
                [
                    'name' => 'hero',
                    'kind' => 'component',
                    'purpose' => 'Top-of-page hero banner section',
                    'declarations' => [
                        'background-color' => 'var(--color-bg)',
                        'color' => 'var(--color-fg)',
                        'padding-block' => 'var(--section-padding-y)',
                        'padding-inline' => 'var(--padding-x)',
                        'display' => 'flex',
                        'flex-direction' => 'column',
                        'gap' => 'var(--gap)',
                    ],
                ],
                [
                    'name' => 'card',
                    'kind' => 'component',
                    'purpose' => 'Contained feature or content card',
                    'declarations' => [
                        'background-color' => 'var(--color-bg)',
                        'color' => 'var(--color-fg)',
                        'border' => '1px solid var(--color-border)',
                        'border-radius' => 'var(--radius)',
                        'padding-block' => 'var(--padding-y)',
                        'padding-inline' => 'var(--padding-x)',
                        'display' => 'flex',
                        'flex-direction' => 'column',
                        'gap' => 'var(--gap)',
                    ],
                ],
                [
                    'name' => 'grid-2',
                    'kind' => 'component',
                    'purpose' => 'Two-column responsive layout grid',
                    'declarations' => [
                        'display' => 'grid',
                        'grid-template-columns' => '1fr 1fr',
                        'gap' => 'var(--gap)',
                    ],
                ],
                [
                    'name' => 'grid-3',
                    'kind' => 'component',
                    'purpose' => 'Three-column responsive layout grid',
                    'declarations' => [
                        'display' => 'grid',
                        'grid-template-columns' => '1fr 1fr 1fr',
                        'gap' => 'var(--gap)',
                    ],
                ],
                [
                    'name' => 'stats-grid',
                    'kind' => 'component',
                    'purpose' => 'Key metrics row container',
                    'declarations' => [
                        'display' => 'grid',
                        'grid-template-columns' => '1fr 1fr 1fr',
                        'gap' => 'var(--gap)',
                    ],
                ],
                [
                    'name' => 'stat',
                    'kind' => 'component',
                    'purpose' => 'Single metric highlight item inside stats-grid',
                    'declarations' => [
                        'display' => 'flex',
                        'flex-direction' => 'column',
                        'gap' => 'var(--gap)',
                        'padding-block' => 'var(--padding-y)',
                        'padding-inline' => 'var(--padding-x)',
                    ],
                ],
                [
                    'name' => 'cta-banner',
                    'kind' => 'component',
                    'purpose' => 'Call-to-action callout block',
                    'declarations' => [
                        'background-color' => 'var(--color-bg)',
                        'color' => 'var(--color-fg)',
                        'border-radius' => 'var(--radius)',
                        'padding-block' => 'var(--padding-y)',
                        'padding-inline' => 'var(--padding-x)',
                        'display' => 'flex',
                        'flex-direction' => 'column',
                        'gap' => 'var(--gap)',
                    ],
                ],
                [
                    'name' => 'btn-primary',
                    'kind' => 'component',
                    'purpose' => 'Primary call-to-action button',
                    'declarations' => [
                        'background-color' => 'var(--color-action)',
                        'color' => 'var(--color-action-fg)',
                        'border-radius' => 'var(--radius)',
                        'padding-block' => 'var(--padding-y)',
                        'padding-inline' => 'var(--padding-x)',
                    ],
                ],

                // Theme Islands (rebind primitives locally)
                [
                    'name' => 'island-sunken',
                    'kind' => 'island',
                    'purpose' => 'Section switches to recessed sunken surface palette',
                    'declarations' => [
                        '--color-bg' => 'var(--bg-sunken)',
                        '--color-fg' => 'var(--fg-default)',
                        '--color-fg-muted' => 'var(--fg-muted)',
                        'background-color' => 'var(--color-bg)',
                        'color' => 'var(--color-fg)',
                    ],
                ],
                [
                    'name' => 'island-brand',
                    'kind' => 'island',
                    'purpose' => 'Section switches to primary brand surface palette',
                    'declarations' => [
                        '--color-bg' => 'var(--bg-brand)',
                        '--color-fg' => 'var(--fg-on-brand)',
                        '--color-fg-muted' => 'var(--fg-on-brand)',
                        'background-color' => 'var(--color-bg)',
                        'color' => 'var(--color-fg)',
                    ],
                ],
                [
                    'name' => 'island-dark',
                    'kind' => 'island',
                    'purpose' => 'Section switches to dark high-contrast surface palette',
                    'declarations' => [
                        '--color-bg' => 'var(--bg-dark)',
                        '--color-fg' => 'var(--fg-on-dark)',
                        '--color-fg-muted' => 'var(--fg-on-dark)',
                        'background-color' => 'var(--color-bg)',
                        'color' => 'var(--color-fg)',
                    ],
                ],
            ],
        ];
    }
}
