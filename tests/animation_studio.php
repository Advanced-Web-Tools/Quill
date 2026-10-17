<?php
// Real controllers, mapper, snapshots and URPC; no editor UI assets reach public output.
ob_start();
require __DIR__ . '/editor_save.php';

use Quill\models\QuillPage;
use Quill\models\QuillPageSnapshot;
use Quill\services\animations\AnimationDefinitions;
use Quill\services\conversion\quillToUrpc\QuillToUrpcConverter;
use Quill\services\conversion\quillToUrpc\WorkingQuillContentSource;
use Quill\services\conversion\quillToUrpc\SnapshotQuillContentSource;

$animationOwner = request('page', ['_token' => 'test-csrf', 'name' => 'Animation resources'], 201);
$animationParams = ['type' => 'page', 'id' => (string) $animationOwner['id']];
$animationDocument = ['schema_version' => 1, 'sections' => [['name' => 'main', 'blocks' => [$element('section', '', [$element('p', 'Animated text'), $element('p', 'Reusable animation')])]]]];
$animationDefinition = ['id' => 'qanim-hero', 'name' => 'Hero entrance', 'duration' => 1200, 'delay' => 100, 'iterations' => 2,
    'direction' => 'alternate', 'fillMode' => 'both', 'easing' => 'ease-in-out', 'tracks' => (object) [
        'opacity' => [['time' => 0, 'value' => '0'], ['time' => 600, 'value' => '0.5', 'easing' => 'ease-out'], ['time' => 1200, 'value' => '1']],
        'rotate' => [['time' => 0, 'value' => '-15deg'], ['time' => 1200, 'value' => '0deg']],
        'background-image' => [['time' => 0, 'value' => 'linear-gradient(90deg, red, blue)'], ['time' => 1200, 'value' => 'linear-gradient(0deg, red, blue)']],
    ]];
$animationDocument['settings'] = ['animations' => (object) ['qanim-hero' => $animationDefinition], 'animation_bindings' => [
    ['id' => 'qbind-one', 'animationId' => 'qanim-hero', 'target' => ['section' => 'main', 'path' => [0,0]], 'trigger' => 'load'],
    ['id' => 'qbind-two', 'animationId' => 'qanim-hero', 'target' => ['section' => 'main', 'path' => [0,1]], 'trigger' => 'click'],
]];
$animationBlocks = json_encode($animationDocument['sections']);
$animationPost = ['_token' => 'test-csrf', 'document' => json_encode($animationDocument), 'lock_version' => '1', 'mode' => 'publish'];
$animationSaved = documentRequest($animationParams, $animationPost, 200);
$animationPage = new QuillPage(); $animationPage->selectByID($animationOwner['id']);
$animationSettings = $animationPage->getDocument()->settings;
check($animationSettings->animations->{'qanim-hero'}->tracks->opacity[1]->easing === 'ease-out' && count($animationSettings->animation_bindings) === 2, 'Global animation definitions and reusable bindings persist outside block records');
$animationResult = (new QuillToUrpcConverter())->convert(new WorkingQuillContentSource($animationPage));
check(substr_count($animationResult->scopedCss, '@keyframes quill-kf-') === 3 && str_contains($animationResult->scopedCss, '50%{opacity:0.5;animation-timing-function:ease-out;}'), 'URPC output preserves separate property offsets and per-keyframe easing');
$animationChildren = array_values($animationResult->document->getBlocks('main')[0]->getBlockChildren());
check(str_contains($animationChildren[0]->getBlockAttributes()['class'], 'quill-animation-') && $animationChildren[1]->getBlockAttributes()['data-quill-animation-trigger'] === 'click', 'Conversion attaches only generated render-time animation classes/triggers to bound elements');
check(str_contains($animationResult->scopedCss, 'animation-iteration-count:2') && str_contains($animationResult->scopedCss, 'animation-direction:alternate') && str_contains($animationResult->scopedCss, 'prefers-reduced-motion:reduce'), 'Published animations retain playback settings and honor reduced motion');
check(in_array('/awt_packages/Quill/views/assets/js/quill-animation-runtime.js', $animationResult->assets['scripts'], true)
    && !array_filter($animationResult->assets['scripts'], fn ($url) => str_contains($url, 'studio') || str_contains($url, 'quill-editor') || str_contains($url, 'quill-animation-session')), 'Public output includes click playback only, never Animation Studio or editor assets');
$animationView = (new Quill\services\editor\EditorDocumentLoader())->load($animationPage);
check(json_encode($animationView['document']->settings->animations) === json_encode($animationSettings->animations), 'Reopening the editor retains the complete animation definition');
$loadedAnimationNodes = $animationView['document']->sections[0]->blocks[0]->children;
check(!str_contains(json_encode($loadedAnimationNodes), 'quill-animation-') && !str_contains(json_encode($loadedAnimationNodes), 'data-quill-animation-'), 'Render-only animation attributes are stripped from the editor block transport');
$canonicalAnimationBlock = json_encode($animationPage->getDocument());
$canonicalRefs = $animationPage->getDocument()->sections[0]->blocks;
$blockModel = (new Quill\models\QuillBlock())->useConnectionFrom($animationPage); $blockModel->selectByID($canonicalRefs[0]);
check(!str_contains($blockModel->block_json, 'qanim-hero') && !str_contains($blockModel->block_json, 'animation_bindings'), 'Canonical block JSON never contains keyframes or animation bindings');
$animationSnapshot = new QuillPageSnapshot(); $animationSnapshot->selectByID($animationSaved['snapshot_id']);
$animationPublished = (new QuillToUrpcConverter())->convert(new SnapshotQuillContentSource($animationSnapshot));
check(str_contains($animationPublished->scopedCss, 'opacity:0.5') && count($animationSnapshot->getDocument()->settings->animation_bindings) === 2, 'Published snapshots contain the captured global animation resources');
$animationDocument['settings']['animations']->{'qanim-hero'}['tracks']->opacity[1]['value'] = '0.8';
$animationDraftPost = array_replace($animationPost, ['lock_version' => '2', 'mode' => 'draft', 'document' => json_encode($animationDocument)]);
documentRequest($animationParams, $animationDraftPost, 200);
check(str_contains((new QuillToUrpcConverter())->convert(new SnapshotQuillContentSource($animationSnapshot))->scopedCss, 'opacity:0.5'), 'Later animation drafts cannot change the published snapshot');
foreach (['unsafe-value','unsafe-property','invalid-property','animation-control','bad-easing','missing-animation','missing-target','duplicate-time'] as $case) {
    $invalidAnimationDocument = json_decode(json_encode($animationDocument));
    $definition = $invalidAnimationDocument->settings->animations->{'qanim-hero'};
    if ($case === 'unsafe-value') $definition->tracks->opacity[0]->value = '0;}body{display:none';
    if ($case === 'unsafe-property') $definition->tracks->display = $definition->tracks->opacity;
    if ($case === 'invalid-property') $definition->tracks->{'border-top-radius'} = $definition->tracks->opacity;
    if ($case === 'animation-control') $definition->tracks->{'animation-duration'} = $definition->tracks->opacity;
    if ($case === 'bad-easing') $definition->easing = 'ease;}body{opacity:0';
    if ($case === 'missing-animation') $invalidAnimationDocument->settings->animation_bindings[0]->animationId = 'missing';
    if ($case === 'missing-target') $invalidAnimationDocument->settings->animation_bindings[0]->target->path = [99];
    if ($case === 'duplicate-time') $definition->tracks->opacity[1]->time = 0;
    documentRequest($animationParams, array_replace($animationDraftPost, ['lock_version' => '3', 'document' => json_encode($invalidAnimationDocument)]), 422);
}
$animationPage->selectByID($animationOwner['id']);
check($animationPage->lock_version === 3 && $animationPage->getDocument()->settings->animations->{'qanim-hero'}->tracks->opacity[1]->value === '0.8', 'Invalid animation submissions leave the last valid working state intact');
echo "Passed Animation Studio registry, persistence, snapshot and conversion checks; $count total checks.\n";

ob_end_flush();
