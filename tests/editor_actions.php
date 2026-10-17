<?php
ob_start();
require __DIR__ . '/editor_save.php';

$toggleOwner = request('page', ['_token' => 'test-csrf', 'name' => 'Toggle contact'], 201);
$toggleAction = (object) ['id' => 'qa-toggle-contact', 'name' => 'Toggle contact', 'trigger' => 'click', 'steps' => [
    (object) ['id' => 'qs-toggle-contact', 'target' => (object) ['type' => 'element', 'value' => 'qt-contact'], 'condition' => null, 'operation' => (object) ['type' => 'toggle-visibility']],
]];
$toggleButton = $element('button', 'Contact'); $toggleButton['_quill']->editor->onClick = $toggleAction->id;
$toggleTarget = $element('div', 'Contact details'); $toggleTarget['_quill']->editor->actionTargetId = 'qt-contact';
$toggleTarget['attributes'] = (object) ['hidden' => true, 'style' => 'display: flex !important'];
$toggleDocument = ['schema_version' => 1, 'settings' => (object) ['actions' => [$toggleAction]], 'sections' => [['name' => 'main', 'blocks' => [$toggleButton, $toggleTarget]]]];
$toggleSaved = documentRequest(['type' => 'page', 'id' => (string) $toggleOwner['id']], array_replace($post, ['document' => json_encode($toggleDocument), 'lock_version' => '1', 'mode' => 'publish']), 200);
$toggleSnapshot = new \Quill\models\QuillPageSnapshot(); $toggleSnapshot->selectByID($toggleSaved['snapshot_id']);
$toggleResult = (new \Quill\services\conversion\quillToUrpc\QuillToUrpcConverter())->convert(new \Quill\services\conversion\quillToUrpc\SnapshotQuillContentSource($toggleSnapshot));
check($toggleResult->actions[0]->steps[0]->operation->type === 'toggle-visibility', 'Toggle visibility survives Save/Publish and is included in the public runtime catalog');
check($toggleResult->document->getBlocks('main')[0]->getBlockAttributes()['data-quill-action'] === $toggleAction->id && $toggleResult->document->getBlocks('main')[1]->getBlockAttributes()['hidden'] === true, 'Toggle bindings and initial hidden state survive public export');
$toggleErrors = libxml_use_internal_errors(true);
$toggleView = $viewController->editor(['type' => 'page', 'id' => (string) $toggleOwner['id']]);
libxml_clear_errors(); libxml_use_internal_errors($toggleErrors);
$toggleReload = json_decode($toggleView->bundle['editorDocumentJson']);
check($toggleReload->settings->actions[0]->steps[0]->operation->type === 'toggle-visibility', 'Reopening the editor retains the new operation');
$animation = (object) ['effect' => 'slide', 'duration' => 280, 'delay' => 20, 'easing' => 'spring', 'direction' => 'up', 'distance' => 32, 'exit' => (object) ['effect' => 'scale', 'duration' => 160, 'delay' => 0, 'easing' => 'gentle']];
$toggleDocument['settings']->actions[0]->steps[0]->operation->animation = $animation;
$toggleDocument['sections'][0]['blocks'][1]['attributes']->style .= ';transition: opacity 280ms ease-in-out';
$animatedSaved = documentRequest(['type' => 'page', 'id' => (string) $toggleOwner['id']], array_replace($post, ['document' => json_encode($toggleDocument), 'lock_version' => '2', 'mode' => 'publish']), 200);
$toggleSnapshot->selectByID($animatedSaved['snapshot_id']);
$animatedResult = (new \Quill\services\conversion\quillToUrpc\QuillToUrpcConverter())->convert(new \Quill\services\conversion\quillToUrpc\SnapshotQuillContentSource($toggleSnapshot));
check(json_encode($animatedResult->actions[0]->steps[0]->operation->animation) === json_encode($animation), 'Appear and disappear animation settings survive publishing');
check(str_contains($animatedResult->scopedCss, '@media(prefers-reduced-motion:reduce)') && str_contains($animatedResult->scopedCss, 'transition:none!important'), 'Published block transitions honor reduced motion, including inline declarations');
$beforeInvalid = (int) $pdo->query('SELECT COUNT(*) FROM quill_page_snapshot')->fetchColumn();
foreach ([['effect' => 'spin'], ['duration' => -1], ['duration' => '250'], ['delay' => 10001], ['distance' => 501], ['easing' => 'bounce'], ['direction' => 'diagonal'], ['exit' => (object) ['effect' => 'fade', 'exit' => (object) ['effect' => 'fade']]], ['unexpected' => true]] as $invalid) {
    $invalidDocument = json_decode(json_encode($toggleDocument));
    foreach ($invalid as $key => $value) $invalidDocument->settings->actions[0]->steps[0]->operation->animation->{$key} = $value;
    documentRequest(['type' => 'page', 'id' => (string) $toggleOwner['id']], array_replace($post, ['document' => json_encode($invalidDocument), 'lock_version' => '3', 'mode' => 'publish']), 422);
}
$invalidDocument = json_decode(json_encode($toggleDocument));
$invalidDocument->settings->actions[0]->steps[0]->operation->type = 'add-class';
$invalidDocument->settings->actions[0]->steps[0]->operation->value = 'shown';
documentRequest(['type' => 'page', 'id' => (string) $toggleOwner['id']], array_replace($post, ['document' => json_encode($invalidDocument), 'lock_version' => '3', 'mode' => 'publish']), 422);
check((int) $pdo->query('SELECT COUNT(*) FROM quill_page_snapshot')->fetchColumn() === $beforeInvalid, 'Invalid animations reject before publishing any snapshot');
echo ob_get_clean();
echo "Passed visibility toggle persistence checks; $count total checks.\n";
