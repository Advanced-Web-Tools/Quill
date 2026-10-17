<?php
require __DIR__ . '/editor_save.php';

use Quill\services\editor\EditorPageRouteCatalog;

$before = $pdo->query('SELECT id,path,page_id FROM quill_dynamic_route ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$catalog = (new EditorPageRouteCatalog())->all();
check(count($catalog) === count($before), 'Editor route catalog includes every editable database route');
foreach ($catalog as $item) {
    check(is_int($item['id']) && ($item['page_id'] === null || is_int($item['page_id'])), 'Route catalog uses typed identities');
    check(array_key_exists('page_name', $item), 'Route catalog includes page labels');
}
check($before === $pdo->query('SELECT id,path,page_id FROM quill_dynamic_route ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'Building the editor route catalog does not write routes');
echo "Passed editor page route catalog checks.\n";
