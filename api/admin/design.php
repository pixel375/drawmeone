<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/design.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    dmo_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

try {
    $db = dmo_database();
    $admin = dmo_admin_require_session($db);

    if ($method === 'GET') {
        dmo_json(200, [
            'ok' => true,
            'draft' => dmo_design_load($db, 'draft'),
            'published' => dmo_design_load($db, 'published'),
            'write_enabled' => dmo_config('DMO_DESIGN_WRITES_ENABLED') === '1',
            'presentation' => dmo_design_presentation(),
            'history' => dmo_design_history($db),
        ]);
    }

    dmo_design_require_write_flag();
    $input = dmo_auth_json_request();
    dmo_auth_verify_csrf($admin);
    $action = $input['action'] ?? null;
    $expected = $input['expected_version'] ?? null;
    if (!in_array($action, ['save_draft', 'publish', 'restore_draft'], true) ||
        !is_int($expected) || $expected < 0 || $expected > 4294967000) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_request']);
    }

    if ($action==='restore_draft' && (!is_int($input['snapshot_id']??null)||$input['snapshot_id']<1)) {
        dmo_json(422,['ok'=>false,'error'=>'invalid_snapshot']);
    }
    $db->beginTransaction();
    try {
        // Serialize first-time saves as well as subsequent updates.
        if($db->query("SELECT id FROM dmo_users WHERE role='admin' ORDER BY id LIMIT 1 FOR UPDATE")->fetchColumn()===false){
            throw new RuntimeException('Design lock unavailable.');
        }
        // Lock the draft row (if any) and use optimistic versions so a
        // stale tab cannot overwrite a more recent design.
        $lock = $db->prepare(
            "SELECT version_number, settings_json FROM dmo_site_settings " .
            "WHERE context = 'draft' FOR UPDATE"
        );
        $lock->execute();
        $draftRow = $lock->fetch();
        $draftVersion = is_array($draftRow) ? (int) $draftRow['version_number'] : 0;
        if ($expected !== $draftVersion) {
            $db->rollBack();
            dmo_json(409, ['ok' => false, 'error' => 'stale_draft_reload_required']);
        }

        if ($action === 'save_draft' || $action === 'restore_draft') {
            if($action==='restore_draft'){
                $settings=null;
                foreach(dmo_design_history($db) as $snapshot){
                    if($snapshot['id']===$input['snapshot_id']){$settings=$snapshot['settings'];break;}
                }
                if($settings===null){$db->rollBack();dmo_json(404,['ok'=>false,'error'=>'snapshot_unavailable']);}
            }else $settings = dmo_design_normalize($input['settings'] ?? null);
            if ($settings === null) {
                $db->rollBack();
                dmo_json(422, ['ok' => false, 'error' => 'invalid_design_options']);
            }
            $json = json_encode($settings, JSON_THROW_ON_ERROR);
            if ($draftRow === false) {
                $db->prepare(
                    "INSERT INTO dmo_site_settings " .
                    "(context, version_number, settings_json, edited_by) " .
                    "VALUES ('draft', 1, :json, :admin)"
                )->execute(['json' => $json, 'admin' => $admin['user_id']]);
                $newVersion = 1;
            } else {
                $db->prepare(
                    "UPDATE dmo_site_settings SET " .
                    "settings_json = :json, version_number = version_number + 1, " .
                    "edited_by = :admin WHERE context = 'draft'"
                )->execute(['json' => $json, 'admin' => $admin['user_id']]);
                $newVersion = $draftVersion + 1;
            }
            dmo_admin_audit($db, (int) $admin['user_id'], $action==='restore_draft'?'design.draft_restored':'design.draft_saved');
            $db->commit();
            dmo_json(200, ['ok' => true, 'draft_version' => $newVersion]);
        }

        if (!is_array($draftRow)) {
            $db->rollBack();
            dmo_json(422, ['ok' => false, 'error' => 'save_draft_first']);
        }
        $decoded = json_decode((string) $draftRow['settings_json'], true);
        $settings = dmo_design_normalize($decoded);
        if ($settings === null) {
            throw new RuntimeException('Invalid draft in database.');
        }
        $json = json_encode($settings, JSON_THROW_ON_ERROR);
        $published = $db->prepare(
            "SELECT version_number, settings_json FROM dmo_site_settings " .
            "WHERE context = 'published' FOR UPDATE"
        );
        $published->execute();
        $existing = $published->fetch();
        $newVersion = is_array($existing) ? (int) $existing['version_number'] + 1 : 1;

        if (is_array($existing)) {
            $previous=dmo_design_normalize(json_decode((string)$existing['settings_json'],true));
            if($previous===null)throw new RuntimeException('Invalid published design.');
            if($previous===$settings){
                $db->commit();
                dmo_json(200,['ok'=>true,'published_version'=>(int)$existing['version_number'],'unchanged'=>true]);
            }
            dmo_design_archive($db,(int)$admin['user_id'],(int)$existing['version_number'],$previous);
        }
        if ($existing === false) {
            $db->prepare(
                "INSERT INTO dmo_site_settings " .
                "(context, version_number, settings_json, edited_by) " .
                "VALUES ('published', :version, :json, :admin)"
            )->execute([
                'version' => $newVersion, 'json' => $json,
                'admin' => $admin['user_id'],
            ]);
        } else {
            $db->prepare(
                "UPDATE dmo_site_settings SET settings_json = :json, " .
                "version_number = :version, edited_by = :admin " .
                "WHERE context = 'published'"
            )->execute([
                'json' => $json, 'version' => $newVersion,
                'admin' => $admin['user_id'],
            ]);
        }
        dmo_admin_audit($db, (int) $admin['user_id'], 'design.published');
        $db->commit();
        dmo_json(200, ['ok' => true, 'published_version' => $newVersion]);
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
} catch (Throwable $error) {
    dmo_log_problem($error, 'admin design settings');
    dmo_json(503, ['ok' => false, 'error' => 'design_service_unavailable']);
}
