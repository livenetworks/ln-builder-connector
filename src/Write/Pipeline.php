<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Write;

use LiveNetworks\BuilderConnector\Audit\AuditLogger;
use LiveNetworks\BuilderConnector\Auth\RequestContext;
use LiveNetworks\BuilderConnector\Builders\BuilderAdapter;
use LiveNetworks\BuilderConnector\Converter\Compactor;
use LiveNetworks\BuilderConnector\Converter\DesignValidator;
use LiveNetworks\BuilderConnector\Converter\Expander;
use LiveNetworks\BuilderConnector\Converter\Patcher;
use LiveNetworks\BuilderConnector\Converter\Problem;
use LiveNetworks\BuilderConnector\Converter\Revision;
use LiveNetworks\BuilderConnector\DesignContext\DesignContextBuilder;
use LiveNetworks\BuilderConnector\DesignContext\StarterPreset;
use LiveNetworks\BuilderConnector\Meta\MetaRepository;
use LiveNetworks\BuilderConnector\Rest\Responder;
use LiveNetworks\BuilderConnector\Snapshots\SnapshotRepository;

final class Pipeline
{
    public const LOCK_TIMEOUT_SECONDS = 5;

    private BuilderAdapter $adapter;
    private SnapshotRepository $snapshots;
    private AuditLogger $audit;
    private MetaRepository $metaRepo;

    public function __construct(
        BuilderAdapter $adapter,
        ?SnapshotRepository $snapshots = null,
        ?AuditLogger $audit = null,
        ?MetaRepository $metaRepo = null
    ) {
        $this->adapter = $adapter;
        $this->snapshots = $snapshots ?? new SnapshotRepository();
        $this->audit = $audit ?? new AuditLogger();
        $this->metaRepo = $metaRepo ?? new MetaRepository();
    }

    /**
     * Execute the full write pipeline for `POST /templates` (Contract §5.2, Plugin §7.1).
     *
     * @param array<string, mixed> $body
     */
    public function createTemplate(array $body): \WP_REST_Response
    {
        $opName = 'POST /templates';
        $envelopeErrors = [];

        $rawTitle = $body['title'] ?? null;
        if (!is_string($rawTitle) || trim(sanitize_text_field($rawTitle)) === '') {
            $envelopeErrors[] = new Problem('title', 'missing_required', "Field 'title' is required and must be a non-empty string.");
        }

        $rawSlug = $body['slug'] ?? null;
        if ($rawSlug !== null && !is_string($rawSlug)) {
            $envelopeErrors[] = new Problem('slug', 'invalid_value', "Field 'slug' must be a string when provided.");
        }

        $rawType = $body['type'] ?? null;
        $allowedTypes = $this->adapter->capabilities()->templateTypes();
        if (!is_string($rawType) || !in_array($rawType, $allowedTypes, true)) {
            $envelopeErrors[] = new Problem(
                'type',
                'invalid_value',
                sprintf("Field 'type' must be one of: %s.", implode(', ', $allowedTypes))
            );
        }

        $document = $body['document'] ?? null;
        if (!is_array($document)) {
            $envelopeErrors[] = new Problem('document', 'missing_required', "Field 'document' is required and must be a JSON object.");
        }

        if ($envelopeErrors !== []) {
            $this->audit->record($opName, 'template', 'new', 'validation_failed');
            return Responder::validationFailed($envelopeErrors);
        }

        /** @var string $rawTitle */
        /** @var string $rawType */
        /** @var array<string, mixed> $document */
        $cleanTitle = trim(sanitize_text_field($rawTitle));
        $cleanSlug = is_string($rawSlug) && trim($rawSlug) !== '' ? sanitize_title($rawSlug) : null;
        $expectedArea = $this->adapter->areaForTemplateType($rawType);

        if (!isset($document['area']) || $document['area'] === '') {
            $document['area'] = $expectedArea;
        } elseif ($document['area'] !== $expectedArea) {
            $err = new Problem(
                'document.area',
                'invalid_value',
                sprintf("Template of type '%s' requires document.area to be '%s', got '%s'.", $rawType, $expectedArea, (string) $document['area'])
            );
            $this->audit->record($opName, 'template', 'new', 'validation_failed');
            return Responder::validationFailed([$err]);
        }

        $lockName = $this->buildLockName('template:create:' . ($cleanSlug ?? sanitize_title($cleanTitle)));
        if (!$this->acquireLock($lockName)) {
            $this->audit->record($opName, 'template', 'new', 'object_locked');
            return Responder::error('object_locked', 'Another write operation is currently in progress for this template.', 409);
        }

        try {
            $vocab = $this->adapter->buildSiteVocabulary($this->metaRepo);
            $schema = $this->adapter->getSchema();
            $expander = new Expander();
            $expRes = $expander->expand($document, $schema, $vocab, false);

            $prefixProblem = static fn(Problem $p): Problem => new Problem(
                $p->path() !== '' ? 'document.' . $p->path() : 'document',
                $p->code(),
                $p->message(),
                $p->hint(),
                $p->severity()
            );

            if ($expRes->hasErrors()) {
                $prefixedErrors = array_map($prefixProblem, $expRes->errors());
                $prefixedWarnings = array_map($prefixProblem, $expRes->warnings());
                $this->audit->record($opName, 'template', 'new', 'validation_failed', null, [
                    'error_count' => count($prefixedErrors),
                ]);
                return Responder::validationFailed($prefixedErrors, $prefixedWarnings);
            }

            /** @var array<int, array<string, mixed>> $nativeElements */
            $nativeElements = $expRes->data();
            $sanitizedNative = $this->sanitizeNativeElements($nativeElements);

            $postId = 0;
            $snapId = 0;
            try {
                $postId = $this->adapter->createTemplate($cleanTitle, $cleanSlug, $rawType);
                $emptyRev = Revision::compute([]);
                $snap = $this->snapshots->create(
                    'template',
                    (string) $postId,
                    $expectedArea,
                    $emptyRev,
                    [
                        'builder' => $this->adapter->name(),
                        'native' => [],
                        'title' => $cleanTitle,
                        'type' => $rawType,
                        'status' => 'draft',
                    ],
                    'create'
                );
                $snapId = $snap['id'];

                $this->adapter->putContentArea($postId, $expectedArea, $sanitizedNative);
                $this->adapter->afterWrite($postId);
            } catch (\Throwable) {
                if ($postId > 0) {
                    wp_delete_post($postId, true);
                }
                if ($snapId > 0) {
                    $this->snapshots->deleteById($snapId);
                }
                $this->audit->record($opName, 'template', $postId > 0 ? (string) $postId : 'new', 'write_failed');
                return Responder::error('write_failed', 'Failed to write template to the database.', 500);
            }

            $verifyRender = (bool) get_option('ln_builder_verify_render', true);
            if ($verifyRender && !$this->adapter->tryRender($postId, $expectedArea)) {
                wp_delete_post($postId, true);
                $this->snapshots->deleteById($snapId);
                $this->audit->record($opName, 'template', (string) $postId, 'render_check_failed');
                return Responder::error('render_check_failed', 'Template failed server-side render verification and was rolled back.', 500);
            }

            $storedNative = $this->adapter->contentArea($postId, $expectedArea);
            $finalRevision = Revision::compute($storedNative);

            $compactor = new Compactor();
            $compactRes = $compactor->compact($storedNative, $expectedArea, $schema, $vocab);
            $details = $this->adapter->templateDetails($postId) ?? [
                'id' => $postId,
                'slug' => $cleanSlug ?? sanitize_title($cleanTitle),
                'title' => $cleanTitle,
                'type' => $rawType,
                'status' => 'draft',
                'modified' => gmdate('Y-m-d\TH:i:s\Z'),
                'edit_url' => $this->adapter->editLink($postId),
                'display_conditions' => [],
            ];

            $details['area'] = $expectedArea;
            $details['revision'] = $finalRevision;
            $details['snapshot_id'] = $snap['snapshot_id'];
            $details['document'] = $compactRes->data();
            $details['section_refs'] = array_values(array_map(
                static fn(array $s): string => (string) ($s['ref'] ?? ''),
                $compactRes->data()['sections'] ?? []
            ));

            $isComponent = !empty($body['component']);
            if (isset($body['component'])) {
                update_post_meta($postId, '_ln_builder_component', $isComponent ? 1 : 0);
            }
            $details['component'] = $isComponent;

            $this->audit->record($opName, 'template', (string) $postId, 'ok', $snapId, [
                'revision' => $finalRevision,
                'type' => $rawType,
                'area' => $expectedArea,
            ]);

            $this->recordAttributionMarker($postId, true);

            DesignContextBuilder::invalidateCache();

            $extraMeta = [];
            $warnings = array_map($prefixProblem, $expRes->warnings());
            if ($warnings !== []) {
                $extraMeta['warnings'] = array_map(static fn(Problem $w): array => $w->toArray(), $warnings);
            }

            return Responder::success($details, 201, $extraMeta);
        } finally {
            $this->releaseLock($lockName);
        }
    }

    /**
     * Execute the full write pipeline for `POST /pages` (Contract §5.3).
     * Supports both `{ title, slug?, parent?, document }` and `{ title, slug?, parent?, from_template, fill? }`.
     *
     * @param array<string, mixed> $body
     */
    public function createPage(array $body): \WP_REST_Response
    {
        $opName = 'POST /pages';
        $envelopeErrors = [];

        $rawTitle = $body['title'] ?? null;
        if (!is_string($rawTitle) || trim(sanitize_text_field($rawTitle)) === '') {
            $envelopeErrors[] = new Problem('title', 'missing_required', "Field 'title' is required and must be a non-empty string.");
        }

        $rawSlug = $body['slug'] ?? null;
        if ($rawSlug !== null && !is_string($rawSlug)) {
            $envelopeErrors[] = new Problem('slug', 'invalid_value', "Field 'slug' must be a string when provided.");
        }

        $parentId = null;
        if (array_key_exists('parent', $body) && $body['parent'] !== null) {
            if (!is_int($body['parent']) || $body['parent'] < 0) {
                $envelopeErrors[] = new Problem('parent', 'invalid_value', "Field 'parent' must be a non-negative integer page ID.");
            } elseif ($body['parent'] > 0) {
                $parentDetails = $this->adapter->pageDetails($body['parent']);
                if ($parentDetails === null) {
                    $envelopeErrors[] = new Problem('parent', 'invalid_value', sprintf('Parent page %d does not exist.', $body['parent']));
                } else {
                    $parentId = $body['parent'];
                }
            }
        }

        $hasDocument = array_key_exists('document', $body);
        $hasFromTemplate = array_key_exists('from_template', $body);

        if ($hasDocument && $hasFromTemplate) {
            $envelopeErrors[] = new Problem('from_template', 'invalid_value', "Provide either 'document' or 'from_template', not both.");
        } elseif (!$hasDocument && !$hasFromTemplate) {
            $envelopeErrors[] = new Problem('document', 'missing_required', "Either 'document' or 'from_template' is required.");
        }

        if ($envelopeErrors !== []) {
            $this->audit->record($opName, 'page', 'new', 'validation_failed');
            return Responder::validationFailed($envelopeErrors);
        }

        /** @var string $rawTitle */
        $cleanTitle = trim(sanitize_text_field($rawTitle));
        $cleanSlug = is_string($rawSlug) && trim($rawSlug) !== '' ? sanitize_title($rawSlug) : null;
        $lockName = $this->buildLockName('page:create:' . ($cleanSlug ?? sanitize_title($cleanTitle)));

        if (!$this->acquireLock($lockName)) {
            $this->audit->record($opName, 'page', 'new', 'object_locked');
            return Responder::error('object_locked', 'Another write operation is currently in progress for this page.', 409);
        }

        try {
            $vocab = $this->adapter->buildSiteVocabulary($this->metaRepo);
            $schema = $this->adapter->getSchema();
            $warnings = [];
            $sanitizedNative = [];

            if ($hasFromTemplate) {
                $fromRef = $body['from_template'];
                if (!is_int($fromRef) && (!is_string($fromRef) || trim($fromRef) === '')) {
                    $err = new Problem('from_template', 'invalid_value', "Field 'from_template' must be a template ID or slug.");
                    $this->audit->record($opName, 'page', 'new', 'validation_failed');
                    return Responder::validationFailed([$err]);
                }

                $tplId = $this->adapter->findTemplateId($fromRef);
                if ($tplId === null) {
                    $this->audit->record($opName, 'page', 'new', 'not_found');
                    return Responder::error('not_found', sprintf("Source template '%s' not found.", (string) $fromRef), 404);
                }

                $tplType = $this->adapter->templateType($tplId) ?? 'content';
                $tplArea = $this->adapter->areaForTemplateType($tplType);
                if ($tplArea !== 'content') {
                    $err = new Problem(
                        'from_template',
                        'invalid_value',
                        sprintf("Template '%s' uses area '%s'; only 'content' templates can be copied into a page.", (string) $fromRef, $tplArea)
                    );
                    $this->audit->record($opName, 'page', 'new', 'validation_failed');
                    return Responder::validationFailed([$err]);
                }

                $baseNative = $this->adapter->contentArea($tplId, 'content');

                if (isset($body['fill']) && $body['fill'] !== null) {
                    if (!is_array($body['fill']) || !array_is_list($body['fill'])) {
                        $err = new Problem('fill', 'invalid_value', "Field 'fill' must be a list of section patch operations.");
                        $this->audit->record($opName, 'page', 'new', 'validation_failed');
                        return Responder::validationFailed([$err]);
                    }

                    if ($body['fill'] !== []) {
                        $patcher = new Patcher();
                        $patchRes = $patcher->apply($baseNative, $body['fill'], $schema, $vocab, 'content');
                        $prefixFill = static fn(Problem $p): Problem => new Problem(
                            str_starts_with($p->path(), 'ops') ? 'fill' . substr($p->path(), 3) : 'fill.' . $p->path(),
                            $p->code(),
                            $p->message(),
                            $p->hint(),
                            $p->severity()
                        );
                        if ($patchRes->hasErrors()) {
                            $this->audit->record($opName, 'page', 'new', 'validation_failed');
                            return Responder::validationFailed(
                                array_map($prefixFill, $patchRes->errors()),
                                array_map($prefixFill, $patchRes->warnings())
                            );
                        }
                        $warnings = array_map($prefixFill, $patchRes->warnings());
                        /** @var array<int, array<string, mixed>> $patchedNative */
                        $patchedNative = $patchRes->data();
                        $baseNative = $this->sanitizeNativeElements($patchedNative, $patcher->modifiedElementIds());
                    }
                }

                // Remap all native element IDs so the copied page has fresh independent IDs
                $sanitizedNative = $this->remapNativeElementIds($baseNative);
            } else {
                $document = $body['document'] ?? null;
                if (!is_array($document)) {
                    $err = new Problem('document', 'invalid_value', "Field 'document' must be a JSON object.");
                    $this->audit->record($opName, 'page', 'new', 'validation_failed');
                    return Responder::validationFailed([$err]);
                }

                if (!isset($document['area']) || $document['area'] === '') {
                    $document['area'] = 'content';
                } elseif ($document['area'] !== 'content') {
                    $err = new Problem('document.area', 'invalid_value', "Pages only support document.area = 'content'.");
                    $this->audit->record($opName, 'page', 'new', 'validation_failed');
                    return Responder::validationFailed([$err]);
                }

                $expander = new Expander();
                $expRes = $expander->expand($document, $schema, $vocab, false);

                $prefixProblem = static fn(Problem $p): Problem => new Problem(
                    $p->path() !== '' ? 'document.' . $p->path() : 'document',
                    $p->code(),
                    $p->message(),
                    $p->hint(),
                    $p->severity()
                );

                if ($expRes->hasErrors()) {
                    $this->audit->record($opName, 'page', 'new', 'validation_failed');
                    return Responder::validationFailed(
                        array_map($prefixProblem, $expRes->errors()),
                        array_map($prefixProblem, $expRes->warnings())
                    );
                }

                $warnings = array_map($prefixProblem, $expRes->warnings());
                /** @var array<int, array<string, mixed>> $nativeElements */
                $nativeElements = $expRes->data();
                $sanitizedNative = $this->sanitizeNativeElements($nativeElements);
            }

            $postId = 0;
            $snapId = 0;
            try {
                $postId = $this->adapter->createPage($cleanTitle, $cleanSlug, $parentId);
                $emptyRev = Revision::compute([]);
                $snap = $this->snapshots->create(
                    'page',
                    (string) $postId,
                    'content',
                    $emptyRev,
                    [
                        'builder' => $this->adapter->name(),
                        'native' => [],
                        'title' => $cleanTitle,
                        'status' => 'draft',
                    ],
                    'create'
                );
                $snapId = $snap['id'];

                $this->adapter->putContentArea($postId, 'content', $sanitizedNative);
                $this->adapter->afterWrite($postId);
            } catch (\Throwable) {
                if ($postId > 0) {
                    wp_delete_post($postId, true);
                }
                if ($snapId > 0) {
                    $this->snapshots->deleteById($snapId);
                }
                $this->audit->record($opName, 'page', $postId > 0 ? (string) $postId : 'new', 'write_failed');
                return Responder::error('write_failed', 'Failed to write page to the database.', 500);
            }

            $verifyRender = (bool) get_option('ln_builder_verify_render', true);
            if ($verifyRender && !$this->adapter->tryRender($postId, 'content')) {
                wp_delete_post($postId, true);
                $this->snapshots->deleteById($snapId);
                $this->audit->record($opName, 'page', (string) $postId, 'render_check_failed');
                return Responder::error('render_check_failed', 'Page failed server-side render verification and was rolled back.', 500);
            }

            $storedNative = $this->adapter->contentArea($postId, 'content');
            $finalRevision = Revision::compute($storedNative);
            $compactor = new Compactor();
            $compactRes = $compactor->compact($storedNative, 'content', $schema, $vocab);

            $details = $this->adapter->pageDetails($postId) ?? [
                'id' => $postId,
                'slug' => $cleanSlug ?? sanitize_title($cleanTitle),
                'title' => $cleanTitle,
                'parent' => $parentId ?? 0,
                'status' => 'draft',
                'editor_mode' => 'bricks',
                'modified' => gmdate('Y-m-d\TH:i:s\Z'),
                'url' => (string) get_permalink($postId),
                'edit_url' => $this->adapter->editLink($postId),
            ];

            $details['area'] = 'content';
            $details['revision'] = $finalRevision;
            $details['snapshot_id'] = $snap['snapshot_id'];
            $details['document'] = $compactRes->data();
            $details['section_refs'] = array_values(array_map(
                static fn(array $s): string => (string) ($s['ref'] ?? ''),
                $compactRes->data()['sections'] ?? []
            ));

            $this->audit->record($opName, 'page', (string) $postId, 'ok', $snapId, [
                'revision' => $finalRevision,
                'from_template' => $hasFromTemplate ? (string) $body['from_template'] : null,
            ]);

            $this->recordAttributionMarker($postId, true);

            $extraMeta = [];
            if ($warnings !== []) {
                $extraMeta['warnings'] = array_map(static fn(Problem $w): array => $w->toArray(), $warnings);
            }

            return Responder::success($details, 201, $extraMeta);
        } finally {
            $this->releaseLock($lockName);
        }
    }

    /**
     * Apply section patch operations (`PATCH /templates/{id}` or `PATCH /pages/{id}`).
     *
     * @param 'template'|'page' $objectType
     * @param array<string, mixed> $body
     */
    public function patchObject(string $objectType, int $postId, array $body): \WP_REST_Response
    {
        $opName = $objectType === 'template' ? 'PATCH /templates/{id}' : 'PATCH /pages/{id}';

        $envelopeErrors = [];
        $baseRevision = $body['base_revision'] ?? null;
        if (!is_string($baseRevision) || trim($baseRevision) === '') {
            $envelopeErrors[] = new Problem('base_revision', 'missing_required', "Field 'base_revision' is required.");
        }

        $ops = $body['ops'] ?? null;
        if (!is_array($ops) || $ops === [] || !array_is_list($ops)) {
            $envelopeErrors[] = new Problem('ops', 'missing_required', "Field 'ops' must be a non-empty list of patch operations.");
        }

        if ($envelopeErrors !== []) {
            $this->audit->record($opName, $objectType, (string) $postId, 'validation_failed');
            return Responder::validationFailed($envelopeErrors);
        }

        $lockName = $this->buildLockName('post:' . $postId);
        if (!$this->acquireLock($lockName)) {
            $this->audit->record($opName, $objectType, (string) $postId, 'object_locked');
            return Responder::error('object_locked', 'Another write operation is currently in progress for this object.', 409);
        }

        try {
            $details = $objectType === 'template'
                ? $this->adapter->templateDetails($postId)
                : $this->adapter->pageDetails($postId);

            if ($details === null) {
                return Responder::error('not_found', sprintf('%s %d not found.', ucfirst($objectType), $postId), 404);
            }

            if ($this->isPostLockedByEditor($postId)) {
                $this->audit->record($opName, $objectType, (string) $postId, 'object_locked');
                return Responder::error('object_locked', 'Object is currently open for editing by a user in WordPress.', 409);
            }

            $area = $objectType === 'template'
                ? $this->adapter->areaForTemplateType((string) ($details['type'] ?? 'content'))
                : 'content';

            $storedNative = $this->adapter->contentArea($postId, $area);

            if ($objectType === 'page' && ($details['editor_mode'] ?? 'bricks') !== 'bricks' && $storedNative === []) {
                $post = get_post($postId);
                if ($post instanceof \WP_Post && trim($post->post_content) !== '' && empty($body['convert_to_bricks'])) {
                    $this->audit->record($opName, $objectType, (string) $postId, 'editor_mode_mismatch');
                    return Responder::error(
                        'editor_mode_mismatch',
                        "Page currently uses the block/classic editor and has non-empty post_content. Pass 'convert_to_bricks': true to overwrite.",
                        409
                    );
                }
            }

            $currentRevision = Revision::compute($storedNative);
            if (!hash_equals($currentRevision, (string) $baseRevision)) {
                $this->audit->record($opName, $objectType, (string) $postId, 'revision_conflict', null, [
                    'base_revision' => (string) $baseRevision,
                    'current_revision' => $currentRevision,
                ]);
                return Responder::error(
                    'revision_conflict',
                    sprintf("Revision conflict: expected '%s', current stored revision is '%s'.", (string) $baseRevision, $currentRevision),
                    409,
                    [],
                    ['current_revision' => $currentRevision]
                );
            }

            $vocab = $this->adapter->buildSiteVocabulary($this->metaRepo);
            $schema = $this->adapter->getSchema();
            $patcher = new Patcher();
            /** @var array<int, mixed> $ops */
            $patchRes = $patcher->apply($storedNative, $ops, $schema, $vocab, $area);

            if ($patchRes->hasErrors()) {
                $this->audit->record($opName, $objectType, (string) $postId, 'validation_failed', null, [
                    'error_count' => count($patchRes->errors()),
                ]);
                return Responder::validationFailed($patchRes->errors(), $patchRes->warnings());
            }

            /** @var array<int, array<string, mixed>> $nextNative */
            $nextNative = $patchRes->data();
            // Only sanitize elements created/modified by insert or replace; untouched sections remain byte-identical
            $sanitizedNext = $this->sanitizeNativeElements($nextNative, $patcher->modifiedElementIds());

            $snap = $this->snapshots->create(
                $objectType,
                (string) $postId,
                $area,
                $currentRevision,
                [
                    'builder' => $this->adapter->name(),
                    'native' => $storedNative,
                    'title' => (string) ($details['title'] ?? ''),
                    'status' => (string) ($details['status'] ?? 'draft'),
                ],
                'patch'
            );

            try {
                $this->adapter->putContentArea($postId, $area, $sanitizedNext);
                $this->adapter->afterWrite($postId);
            } catch (\Throwable) {
                $this->rollbackContentArea($postId, $area, $storedNative);
                $this->audit->record($opName, $objectType, (string) $postId, 'write_failed', $snap['id']);
                return Responder::error('write_failed', 'Failed to write patched content; previous snapshot restored.', 500);
            }

            $verifyRender = (bool) get_option('ln_builder_verify_render', true);
            if ($verifyRender && !$this->adapter->tryRender($postId, $area)) {
                $this->rollbackContentArea($postId, $area, $storedNative);
                $this->audit->record($opName, $objectType, (string) $postId, 'render_check_failed', $snap['id']);
                return Responder::error('render_check_failed', 'Patched content failed server-side render verification; previous snapshot restored.', 500);
            }

            $finalNative = $this->adapter->contentArea($postId, $area);
            $finalRevision = Revision::compute($finalNative);
            $compactor = new Compactor();
            $compactRes = $compactor->compact($finalNative, $area, $schema, $vocab);

            $updatedDetails = ($objectType === 'template'
                ? $this->adapter->templateDetails($postId)
                : $this->adapter->pageDetails($postId)) ?? $details;

            $updatedDetails['area'] = $area;
            $updatedDetails['revision'] = $finalRevision;
            $updatedDetails['snapshot_id'] = $snap['snapshot_id'];
            $updatedDetails['document'] = $compactRes->data();
            $updatedDetails['section_refs'] = array_values(array_map(
                static fn(array $s): string => (string) ($s['ref'] ?? ''),
                $compactRes->data()['sections'] ?? []
            ));

            $this->audit->record($opName, $objectType, (string) $postId, 'ok', $snap['id'], [
                'base_revision' => $currentRevision,
                'revision' => $finalRevision,
                'op_count' => count($ops),
            ]);

            $this->recordAttributionMarker($postId, false);

            if ($objectType === 'template') {
                if (isset($body) && is_array($body) && array_key_exists('component', $body)) {
                    $isComp = !empty($body['component']);
                    update_post_meta($postId, '_ln_builder_component', $isComp ? 1 : 0);
                    $updatedDetails['component'] = $isComp;
                }
                DesignContextBuilder::invalidateCache();
            }

            $warnings = array_merge($patchRes->warnings(), $compactRes->warnings());
            if (($updatedDetails['status'] ?? '') === 'publish') {
                $warnings[] = new Problem(
                    '',
                    'published_object_modified',
                    'Modified a live published object; use snapshot_id to restore if needed.',
                    null,
                    Problem::SEVERITY_WARNING
                );
            }

            $extraMeta = [];
            if ($warnings !== []) {
                $extraMeta['warnings'] = array_map(static fn(Problem $w): array => $w->toArray(), $warnings);
            }

            return Responder::success($updatedDetails, 200, $extraMeta);
        } finally {
            $this->releaseLock($lockName);
        }
    }

    /**
     * Full document replacement (`PUT /templates/{id}` or `PUT /pages/{id}`).
     *
     * @param 'template'|'page' $objectType
     * @param array<string, mixed> $body
     */
    public function putObject(string $objectType, int $postId, array $body): \WP_REST_Response
    {
        $opName = $objectType === 'template' ? 'PUT /templates/{id}' : 'PUT /pages/{id}';

        $envelopeErrors = [];
        $baseRevision = $body['base_revision'] ?? null;
        if (!is_string($baseRevision) || trim($baseRevision) === '') {
            $envelopeErrors[] = new Problem('base_revision', 'missing_required', "Field 'base_revision' is required.");
        }

        $document = $body['document'] ?? null;
        if (!is_array($document)) {
            $envelopeErrors[] = new Problem('document', 'missing_required', "Field 'document' is required and must be a JSON object.");
        }

        if ($envelopeErrors !== []) {
            $this->audit->record($opName, $objectType, (string) $postId, 'validation_failed');
            return Responder::validationFailed($envelopeErrors);
        }

        /** @var array<string, mixed> $document */
        $lockName = $this->buildLockName('post:' . $postId);
        if (!$this->acquireLock($lockName)) {
            $this->audit->record($opName, $objectType, (string) $postId, 'object_locked');
            return Responder::error('object_locked', 'Another write operation is currently in progress for this object.', 409);
        }

        try {
            $details = $objectType === 'template'
                ? $this->adapter->templateDetails($postId)
                : $this->adapter->pageDetails($postId);

            if ($details === null) {
                return Responder::error('not_found', sprintf('%s %d not found.', ucfirst($objectType), $postId), 404);
            }

            if ($this->isPostLockedByEditor($postId)) {
                $this->audit->record($opName, $objectType, (string) $postId, 'object_locked');
                return Responder::error('object_locked', 'Object is currently open for editing by a user in WordPress.', 409);
            }

            $expectedArea = $objectType === 'template'
                ? $this->adapter->areaForTemplateType((string) ($details['type'] ?? 'content'))
                : 'content';

            if (!isset($document['area']) || $document['area'] === '') {
                $document['area'] = $expectedArea;
            } elseif ($document['area'] !== $expectedArea) {
                $err = new Problem(
                    'document.area',
                    'invalid_value',
                    sprintf("Object requires document.area to be '%s', got '%s'.", $expectedArea, (string) $document['area'])
                );
                $this->audit->record($opName, $objectType, (string) $postId, 'validation_failed');
                return Responder::validationFailed([$err]);
            }

            $storedNative = $this->adapter->contentArea($postId, $expectedArea);

            if ($objectType === 'page' && ($details['editor_mode'] ?? 'bricks') !== 'bricks' && $storedNative === []) {
                $post = get_post($postId);
                if ($post instanceof \WP_Post && trim($post->post_content) !== '' && empty($body['convert_to_bricks'])) {
                    $this->audit->record($opName, $objectType, (string) $postId, 'editor_mode_mismatch');
                    return Responder::error(
                        'editor_mode_mismatch',
                        "Page currently uses the block/classic editor and has non-empty post_content. Pass 'convert_to_bricks': true to overwrite.",
                        409
                    );
                }
            }

            $currentRevision = Revision::compute($storedNative);
            if (!hash_equals($currentRevision, (string) $baseRevision)) {
                $this->audit->record($opName, $objectType, (string) $postId, 'revision_conflict', null, [
                    'base_revision' => (string) $baseRevision,
                    'current_revision' => $currentRevision,
                ]);
                return Responder::error(
                    'revision_conflict',
                    sprintf("Revision conflict: expected '%s', current stored revision is '%s'.", (string) $baseRevision, $currentRevision),
                    409,
                    [],
                    ['current_revision' => $currentRevision]
                );
            }

            $vocab = $this->adapter->buildSiteVocabulary($this->metaRepo);
            $schema = $this->adapter->getSchema();
            $storedPartition = Compactor::partitionByRootSections($storedNative);

            $compactor = new Compactor();
            $existingCompact = $compactor->compact($storedNative, $expectedArea, $schema, $vocab)->data();
            $existingCompactedByRef = [];
            foreach (($existingCompact['sections'] ?? []) as $cSec) {
                if (is_array($cSec) && isset($cSec['ref']) && is_string($cSec['ref'])) {
                    $existingCompactedByRef[$cSec['ref']] = $cSec;
                }
            }

            $expander = new Expander();
            $expRes = $expander->expand(
                $document,
                $schema,
                $vocab,
                true,
                array_keys($storedPartition['byId']),
                $storedPartition['roots']
            );

            $prefixProblem = static fn(Problem $p): Problem => new Problem(
                $p->path() !== '' ? 'document.' . $p->path() : 'document',
                $p->code(),
                $p->message(),
                $p->hint(),
                $p->severity()
            );

            if ($expRes->hasErrors()) {
                $this->audit->record($opName, $objectType, (string) $postId, 'validation_failed', null, [
                    'error_count' => count($expRes->errors()),
                ]);
                return Responder::validationFailed(
                    array_map($prefixProblem, $expRes->errors()),
                    array_map($prefixProblem, $expRes->warnings())
                );
            }

            // Preserve byte-for-byte any section whose incoming lean structure is identical to the stored section
            /** @var array<int, array<string, mixed>> $expandedNative */
            $expandedNative = $expRes->data();
            $expandedPartition = Compactor::partitionByRootSections($expandedNative);
            $incomingSections = is_array($document['sections'] ?? null) ? $document['sections'] : [];

            $finalNative = [];
            $modifiedIds = [];
            foreach ($expandedPartition['roots'] as $sIdx => $rootId) {
                $inSec = $incomingSections[$sIdx] ?? null;
                $refKey = 's_' . $rootId;
                if (
                    is_array($inSec)
                    && isset($inSec['ref'])
                    && $inSec['ref'] === $refKey
                    && isset($existingCompactedByRef[$refKey], $storedPartition['subtrees'][$rootId])
                    && $inSec === $existingCompactedByRef[$refKey]
                ) {
                    foreach ($storedPartition['subtrees'][$rootId] as $unchangedEl) {
                        $finalNative[] = $unchangedEl;
                    }
                } else {
                    foreach (($expandedPartition['subtrees'][$rootId] ?? []) as $modEl) {
                        $elId = (string) ($modEl['id'] ?? '');
                        if ($elId !== '') {
                            $modifiedIds[$elId] = true;
                        }
                        $finalNative[] = $modEl;
                    }
                }
            }

            $sanitizedNext = $this->sanitizeNativeElements($finalNative, $modifiedIds);

            $snap = $this->snapshots->create(
                $objectType,
                (string) $postId,
                $expectedArea,
                $currentRevision,
                [
                    'builder' => $this->adapter->name(),
                    'native' => $storedNative,
                    'title' => (string) ($details['title'] ?? ''),
                    'status' => (string) ($details['status'] ?? 'draft'),
                ],
                'replace'
            );

            try {
                $this->adapter->putContentArea($postId, $expectedArea, $sanitizedNext);
                $this->adapter->afterWrite($postId);
            } catch (\Throwable) {
                $this->rollbackContentArea($postId, $expectedArea, $storedNative);
                $this->audit->record($opName, $objectType, (string) $postId, 'write_failed', $snap['id']);
                return Responder::error('write_failed', 'Failed to write replaced content; previous snapshot restored.', 500);
            }

            $verifyRender = (bool) get_option('ln_builder_verify_render', true);
            if ($verifyRender && !$this->adapter->tryRender($postId, $expectedArea)) {
                $this->rollbackContentArea($postId, $expectedArea, $storedNative);
                $this->audit->record($opName, $objectType, (string) $postId, 'render_check_failed', $snap['id']);
                return Responder::error('render_check_failed', 'Replaced content failed server-side render verification; previous snapshot restored.', 500);
            }

            $storedAfter = $this->adapter->contentArea($postId, $expectedArea);
            $finalRevision = Revision::compute($storedAfter);
            $compactRes = $compactor->compact($storedAfter, $expectedArea, $schema, $vocab);

            $updatedDetails = ($objectType === 'template'
                ? $this->adapter->templateDetails($postId)
                : $this->adapter->pageDetails($postId)) ?? $details;

            $updatedDetails['area'] = $expectedArea;
            $updatedDetails['revision'] = $finalRevision;
            $updatedDetails['snapshot_id'] = $snap['snapshot_id'];
            $updatedDetails['document'] = $compactRes->data();
            $updatedDetails['section_refs'] = array_values(array_map(
                static fn(array $s): string => (string) ($s['ref'] ?? ''),
                $compactRes->data()['sections'] ?? []
            ));

            $this->audit->record($opName, $objectType, (string) $postId, 'ok', $snap['id'], [
                'base_revision' => $currentRevision,
                'revision' => $finalRevision,
            ]);

            $this->recordAttributionMarker($postId, false);

            if ($objectType === 'template') {
                if (isset($body) && is_array($body) && array_key_exists('component', $body)) {
                    $isComp = !empty($body['component']);
                    update_post_meta($postId, '_ln_builder_component', $isComp ? 1 : 0);
                    $updatedDetails['component'] = $isComp;
                }
                DesignContextBuilder::invalidateCache();
            }

            $warnings = array_merge(
                array_map($prefixProblem, $expRes->warnings()),
                $compactRes->warnings()
            );
            if (($updatedDetails['status'] ?? '') === 'publish') {
                $warnings[] = new Problem(
                    '',
                    'published_object_modified',
                    'Modified a live published object; use snapshot_id to restore if needed.',
                    null,
                    Problem::SEVERITY_WARNING
                );
            }

            $extraMeta = [];
            if ($warnings !== []) {
                $extraMeta['warnings'] = array_map(static fn(Problem $w): array => $w->toArray(), $warnings);
            }

            return Responder::success($updatedDetails, 200, $extraMeta);
        } finally {
            $this->releaseLock($lockName);
        }
    }

    /**
     * Publish a template or page (`POST /templates/{id}/publish` or `POST /pages/{id}/publish`).
     *
     * @param 'template'|'page' $objectType
     */
    public function publishObject(string $objectType, int $postId): \WP_REST_Response
    {
        $opName = $objectType === 'template' ? 'POST /templates/{id}/publish' : 'POST /pages/{id}/publish';
        $lockName = $this->buildLockName('post:' . $postId);

        if (!$this->acquireLock($lockName)) {
            $this->audit->record($opName, $objectType, (string) $postId, 'object_locked');
            return Responder::error('object_locked', 'Another write operation is currently in progress for this object.', 409);
        }

        try {
            $details = $objectType === 'template'
                ? $this->adapter->templateDetails($postId)
                : $this->adapter->pageDetails($postId);

            if ($details === null) {
                return Responder::error('not_found', sprintf('%s %d not found.', ucfirst($objectType), $postId), 404);
            }

            $area = $objectType === 'template'
                ? $this->adapter->areaForTemplateType((string) ($details['type'] ?? 'content'))
                : 'content';

            $storedNative = $this->adapter->contentArea($postId, $area);
            $currentRevision = Revision::compute($storedNative);

            $snap = $this->snapshots->create(
                $objectType,
                (string) $postId,
                $area,
                $currentRevision,
                [
                    'builder' => $this->adapter->name(),
                    'native' => $storedNative,
                    'title' => (string) ($details['title'] ?? ''),
                    'status' => (string) ($details['status'] ?? 'draft'),
                ],
                'publish'
            );

            try {
                $this->adapter->publish($postId);
                $this->adapter->afterWrite($postId);
            } catch (\Throwable) {
                $this->audit->record($opName, $objectType, (string) $postId, 'write_failed', $snap['id']);
                return Responder::error('write_failed', 'Failed to publish object.', 500);
            }

            $updatedDetails = ($objectType === 'template'
                ? $this->adapter->templateDetails($postId)
                : $this->adapter->pageDetails($postId)) ?? $details;

            $updatedDetails['area'] = $area;
            $updatedDetails['revision'] = $currentRevision;
            $updatedDetails['snapshot_id'] = $snap['snapshot_id'];

            $this->audit->record($opName, $objectType, (string) $postId, 'ok', $snap['id'], [
                'revision' => $currentRevision,
            ]);

            $this->recordAttributionMarker($postId, false);

            if ($objectType === 'template') {
                if (isset($body) && is_array($body) && array_key_exists('component', $body)) {
                    $isComp = !empty($body['component']);
                    update_post_meta($postId, '_ln_builder_component', $isComp ? 1 : 0);
                    $updatedDetails['component'] = $isComp;
                }
                DesignContextBuilder::invalidateCache();
            }

            $extraMeta = [];
            if ($objectType === 'template' && !empty($updatedDetails['display_conditions'])) {
                $extraMeta['warnings'] = [
                    [
                        'path' => 'display_conditions',
                        'code' => 'active_display_conditions',
                        'message' => 'Published template has active display conditions and now affects matching pages on the live site.',
                    ],
                ];
            }

            return Responder::success($updatedDetails, 200, $extraMeta);
        } finally {
            $this->releaseLock($lockName);
        }
    }

    /**
     * Restore a snapshot by ID (`POST /snapshots/{id}/restore`).
     * Takes a `restore` snapshot of the current state before restoring.
     *
     * @param int|string $snapshotIdOrSnp
     */
    public function restoreSnapshot($snapshotIdOrSnp): \WP_REST_Response
    {
        $opName = 'POST /snapshots/{id}/restore';
        $snapshot = $this->snapshots->findById($snapshotIdOrSnp);
        if ($snapshot === null) {
            return Responder::error('not_found', 'Snapshot not found.', 404);
        }

        $objectType = (string) $snapshot['object_type'];
        $objectKey = (string) $snapshot['object_key'];
        $requiredScope = $objectType === 'design' ? 'design:write' : 'content:write';

        $ctx = RequestContext::current();
        if ($ctx === null || !$ctx->hasScope($requiredScope)) {
            return Responder::error(
                'scope_denied',
                sprintf("Restoring a '%s' snapshot requires the '%s' scope.", $objectType, $requiredScope),
                403
            );
        }

        $payload = is_array($snapshot['payload'] ?? null) ? $snapshot['payload'] : [];
        $snapBuilder = isset($payload['builder']) && is_string($payload['builder']) ? $payload['builder'] : $this->adapter->name();
        if ($snapBuilder !== $this->adapter->name()) {
            return Responder::error(
                'validation_failed',
                sprintf("Snapshot was created for builder '%s', but active builder is '%s'.", $snapBuilder, $this->adapter->name()),
                422
            );
        }

        if ($objectType === 'design') {
            return $this->restoreDesignSnapshot($opName, $snapshot, $objectKey, $payload);
        }

        if (!in_array($objectType, ['template', 'page'], true) || !ctype_digit($objectKey)) {
            return Responder::error('validation_failed', 'Unsupported snapshot object type.', 422);
        }

        $postId = (int) $objectKey;
        $lockName = $this->buildLockName('post:' . $postId);
        if (!$this->acquireLock($lockName)) {
            $this->audit->record($opName, $objectType, $objectKey, 'object_locked');
            return Responder::error('object_locked', 'Another write operation is currently in progress for this object.', 409);
        }

        try {
            $details = $objectType === 'template'
                ? $this->adapter->templateDetails($postId)
                : $this->adapter->pageDetails($postId);

            if ($details === null) {
                return Responder::error('not_found', sprintf('Target %s %d no longer exists or is trashed.', $objectType, $postId), 404);
            }

            $expectedArea = $objectType === 'template'
                ? $this->adapter->areaForTemplateType((string) ($details['type'] ?? 'content'))
                : 'content';

            $snapArea = $snapshot['area'] !== null ? (string) $snapshot['area'] : $expectedArea;
            if ($snapArea !== $expectedArea) {
                return Responder::error(
                    'validation_failed',
                    sprintf("Snapshot area '%s' does not match target object's current area '%s'.", $snapArea, $expectedArea),
                    422
                );
            }

            $currentNative = $this->adapter->contentArea($postId, $expectedArea);
            $currentRevision = Revision::compute($currentNative);

            // Extract target native elements (preferring lossless serialized representation)
            $targetNative = [];
            if (isset($payload['native_serialized']) && is_string($payload['native_serialized']) && $payload['native_serialized'] !== '') {
                $unserialized = @unserialize($payload['native_serialized'], ['allowed_classes' => false]);
                if (is_array($unserialized)) {
                    $targetNative = $unserialized;
                }
            } elseif (isset($payload['native']) && is_array($payload['native'])) {
                $targetNative = $payload['native'];
            }

            // Record pre-restore snapshot first
            $preRestoreSnap = $this->snapshots->create(
                $objectType,
                $objectKey,
                $expectedArea,
                $currentRevision,
                [
                    'builder' => $this->adapter->name(),
                    'native' => $currentNative,
                    'title' => (string) ($details['title'] ?? ''),
                    'status' => (string) ($details['status'] ?? 'draft'),
                ],
                'restore'
            );

            try {
                $this->adapter->putContentArea($postId, $expectedArea, $targetNative);
                $this->adapter->afterWrite($postId);
            } catch (\Throwable) {
                $this->rollbackContentArea($postId, $expectedArea, $currentNative);
                $this->audit->record($opName, $objectType, $objectKey, 'write_failed', $preRestoreSnap['id']);
                return Responder::error('write_failed', 'Failed to restore snapshot; previous state preserved.', 500);
            }

            $verifyRender = (bool) get_option('ln_builder_verify_render', true);
            if ($verifyRender && !$this->adapter->tryRender($postId, $expectedArea)) {
                $this->rollbackContentArea($postId, $expectedArea, $currentNative);
                $this->audit->record($opName, $objectType, $objectKey, 'render_check_failed', $preRestoreSnap['id']);
                return Responder::error('render_check_failed', 'Restored snapshot failed render verification; previous state preserved.', 500);
            }

            $storedAfter = $this->adapter->contentArea($postId, $expectedArea);
            $finalRevision = Revision::compute($storedAfter);
            $vocab = $this->adapter->buildSiteVocabulary($this->metaRepo);
            $schema = $this->adapter->getSchema();
            $compactor = new Compactor();
            $compactRes = $compactor->compact($storedAfter, $expectedArea, $schema, $vocab);

            $updatedDetails = ($objectType === 'template'
                ? $this->adapter->templateDetails($postId)
                : $this->adapter->pageDetails($postId)) ?? $details;

            $updatedDetails['object'] = "{$objectType}:{$postId}";
            $updatedDetails['area'] = $expectedArea;
            $updatedDetails['restored_from'] = (string) $snapshot['snapshot_id'];
            $updatedDetails['snapshot_id'] = $preRestoreSnap['snapshot_id'];
            $updatedDetails['revision'] = $finalRevision;
            $updatedDetails['document'] = $compactRes->data();

            $this->audit->record($opName, $objectType, $objectKey, 'ok', $preRestoreSnap['id'], [
                'restored_from' => (string) $snapshot['snapshot_id'],
                'revision' => $finalRevision,
            ]);

            if ($objectType === 'template' || $objectType === 'page') {
                $this->recordAttributionMarker($postId, false);
            }

            if ($objectType === 'template') {
                if (isset($body) && is_array($body) && array_key_exists('component', $body)) {
                    $isComp = !empty($body['component']);
                    update_post_meta($postId, '_ln_builder_component', $isComp ? 1 : 0);
                    $updatedDetails['component'] = $isComp;
                }
                DesignContextBuilder::invalidateCache();
            }

            return Responder::success($updatedDetails, 200);
        } finally {
            $this->releaseLock($lockName);
        }
    }

    /**
     * `PATCH /design-system/colors` (Contract §5.5 / Plugin §8.3).
     *
     * @param array<string, mixed> $body
     */
    public function patchDesignColors(array $body): \WP_REST_Response
    {
        $opName = 'PATCH /design-system/colors';
        $colors = $body['colors'] ?? null;
        if (!is_array($colors) || $colors === [] || !array_is_list($colors)) {
            $err = new Problem('colors', 'missing_required', "Field 'colors' must be a non-empty list of color objects.");
            $this->audit->record($opName, 'design', 'colors', 'validation_failed');
            return Responder::validationFailed([$err]);
        }

        $hasDelete = false;
        foreach ($colors as $c) {
            if (is_array($c) && !empty($c['delete'])) {
                $hasDelete = true;
                break;
            }
        }

        $lockName = $this->buildLockName('design:global');
        if (!$this->acquireLock($lockName)) {
            $this->audit->record($opName, 'design', 'colors', 'object_locked');
            return Responder::error('object_locked', 'Another write operation is currently in progress for the design system.', 409);
        }

        try {
            $currentDesign = $this->adapter->designSystem($this->metaRepo);
            $revErr = $this->checkDesignBaseRevision($opName, 'colors', $body, $currentDesign, 'colors', $hasDelete);
            if ($revErr !== null) {
                return $revErr;
            }

            $vocab = $this->adapter->buildSiteVocabulary($this->metaRepo);
            $state = $this->adapter->designValidationState($this->metaRepo);
            $externalUsages = [];
            foreach ($colors as $c) {
                if (is_array($c) && !empty($c['delete']) && isset($c['name']) && is_string($c['name'])) {
                    $cName = trim($c['name']);
                    $cssVar = isset($state['colors'][$cName]['css_var']) ? (string) $state['colors'][$cName]['css_var'] : '';
                    if ($cssVar !== '') {
                        $externalUsages[$cssVar] = $this->adapter->findDesignUsagesInContent('variable', $cssVar);
                    }
                }
            }
            $validator = new DesignValidator();
            $valRes = $validator->validateColorsBatch($colors, $state, $vocab->tokenDiscipline(), $externalUsages, 'colors');

            if ($valRes->hasErrors()) {
                $this->audit->record($opName, 'design', 'colors', 'validation_failed', null, [
                    'error_count' => count($valRes->errors()),
                ]);
                return Responder::validationFailed($valRes->errors(), $valRes->warnings());
            }

            $rawBefore = $this->adapter->rawDesignSnapshot('colors');
            $currentAreaRev = (string) ($currentDesign['revisions']['colors'] ?? $currentDesign['revision']);
            $snap = $this->snapshots->create(
                'design',
                'colors',
                null,
                $currentAreaRev,
                [
                    'builder' => $this->adapter->name(),
                    'raw_design' => $rawBefore,
                ],
                'design'
            );

            /** @var array<string, mixed> $valData */
            $valData = $valRes->data();
            try {
                $this->adapter->saveMergedColors($valData['colors'], $this->metaRepo);
                $this->adapter->afterDesignWrite('colors');
            } catch (\Throwable) {
                $this->adapter->restoreRawDesignSnapshot($rawBefore);
                $this->audit->record($opName, 'design', 'colors', 'write_failed', $snap['id']);
                return Responder::error('write_failed', 'Failed to update design colors; previous state restored.', 500);
            }

            if (!$this->verifyDesignSmokeTest()) {
                $this->adapter->restoreRawDesignSnapshot($rawBefore);
                $this->audit->record($opName, 'design', 'colors', 'render_check_failed', $snap['id']);
                return Responder::error('render_check_failed', 'Design colors failed smoke-test page render verification and were rolled back.', 500);
            }

            $afterDesign = $this->adapter->designSystem($this->metaRepo);
            $finalAreaRev = (string) ($afterDesign['revisions']['colors'] ?? $afterDesign['revision']);

            $this->audit->record($opName, 'design', 'colors', 'ok', $snap['id'], [
                'base_revision' => $currentAreaRev,
                'revision' => $finalAreaRev,
            ]);

            $warnings = $valRes->warnings();
            $this->appendPublishedContentWarning($warnings);

            return Responder::success([
                'id' => 'design:colors',
                'status' => 'applied',
                'revision' => $finalAreaRev,
                'revisions' => $afterDesign['revisions'],
                'snapshot_id' => $snap['snapshot_id'],
                'colors' => $afterDesign['colors'],
            ], 200, [
                'warnings' => array_map(static fn(Problem $w): array => $w->toArray(), $warnings),
            ]);
        } finally {
            $this->releaseLock($lockName);
        }
    }

    /**
     * `PATCH /design-system/variables` (Contract §5.5 / Plugin §8.3).
     *
     * @param array<string, mixed> $body
     */
    public function patchDesignVariables(array $body): \WP_REST_Response
    {
        $opName = 'PATCH /design-system/variables';
        $variables = $body['variables'] ?? null;
        if (!is_array($variables) || $variables === [] || !array_is_list($variables)) {
            $err = new Problem('variables', 'missing_required', "Field 'variables' must be a non-empty list of variable objects.");
            $this->audit->record($opName, 'design', 'variables', 'validation_failed');
            return Responder::validationFailed([$err]);
        }

        $hasDelete = false;
        foreach ($variables as $v) {
            if (is_array($v) && !empty($v['delete'])) {
                $hasDelete = true;
                break;
            }
        }

        $lockName = $this->buildLockName('design:global');
        if (!$this->acquireLock($lockName)) {
            $this->audit->record($opName, 'design', 'variables', 'object_locked');
            return Responder::error('object_locked', 'Another write operation is currently in progress for the design system.', 409);
        }

        try {
            $currentDesign = $this->adapter->designSystem($this->metaRepo);
            $revErr = $this->checkDesignBaseRevision($opName, 'variables', $body, $currentDesign, 'variables', $hasDelete);
            if ($revErr !== null) {
                return $revErr;
            }

            $vocab = $this->adapter->buildSiteVocabulary($this->metaRepo);
            $state = $this->adapter->designValidationState($this->metaRepo);
            $externalUsages = [];
            foreach ($variables as $v) {
                if (is_array($v) && !empty($v['delete']) && isset($v['name']) && is_string($v['name'])) {
                    $rawName = trim($v['name']);
                    $varName = str_starts_with($rawName, '--') ? $rawName : '--' . $rawName;
                    $externalUsages[$varName] = $this->adapter->findDesignUsagesInContent('variable', $varName);
                }
            }
            $validator = new DesignValidator();
            $valRes = $validator->validateVariablesBatch(
                $variables,
                $state,
                $vocab->tokenDiscipline(),
                $externalUsages,
                'variables'
            );

            if ($valRes->hasErrors()) {
                $this->audit->record($opName, 'design', 'variables', 'validation_failed', null, [
                    'error_count' => count($valRes->errors()),
                ]);
                return Responder::validationFailed($valRes->errors(), $valRes->warnings());
            }

            $rawBefore = $this->adapter->rawDesignSnapshot('variables');
            $currentAreaRev = (string) ($currentDesign['revisions']['variables'] ?? $currentDesign['revision']);
            $snap = $this->snapshots->create(
                'design',
                'variables',
                null,
                $currentAreaRev,
                [
                    'builder' => $this->adapter->name(),
                    'raw_design' => $rawBefore,
                ],
                'design'
            );

            /** @var array<string, mixed> $valData */
            $valData = $valRes->data();
            try {
                $this->adapter->saveMergedVariables($valData['variables'], $this->metaRepo);
                $this->adapter->afterDesignWrite('variables');
            } catch (\Throwable) {
                $this->adapter->restoreRawDesignSnapshot($rawBefore);
                $this->audit->record($opName, 'design', 'variables', 'write_failed', $snap['id']);
                return Responder::error('write_failed', 'Failed to update design variables; previous state restored.', 500);
            }

            if (!$this->verifyDesignSmokeTest()) {
                $this->adapter->restoreRawDesignSnapshot($rawBefore);
                $this->audit->record($opName, 'design', 'variables', 'render_check_failed', $snap['id']);
                return Responder::error('render_check_failed', 'Design variables failed smoke-test page render verification and were rolled back.', 500);
            }

            $afterDesign = $this->adapter->designSystem($this->metaRepo);
            $finalAreaRev = (string) ($afterDesign['revisions']['variables'] ?? $afterDesign['revision']);

            $this->audit->record($opName, 'design', 'variables', 'ok', $snap['id'], [
                'base_revision' => $currentAreaRev,
                'revision' => $finalAreaRev,
            ]);

            $warnings = $valRes->warnings();
            $this->appendPublishedContentWarning($warnings);

            return Responder::success([
                'id' => 'design:variables',
                'status' => 'applied',
                'revision' => $finalAreaRev,
                'revisions' => $afterDesign['revisions'],
                'snapshot_id' => $snap['snapshot_id'],
                'variables' => $afterDesign['variables'],
            ], 200, [
                'warnings' => array_map(static fn(Problem $w): array => $w->toArray(), $warnings),
            ]);
        } finally {
            $this->releaseLock($lockName);
        }
    }

    /**
     * `PATCH /design-system/classes` (Contract §5.5 / Plugin §8.3).
     *
     * @param array<string, mixed> $body
     */
    public function patchDesignClasses(array $body): \WP_REST_Response
    {
        $opName = 'PATCH /design-system/classes';
        $classes = $body['classes'] ?? null;
        if (!is_array($classes) || $classes === [] || !array_is_list($classes)) {
            $err = new Problem('classes', 'missing_required', "Field 'classes' must be a non-empty list of class objects.");
            $this->audit->record($opName, 'design', 'classes', 'validation_failed');
            return Responder::validationFailed([$err]);
        }

        $hasDelete = false;
        foreach ($classes as $c) {
            if (is_array($c) && !empty($c['delete'])) {
                $hasDelete = true;
                break;
            }
        }

        $lockName = $this->buildLockName('design:global');
        if (!$this->acquireLock($lockName)) {
            $this->audit->record($opName, 'design', 'classes', 'object_locked');
            return Responder::error('object_locked', 'Another write operation is currently in progress for the design system.', 409);
        }

        try {
            $currentDesign = $this->adapter->designSystem($this->metaRepo);
            $revErr = $this->checkDesignBaseRevision($opName, 'classes', $body, $currentDesign, 'classes', $hasDelete);
            if ($revErr !== null) {
                return $revErr;
            }

            $vocab = $this->adapter->buildSiteVocabulary($this->metaRepo);
            $state = $this->adapter->designValidationState($this->metaRepo);
            $externalUsages = [];
            foreach ($classes as $c) {
                if (is_array($c) && !empty($c['delete']) && isset($c['name']) && is_string($c['name'])) {
                    $cName = trim($c['name']);
                    $externalUsages[$cName] = $this->adapter->findDesignUsagesInContent('class', $cName);
                }
            }
            $validator = new DesignValidator();
            $valRes = $validator->validateClassesBatch(
                $classes,
                $state,
                $vocab->tokenDiscipline(),
                $externalUsages,
                'classes'
            );

            if ($valRes->hasErrors()) {
                $this->audit->record($opName, 'design', 'classes', 'validation_failed', null, [
                    'error_count' => count($valRes->errors()),
                ]);
                return Responder::validationFailed($valRes->errors(), $valRes->warnings());
            }

            $rawBefore = $this->adapter->rawDesignSnapshot('classes');
            $currentAreaRev = (string) ($currentDesign['revisions']['classes'] ?? $currentDesign['revision']);
            $snap = $this->snapshots->create(
                'design',
                'classes',
                null,
                $currentAreaRev,
                [
                    'builder' => $this->adapter->name(),
                    'raw_design' => $rawBefore,
                ],
                'design'
            );

            /** @var array<string, mixed> $valData */
            $valData = $valRes->data();
            try {
                $this->adapter->saveMergedClasses($valData['classes'], $valData['deleted_classes'], $this->metaRepo);
                $this->adapter->afterDesignWrite('classes');
            } catch (\Throwable) {
                $this->adapter->restoreRawDesignSnapshot($rawBefore);
                $this->audit->record($opName, 'design', 'classes', 'write_failed', $snap['id']);
                return Responder::error('write_failed', 'Failed to update design classes; previous state restored.', 500);
            }

            if (!$this->verifyDesignSmokeTest()) {
                $this->adapter->restoreRawDesignSnapshot($rawBefore);
                $this->audit->record($opName, 'design', 'classes', 'render_check_failed', $snap['id']);
                return Responder::error('render_check_failed', 'Design classes failed smoke-test page render verification and were rolled back.', 500);
            }

            $afterDesign = $this->adapter->designSystem($this->metaRepo);
            $finalAreaRev = (string) ($afterDesign['revisions']['classes'] ?? $afterDesign['revision']);

            $this->audit->record($opName, 'design', 'classes', 'ok', $snap['id'], [
                'base_revision' => $currentAreaRev,
                'revision' => $finalAreaRev,
            ]);

            $warnings = $valRes->warnings();
            $this->appendPublishedContentWarning($warnings);

            return Responder::success([
                'id' => 'design:classes',
                'status' => 'applied',
                'revision' => $finalAreaRev,
                'revisions' => $afterDesign['revisions'],
                'snapshot_id' => $snap['snapshot_id'],
                'classes' => $afterDesign['classes'],
            ], 200, [
                'warnings' => array_map(static fn(Problem $w): array => $w->toArray(), $warnings),
            ]);
        } finally {
            $this->releaseLock($lockName);
        }
    }

    /**
     * `PATCH /design-system/theme-styles/{name}` (Contract §5.5 / Plugin §8.3).
     *
     * @param array<string, mixed> $body
     */
    public function patchDesignThemeStyle(string $name, array $body): \WP_REST_Response
    {
        $opName = 'PATCH /design-system/theme-styles/{name}';
        $lockName = $this->buildLockName('design:global');

        if (!$this->acquireLock($lockName)) {
            $this->audit->record($opName, 'design', 'theme-styles', 'object_locked');
            return Responder::error('object_locked', 'Another write operation is currently in progress for the design system.', 409);
        }

        try {
            $currentDesign = $this->adapter->designSystem($this->metaRepo);
            $revErr = $this->checkDesignBaseRevision($opName, 'theme-styles', $body, $currentDesign, 'theme_styles', false);
            if ($revErr !== null) {
                return $revErr;
            }

            $vocab = $this->adapter->buildSiteVocabulary($this->metaRepo);
            $state = $this->adapter->designValidationState($this->metaRepo);
            $validator = new DesignValidator();
            $valRes = $validator->validateThemeStylePatch($name, $body, $state, $vocab->tokenDiscipline());

            if ($valRes->hasErrors()) {
                $this->audit->record($opName, 'design', 'theme-styles', 'validation_failed', null, [
                    'error_count' => count($valRes->errors()),
                ]);
                return Responder::validationFailed($valRes->errors(), $valRes->warnings());
            }

            $rawBefore = $this->adapter->rawDesignSnapshot('theme-styles');
            $currentAreaRev = (string) ($currentDesign['revisions']['theme_styles'] ?? $currentDesign['revision']);
            $snap = $this->snapshots->create(
                'design',
                'theme-styles',
                null,
                $currentAreaRev,
                [
                    'builder' => $this->adapter->name(),
                    'raw_design' => $rawBefore,
                ],
                'design'
            );

            /** @var array<string, mixed> $valData */
            $valData = $valRes->data();
            try {
                $this->adapter->saveMergedThemeStyle($name, $valData['label'] ?? null, $valData['settings'] ?? []);
                $this->adapter->afterDesignWrite('theme-styles');
            } catch (\Throwable) {
                $this->adapter->restoreRawDesignSnapshot($rawBefore);
                $this->audit->record($opName, 'design', 'theme-styles', 'write_failed', $snap['id']);
                return Responder::error('write_failed', 'Failed to update theme style; previous state restored.', 500);
            }

            if (!$this->verifyDesignSmokeTest()) {
                $this->adapter->restoreRawDesignSnapshot($rawBefore);
                $this->audit->record($opName, 'design', 'theme-styles', 'render_check_failed', $snap['id']);
                return Responder::error('render_check_failed', 'Theme style failed smoke-test page render verification and was rolled back.', 500);
            }

            $afterDesign = $this->adapter->designSystem($this->metaRepo);
            $finalAreaRev = (string) ($afterDesign['revisions']['theme_styles'] ?? $afterDesign['revision']);

            $this->audit->record($opName, 'design', 'theme-styles', 'ok', $snap['id'], [
                'style' => $name,
                'base_revision' => $currentAreaRev,
                'revision' => $finalAreaRev,
            ]);

            $warnings = $valRes->warnings();
            $this->appendPublishedContentWarning($warnings);

            return Responder::success([
                'id' => 'design:theme-styles:' . $name,
                'status' => 'applied',
                'revision' => $finalAreaRev,
                'revisions' => $afterDesign['revisions'],
                'snapshot_id' => $snap['snapshot_id'],
                'theme_styles' => $afterDesign['theme_styles'],
            ], 200, [
                'warnings' => array_map(static fn(Problem $w): array => $w->toArray(), $warnings),
            ]);
        } finally {
            $this->releaseLock($lockName);
        }
    }

    /**
     * `POST /design-system/preset` (Contract §5.5 / Overview §6.6 / Plugin §8.3).
     * Strictly additive by default (never overwrites existing names unless `overwrite: true`, emitting warnings for skipped items).
     *
     * @param array<string, mixed> $body
     */
    public function applyDesignPreset(array $body): \WP_REST_Response
    {
        $opName = 'POST /design-system/preset';
        $presetName = isset($body['preset']) && is_string($body['preset']) ? trim($body['preset']) : '';
        $customDef = isset($body['definition']) && is_array($body['definition']) ? $body['definition'] : null;

        if ($presetName === '' && $customDef === null) {
            $err = new Problem('preset', 'missing_required', "Field 'preset' (e.g. 'starter') or 'definition' is required.");
            $this->audit->record($opName, 'design', 'preset', 'validation_failed');
            return Responder::validationFailed([$err]);
        }

        if ($customDef !== null) {
            $presetDef = $customDef;
            if ($presetName === '') {
                $presetName = 'custom';
            }
        } elseif ($presetName === StarterPreset::NAME) {
            $presetDef = StarterPreset::definition();
        } else {
            $err = new Problem('preset', 'invalid_value', sprintf("Unknown preset '%s'. Supported built-in preset: 'starter', or supply a 'definition' object.", $presetName));
            $this->audit->record($opName, 'design', 'preset', 'validation_failed');
            return Responder::validationFailed([$err]);
        }

        $lockName = $this->buildLockName('design:global');
        if (!$this->acquireLock($lockName)) {
            $this->audit->record($opName, 'design', 'preset', 'object_locked');
            return Responder::error('object_locked', 'Another write operation is currently in progress for the design system.', 409);
        }

        try {
            $currentDesign = $this->adapter->designSystem($this->metaRepo);
            $revErr = $this->checkDesignBaseRevision($opName, 'preset', $body, $currentDesign, 'preset', true);
            if ($revErr !== null) {
                return $revErr;
            }

            $state = $this->adapter->designValidationState($this->metaRepo);
            $overwrite = !empty($body['overwrite']);
            $skipWarnings = [];

            // Filter preset entries so existing colors/variables/classes are preserved unless overwrite=true
            $filteredColors = [];
            foreach (($presetDef['colors'] ?? []) as $cIdx => $c) {
                if (!is_array($c) || !empty($c['delete'])) {
                    continue;
                }
                $cName = isset($c['name']) ? trim((string) $c['name']) : '';
                if (!$overwrite && $cName !== '' && isset($state['colors'][$cName])) {
                    $skipWarnings[] = new Problem(
                        "colors[{$cIdx}]",
                        'preset_entry_skipped',
                        sprintf("Existing color '%s' was preserved (pass 'overwrite': true to replace).", $cName),
                        null,
                        Problem::SEVERITY_WARNING
                    );
                    continue;
                }
                $filteredColors[] = $c;
            }

            $filteredVariables = [];
            foreach (($presetDef['variables'] ?? []) as $vIdx => $v) {
                if (!is_array($v) || !empty($v['delete'])) {
                    continue;
                }
                $rawName = isset($v['name']) ? trim((string) $v['name']) : '';
                $normName = $rawName !== '' && !str_starts_with($rawName, '--') ? '--' . $rawName : $rawName;
                if (!$overwrite && $normName !== '' && isset($state['variables'][$normName])) {
                    $skipWarnings[] = new Problem(
                        "variables[{$vIdx}]",
                        'preset_entry_skipped',
                        sprintf("Existing variable '%s' was preserved (pass 'overwrite': true to replace).", $normName),
                        null,
                        Problem::SEVERITY_WARNING
                    );
                    continue;
                }
                $filteredVariables[] = $v;
            }

            $filteredClasses = [];
            foreach (($presetDef['classes'] ?? []) as $clsIdx => $cls) {
                if (!is_array($cls) || !empty($cls['delete'])) {
                    continue;
                }
                $clsName = isset($cls['name']) ? trim((string) $cls['name']) : '';
                if (!$overwrite && $clsName !== '' && isset($state['classes'][$clsName])) {
                    $skipWarnings[] = new Problem(
                        "classes[{$clsIdx}]",
                        'preset_entry_skipped',
                        sprintf("Existing class '%s' was preserved (pass 'overwrite': true to replace).", $clsName),
                        null,
                        Problem::SEVERITY_WARNING
                    );
                    continue;
                }
                $filteredClasses[] = $cls;
            }

            $filteredPayload = [];
            if ($filteredColors !== []) {
                $filteredPayload['colors'] = $filteredColors;
            }
            if ($filteredVariables !== []) {
                $filteredPayload['variables'] = $filteredVariables;
            }
            if ($filteredClasses !== []) {
                $filteredPayload['classes'] = $filteredClasses;
            }

            $vocab = $this->adapter->buildSiteVocabulary($this->metaRepo);
            $valWarnings = [];
            $valData = $state;

            if ($filteredPayload !== []) {
                $validator = new DesignValidator();
                $valRes = $validator->validateFullDesignPayload($filteredPayload, $state, $vocab->tokenDiscipline(), 'preset');

                if ($valRes->hasErrors()) {
                    $this->audit->record($opName, 'design', 'preset', 'validation_failed', null, [
                        'error_count' => count($valRes->errors()),
                    ]);
                    return Responder::validationFailed($valRes->errors(), array_merge($skipWarnings, $valRes->warnings()));
                }

                $valWarnings = $valRes->warnings();
                /** @var array<string, mixed> $valData */
                $valData = $valRes->data();
            }

            $rawBefore = $this->adapter->rawDesignSnapshot('preset');
            $currentRev = (string) $currentDesign['revision'];
            $snap = $this->snapshots->create(
                'design',
                'preset',
                null,
                $currentRev,
                [
                    'builder' => $this->adapter->name(),
                    'raw_design' => $rawBefore,
                ],
                'design'
            );

            try {
                if ($filteredColors !== []) {
                    $this->adapter->saveMergedColors($valData['colors'], $this->metaRepo);
                }
                if ($filteredVariables !== []) {
                    $this->adapter->saveMergedVariables($valData['variables'], $this->metaRepo);
                }
                if ($filteredClasses !== []) {
                    $this->adapter->saveMergedClasses($valData['classes'], $valData['deleted_classes'] ?? [], $this->metaRepo);
                }
                $this->adapter->afterDesignWrite('preset');
            } catch (\Throwable) {
                $this->adapter->restoreRawDesignSnapshot($rawBefore);
                $this->audit->record($opName, 'design', 'preset', 'write_failed', $snap['id']);
                return Responder::error('write_failed', 'Failed to apply design preset; previous state restored.', 500);
            }

            if (!$this->verifyDesignSmokeTest()) {
                $this->adapter->restoreRawDesignSnapshot($rawBefore);
                $this->audit->record($opName, 'design', 'preset', 'render_check_failed', $snap['id']);
                return Responder::error('render_check_failed', 'Design preset failed smoke-test page render verification and was rolled back.', 500);
            }

            $afterDesign = $this->adapter->designSystem($this->metaRepo);
            $finalRev = (string) $afterDesign['revision'];

            $this->audit->record($opName, 'design', 'preset', 'ok', $snap['id'], [
                'preset' => $presetName,
                'base_revision' => $currentRev,
                'revision' => $finalRev,
            ]);

            $warnings = array_merge($skipWarnings, $valWarnings);
            $this->appendPublishedContentWarning($warnings);

            $responseData = $afterDesign;
            $responseData['id'] = 'design:preset';
            $responseData['status'] = 'applied';
            $responseData['preset'] = $presetName;
            $responseData['snapshot_id'] = $snap['snapshot_id'];

            return Responder::success($responseData, 200, [
                'warnings' => array_map(static fn(Problem $w): array => $w->toArray(), $warnings),
            ]);
        } finally {
            $this->releaseLock($lockName);
        }
    }

    /**
     * Restore a `design` snapshot (`POST /snapshots/{id}/restore` when `object_type = 'design'`).
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $payload
     */
    private function restoreDesignSnapshot(string $opName, array $snapshot, string $objectKey, array $payload): \WP_REST_Response
    {
        $rawDesign = isset($payload['raw_design']) && is_array($payload['raw_design']) ? $payload['raw_design'] : null;
        if ($rawDesign === null) {
            return Responder::error('validation_failed', 'Design snapshot payload is missing raw_design data.', 422);
        }

        $lockName = $this->buildLockName('design:global');
        if (!$this->acquireLock($lockName)) {
            $this->audit->record($opName, 'design', $objectKey, 'object_locked');
            return Responder::error('object_locked', 'Another write operation is currently in progress for the design system.', 409);
        }

        try {
            $currentDesign = $this->adapter->designSystem($this->metaRepo);
            $currentRaw = $this->adapter->rawDesignSnapshot($objectKey);
            $currentRev = (string) ($currentDesign['revisions'][$objectKey] ?? $currentDesign['revision']);

            $preRestoreSnap = $this->snapshots->create(
                'design',
                $objectKey,
                null,
                $currentRev,
                [
                    'builder' => $this->adapter->name(),
                    'raw_design' => $currentRaw,
                ],
                'restore'
            );

            try {
                $this->adapter->restoreRawDesignSnapshot($rawDesign);
            } catch (\Throwable) {
                $this->adapter->restoreRawDesignSnapshot($currentRaw);
                $this->audit->record($opName, 'design', $objectKey, 'write_failed', $preRestoreSnap['id']);
                return Responder::error('write_failed', 'Failed to restore design snapshot; previous state preserved.', 500);
            }

            if (!$this->verifyDesignSmokeTest()) {
                $this->adapter->restoreRawDesignSnapshot($currentRaw);
                $this->audit->record($opName, 'design', $objectKey, 'render_check_failed', $preRestoreSnap['id']);
                return Responder::error('render_check_failed', 'Restored design snapshot failed smoke-test page render verification; previous state preserved.', 500);
            }

            $afterDesign = $this->adapter->designSystem($this->metaRepo);
            $finalRev = (string) ($afterDesign['revisions'][$objectKey] ?? $afterDesign['revision']);

            $this->audit->record($opName, 'design', $objectKey, 'ok', $preRestoreSnap['id'], [
                'restored_from' => (string) $snapshot['snapshot_id'],
                'revision' => $finalRev,
            ]);

            $responseData = $afterDesign;
            $responseData['object'] = 'design:' . $objectKey;
            $responseData['restored_from'] = (string) $snapshot['snapshot_id'];
            $responseData['snapshot_id'] = $preRestoreSnap['snapshot_id'];
            $responseData['revision'] = $finalRev;

            return Responder::success($responseData, 200);
        } finally {
            $this->releaseLock($lockName);
        }
    }

    /**
     * Check `base_revision` for a design-system write.
     * Accepts either the area-specific revision (`revisions[$areaKey]`) or the overall design `revision`.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $currentDesign
     */
    private function checkDesignBaseRevision(
        string $opName,
        string $auditObjectKey,
        array $body,
        array $currentDesign,
        string $areaKey,
        bool $requireRevision
    ): ?\WP_REST_Response {
        $baseRev = $body['base_revision'] ?? null;
        if ($baseRev === null || $baseRev === '') {
            if ($requireRevision) {
                $err = new Problem(
                    'base_revision',
                    'missing_required',
                    "Field 'base_revision' is required for destructive design operations and preset initialization."
                );
                $this->audit->record($opName, 'design', $auditObjectKey, 'validation_failed');
                return Responder::validationFailed([$err]);
            }
            return null;
        }

        if (!is_string($baseRev)) {
            $err = new Problem('base_revision', 'invalid_value', "Field 'base_revision' must be a string.");
            $this->audit->record($opName, 'design', $auditObjectKey, 'validation_failed');
            return Responder::validationFailed([$err]);
        }

        $topRev = (string) ($currentDesign['revision'] ?? '');
        $areaRev = isset($currentDesign['revisions'][$areaKey]) ? (string) $currentDesign['revisions'][$areaKey] : $topRev;

        if (!hash_equals($areaRev, $baseRev) && !hash_equals($topRev, $baseRev)) {
            $this->audit->record($opName, 'design', $auditObjectKey, 'revision_conflict', null, [
                'base_revision' => $baseRev,
                'current_revision' => $areaRev,
            ]);
            return Responder::error(
                'revision_conflict',
                sprintf("Revision conflict for '%s': expected '%s', current stored revision is '%s'.", $auditObjectKey, $baseRev, $areaRev),
                409,
                [],
                [
                    'current_revision' => $areaRev,
                    'current_revisions' => $currentDesign['revisions'] ?? [],
                ]
            );
        }

        return null;
    }

    private function verifyDesignSmokeTest(): bool
    {
        $verifyRender = (bool) get_option('ln_builder_verify_render', true);
        if (!$verifyRender) {
            return true;
        }

        $smokePageId = (int) get_option('ln_builder_smoke_test_page_id', 0);
        if ($smokePageId <= 0) {
            return true;
        }

        $details = $this->adapter->pageDetails($smokePageId);
        if ($details === null) {
            return true;
        }

        return $this->adapter->tryRender($smokePageId, 'content');
    }

    /**
     * @param list<Problem> $warnings
     */
    private function appendPublishedContentWarning(array &$warnings): void
    {
        $warnings[] = new Problem(
            '',
            'published_content_affected',
            'Design system changes affect all published templates and pages referencing these tokens or classes; use snapshot_id to restore if needed.',
            null,
            Problem::SEVERITY_WARNING
        );
    }

    /**
     * Sanitize user-supplied text, HTML, and URLs in the expanded native element array.
     * When `$onlyIds` is non-null, only elements whose `id` is in `$onlyIds` are sanitized,
     * leaving untouched sections byte-for-byte identical.
     *
     * @param array<int, array<string, mixed>> $nativeElements
     * @param array<string, true>|null $onlyIds
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeNativeElements(array $nativeElements, ?array $onlyIds = null): array
    {
        foreach ($nativeElements as &$el) {
            $elId = (string) ($el['id'] ?? '');
            if ($onlyIds !== null && !isset($onlyIds[$elId])) {
                continue;
            }

            if (isset($el['label']) && is_string($el['label'])) {
                $el['label'] = sanitize_text_field($el['label']);
            }

            if (!isset($el['settings']) || !is_array($el['settings'])) {
                continue;
            }

            $name = (string) ($el['name'] ?? '');

            if ($this->adapter->name() === 'elementor') {
                $el['settings'] = $this->sanitizeElementorSettings($name, $el['settings']);
                continue;
            }

            if ($name === 'text' && isset($el['settings']['text']) && is_string($el['settings']['text'])) {
                $el['settings']['text'] = wp_kses_post($el['settings']['text']);
            } elseif (in_array($name, ['heading', 'button'], true) && isset($el['settings']['text']) && is_string($el['settings']['text'])) {
                $el['settings']['text'] = sanitize_text_field($el['settings']['text']);
            }

            if ($name === 'button' && isset($el['settings']['link']['url']) && is_string($el['settings']['link']['url'])) {
                $el['settings']['link']['url'] = $this->sanitizeUrlOrDynamicTag($el['settings']['link']['url']);
            }

            if ($name === 'image') {
                if (isset($el['settings']['altText']) && is_string($el['settings']['altText'])) {
                    $el['settings']['altText'] = sanitize_text_field($el['settings']['altText']);
                }
                if (isset($el['settings']['image']['url']) && is_string($el['settings']['image']['url'])) {
                    $el['settings']['image']['url'] = $this->sanitizeUrlOrDynamicTag($el['settings']['image']['url']);
                }
            }

            if ($name === 'list' && isset($el['settings']['items']) && is_array($el['settings']['items'])) {
                foreach ($el['settings']['items'] as &$item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    if (isset($item['title']) && is_string($item['title'])) {
                        $item['title'] = sanitize_text_field($item['title']);
                    }
                    if (isset($item['link']['url']) && is_string($item['link']['url'])) {
                        $item['link']['url'] = $this->sanitizeUrlOrDynamicTag($item['link']['url']);
                    }
                }
                unset($item);
            }
        }
        unset($el);

        return $nativeElements;
    }

    /**
     * Elementor counterpart of the Bricks sanitization above (native keys differ per widget).
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function sanitizeElementorSettings(string $widget, array $settings): array
    {
        if (isset($settings['_title']) && is_string($settings['_title'])) {
            $settings['_title'] = sanitize_text_field($settings['_title']);
        }

        switch ($widget) {
            case 'text-editor':
                if (isset($settings['editor']) && is_string($settings['editor'])) {
                    $settings['editor'] = wp_kses_post($settings['editor']);
                }
                break;
            case 'heading':
                if (isset($settings['title']) && is_string($settings['title'])) {
                    $settings['title'] = sanitize_text_field($settings['title']);
                }
                break;
            case 'button':
                if (isset($settings['text']) && is_string($settings['text'])) {
                    $settings['text'] = sanitize_text_field($settings['text']);
                }
                if (isset($settings['link']['url']) && is_string($settings['link']['url'])) {
                    $settings['link']['url'] = $this->sanitizeUrlOrDynamicTag($settings['link']['url']);
                }
                break;
            case 'image':
                if (isset($settings['image']['url']) && is_string($settings['image']['url'])) {
                    $settings['image']['url'] = $this->sanitizeUrlOrDynamicTag($settings['image']['url']);
                }
                if (isset($settings['image']['alt']) && is_string($settings['image']['alt'])) {
                    $settings['image']['alt'] = sanitize_text_field($settings['image']['alt']);
                }
                break;
            case 'icon-list':
                if (isset($settings['icon_list']) && is_array($settings['icon_list'])) {
                    foreach ($settings['icon_list'] as &$item) {
                        if (!is_array($item)) {
                            continue;
                        }
                        if (isset($item['text']) && is_string($item['text'])) {
                            $item['text'] = sanitize_text_field($item['text']);
                        }
                        if (isset($item['link']['url']) && is_string($item['link']['url'])) {
                            $item['link']['url'] = $this->sanitizeUrlOrDynamicTag($item['link']['url']);
                        }
                    }
                    unset($item);
                }
                break;
        }

        return $settings;
    }

    private function sanitizeUrlOrDynamicTag(string $url): string
    {
        $trimmed = trim($url);
        if (preg_match('/^\{[a-zA-Z0-9_:-]+\}$/', $trimmed) === 1) {
            return $trimmed;
        }
        return esc_url_raw($url);
    }

    /**
     * Remap all element IDs in a native Bricks flat array to fresh unique 6-char IDs
     * (used when cloning a template into an independent page via `from_template`).
     *
     * @param array<int, array<string, mixed>> $nativeElements
     * @return array<int, array<string, mixed>>
     */
    private function remapNativeElementIds(array $nativeElements): array
    {
        $usedIds = [];
        $idMap = [];

        foreach ($nativeElements as $el) {
            if (!is_array($el) || !isset($el['id'])) {
                continue;
            }
            $oldId = (string) $el['id'];
            if ($oldId !== '') {
                $usedIds[$oldId] = true;
            }
        }

        foreach ($nativeElements as $el) {
            if (!is_array($el) || !isset($el['id'])) {
                continue;
            }
            $oldId = (string) $el['id'];
            if ($oldId !== '' && !isset($idMap[$oldId])) {
                $newId = Expander::defaultIdGenerator();
                while (isset($usedIds[$newId])) {
                    $newId = Expander::defaultIdGenerator();
                }
                $usedIds[$newId] = true;
                $idMap[$oldId] = $newId;
            }
        }

        $remapped = [];
        foreach ($nativeElements as $el) {
            if (!is_array($el) || !isset($el['id'])) {
                continue;
            }
            $oldId = (string) $el['id'];
            $el['id'] = $idMap[$oldId] ?? $oldId;

            $parent = $el['parent'] ?? 0;
            if ($parent !== 0 && $parent !== '0' && $parent !== '') {
                $parentStr = (string) $parent;
                $el['parent'] = $idMap[$parentStr] ?? $parentStr;
            }

            if (isset($el['children']) && is_array($el['children'])) {
                $newChildren = [];
                foreach ($el['children'] as $cId) {
                    $cStr = (string) $cId;
                    $newChildren[] = $idMap[$cStr] ?? $cStr;
                }
                $el['children'] = $newChildren;
            }

            $remapped[] = $el;
        }

        return $remapped;
    }

    /**
     * @param array<int, array<string, mixed>> $previousNative
     */
    private function rollbackContentArea(int $postId, string $area, array $previousNative): void
    {
        try {
            $this->adapter->putContentArea($postId, $area, $previousNative);
            $this->adapter->afterWrite($postId);
        } catch (\Throwable) {
            // Suppress secondary errors during rollback
        }
    }

    private function isPostLockedByEditor(int $postId): bool
    {
        if (!function_exists('wp_check_post_lock')) {
            $postAdminFile = ABSPATH . 'wp-admin/includes/post.php';
            if (is_file($postAdminFile)) {
                require_once $postAdminFile;
            }
        }

        if (function_exists('wp_check_post_lock')) {
            $lockedBy = wp_check_post_lock($postId);
            return is_int($lockedBy) && $lockedBy > 0;
        }

        return false;
    }

    private function buildLockName(string $target): string
    {
        global $wpdb;
        $dbName = defined('DB_NAME') ? (string) DB_NAME : 'wp';
        $hash = sha1($dbName . ':' . $wpdb->prefix . ':' . $target);
        return 'ln_bldr_' . substr($hash, 0, 48);
    }

    private function acquireLock(string $lockName): bool
    {
        global $wpdb;
        $res = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lockName, self::LOCK_TIMEOUT_SECONDS));
        return (int) $res === 1;
    }

    private function releaseLock(string $lockName): void
    {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
    }

    private function recordAttributionMarker(int $postId, bool $isCreate): void
    {
        if ($postId <= 0 || !function_exists('update_post_meta')) {
            return;
        }

        $ctx = RequestContext::current();
        $marker = [
            'act' => $ctx !== null ? $ctx->actor() : '',
            'name' => $ctx !== null ? $ctx->actorName() : null,
            'org' => $ctx !== null ? $ctx->org() : null,
            'sid' => $ctx !== null ? $ctx->session() : '',
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        if ($isCreate) {
            update_post_meta($postId, '_ln_builder_created_by', $marker);
        } else {
            update_post_meta($postId, '_ln_builder_last_ai_write', $marker);
        }
    }
}
