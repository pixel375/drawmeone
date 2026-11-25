<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/artwork.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    dmo_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
}
try {
    $db = dmo_database();
    $admin = dmo_admin_require_session($db);
    if ($method === 'GET') {
        $query = $db->query(
            'SELECT a.id, a.slug, a.visibility, a.is_featured, a.sort_order, a.alt_text, ' .
            'a.created_at, d.pixel_width, d.pixel_height FROM dmo_portfolio_artworks a ' .
            "LEFT JOIN dmo_artwork_files d ON d.artwork_id = a.id AND d.variant = 'display' " .
            'ORDER BY a.created_at DESC, a.id DESC LIMIT 2001'
        );
        $rows=$query->fetchAll();
        $truncated=count($rows)>2000;
        $items = [];
        foreach (array_slice($rows,0,2000) as $row) {
            $id = (int) $row['id'];
            $items[] = [
                'id' => $id,
                'visibility' => $row['visibility'],
                'featured' => (bool) $row['is_featured'],
                'sort_order' => (int) $row['sort_order'],
                'created_at' => $row['created_at'],
                'alt' => (string)($row['alt_text']??''),
                'width' => $row['pixel_width'] === null ? null : (int) $row['pixel_width'],
                'height' => $row['pixel_height'] === null ? null : (int) $row['pixel_height'],
                'thumb' => 'artwork-preview.php?id=' . $id . '&v=thumb',
            ];
        }
        dmo_json(200, [
            'ok' => true, 'artworks' => $items, 'truncated'=>$truncated,
            'write_enabled' => dmo_artwork_writes_enabled(),
        ]);
    }

    $input = dmo_auth_json_request();
    dmo_auth_verify_csrf($admin);
    if (!dmo_artwork_writes_enabled()) {
        dmo_json(503, ['ok' => false, 'error' => 'artwork_editing_disabled']);
    }
    $action = $input['action'] ?? null;
    if($action==='batch_visibility')dmo_artwork_batch_visibility($db,(int)$admin['user_id'],$input);
    $id = dmo_artwork_id($input['id'] ?? null);
    if (!in_array($action, ['set_visibility', 'set_featured', 'set_order', 'set_alt'], true)) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_action']);
    }
    $params = ['id' => $id];
    if ($action === 'set_visibility') {
        $value = $input['visibility'] ?? null;
        if (!is_string($value) || !in_array($value, ['draft', 'published'], true)) {
            dmo_json(422, ['ok' => false, 'error' => 'invalid_visibility']);
        }
        $params['value'] = $value;
        $update = 'visibility = :value, published_at = ' .
            ($value === 'published' ? 'COALESCE(published_at, UTC_TIMESTAMP())' : 'NULL');
    } elseif ($action === 'set_featured') {
        $value = $input['featured'] ?? null;
        if (!is_bool($value)) {
            dmo_json(422, ['ok' => false, 'error' => 'invalid_featured']);
        }
        $params['value'] = (int) $value;
        $update = 'is_featured = :value';
    } elseif($action==='set_alt') {
        $value=$input['alt']??null;
        if(!is_string($value)||strlen($value)>240||!preg_match('//u',$value)||preg_match('/[\\x00-\\x1f\\x7f]/',$value))dmo_json(422,['ok'=>false,'error'=>'invalid_alt_text']);
        $params['value']=trim($value)===''?null:trim($value);
        $update='alt_text = :value';
    } else {
        $value = $input['sort_order'] ?? null;
        if (!is_int($value) || $value < -10000 || $value > 10000) {
            dmo_json(422, ['ok' => false, 'error' => 'invalid_sort_order']);
        }
        $params['value'] = $value;
        $update = 'sort_order = :value';
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT visibility FROM dmo_portfolio_artworks WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        if ($stmt->fetchColumn() === false) {
            $db->rollBack();
            dmo_json(404, ['ok' => false, 'error' => 'artwork_not_found']);
        }
        if($action==='set_visibility'&&$value==='published'&&!dmo_artwork_publishable($db,$id)){
            $db->rollBack();dmo_json(422,['ok'=>false,'error'=>'optimized_artwork_missing']);
        }
        $db->prepare('UPDATE dmo_portfolio_artworks SET ' . $update . ' WHERE id = :id')
            ->execute($params);
        dmo_artwork_record_audit(
            $db, (int) $admin['user_id'], $id, 'artwork.' . $action
        );
        $db->commit();
        dmo_json(200, ['ok' => true, 'id' => $id]);
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
} catch (Throwable $error) {
    dmo_log_problem($error, 'admin artwork management');
    dmo_json(503, ['ok' => false, 'error' => 'artwork_service_unavailable']);
}
