<?php
declare(strict_types=1);

/**
 * Disposable MariaDB integration test. CI database only; no production
 * credentials, HTTP sessions, outbound email, payment providers or file writes.
 */
if (PHP_SAPI !== 'cli' || getenv('DMO_CI_INTEGRATION') !== '1' ||
    getenv('DB_NAME') !== 'drawmeone_ci') {
    fwrite(STDERR, "Refusing non-CI integration context.\n");
    exit(2);
}
require_once dirname(__DIR__) . '/app/commission-workflow.php';
require_once dirname(__DIR__) . '/app/quotes.php';
require_once dirname(__DIR__) . '/app/orders.php';

function qa_assert(bool $condition, string $name): void
{
    if (!$condition) throw new RuntimeException('INTEGRATION_FAIL=' . $name);
    echo "PASS: " . $name . "\n";
}
function qa_write(PDO $db, string $sql, array $args): int
{
    $db->prepare($sql)->execute($args);
    return (int) $db->lastInsertId();
}
function qa_reference(int $customer, string $seed): string
{
    return 'DMO-' . substr(hash('sha256', 'qa-' . $customer . '-' . $seed), 0, 20);
}

try {
    $db = dmo_database();
    $db->beginTransaction();
    $hash = password_hash('CI-only-fixture-password-123!', PASSWORD_DEFAULT);
    $person1 = qa_write($db,
        "INSERT INTO dmo_users (email, display_name, password_hash, role, account_status, email_verified_at)
         VALUES (:email, 'QA Customer One', :hash, 'customer', 'active', UTC_TIMESTAMP())",
        ['email' => 'qa1@example.invalid', 'hash' => $hash]);
    $person2 = qa_write($db,
        "INSERT INTO dmo_users (email, display_name, password_hash, role, account_status, email_verified_at)
         VALUES (:email, 'QA Customer Two', :hash, 'customer', 'active', UTC_TIMESTAMP())",
        ['email' => 'qa2@example.invalid', 'hash' => $hash]);
    $artist = qa_write($db,
        "INSERT INTO dmo_users (email, display_name, password_hash, role, account_status, email_verified_at)
         VALUES (:email, 'QA Artist', :hash, 'admin', 'active', UTC_TIMESTAMP())",
        ['email' => 'artistqa@example.invalid', 'hash' => $hash]);
    qa_assert($person1 > 0 && $person2 > $person1 && $artist > $person2,
        'three_synthetic_accounts');

    $request1 = qa_write($db,
        "INSERT INTO dmo_commission_requests
         (public_ref, customer_id, subject_type, brief, preferred_contact, policy_version, status)
         VALUES (:ref, :customer, 'portrait', :brief, 'onsite', :policy, 'submitted')",
        ['ref' => qa_reference($person1, 'one'), 'customer' => $person1,
         'brief' => str_repeat('Test artwork description. ', 3), 'policy' => 'qa-policy-v1']);
    $request2 = qa_write($db,
        "INSERT INTO dmo_commission_requests
         (public_ref, customer_id, subject_type, brief, preferred_contact, policy_version, status)
         VALUES (:ref, :customer, 'portrait', :brief, 'onsite', :policy, 'submitted')",
        ['ref' => qa_reference($person2, 'two'), 'customer' => $person2,
         'brief' => str_repeat('Separate private drawing. ', 3), 'policy' => 'qa-policy-v1']);
    qa_assert($request1 > 0 && $request2 > $request1, 'isolated_requests_created');

    $conversation1 = qa_write($db,
        "INSERT INTO dmo_conversations (request_id) VALUES (:id)",
        ['id' => $request1]);
    qa_write($db, "INSERT INTO dmo_messages
        (conversation_id, sender_id, body, is_internal)
        VALUES (:cid,:sender,'PRIVATE QA NOTE',1)",
        ['cid' => $conversation1,'sender' => $artist]);
    qa_write($db, "INSERT INTO dmo_messages
        (conversation_id, sender_id, body, is_internal)
        VALUES (:cid,:sender,'Visible customer message',0)",
        ['cid' => $conversation1,'sender' => $person1]);

    $draft = qa_write($db,
        "INSERT INTO dmo_quotes
         (request_id, version_number, currency, total_minor, scope_description, revision_allowance, status)
         VALUES (:request,1,'CAD',1500,'Synthetic draft – do not send',1,'draft')",
        ['request' => $request1]);
    $sent = qa_write($db,
        "INSERT INTO dmo_quotes
         (request_id, version_number, currency, total_minor, scope_description,
          revision_allowance, status, sent_at, validity_ends_at)
         VALUES (:request,2,'CAD',2600,'Synthetic private quote',2,'sent',
          UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY))",
        ['request' => $request1]);
    $other = qa_write($db,
        "INSERT INTO dmo_quotes
         (request_id, version_number, currency, total_minor, scope_description,
          revision_allowance, status, sent_at, validity_ends_at)
         VALUES (:request,1,'USD',4700,'Second customer quote',1,'sent',
          UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY))",
        ['request' => $request2]);
    foreach ([[$draft,1500],[$sent,2600],[$other,4700]] as [$quote,$amount]) {
        qa_write($db,
            "INSERT INTO dmo_quote_lines (quote_id, description, amount_minor, sort_order)
             VALUES (:quote,'Synthetic artwork line',:amount,0)",
            ['quote' => $quote, 'amount' => $amount]);
    }

    $customer1Quotes = dmo_quote_customer_rows($db, $request1, 'qa-policy-v1');
    $customer2Quotes = dmo_quote_customer_rows($db, $request2, 'qa-policy-v1');
    qa_assert(count($customer1Quotes) === 1 &&
        $customer1Quotes[0]['id'] === $sent &&
        $customer1Quotes[0]['total_minor'] === 2600 &&
        count($customer1Quotes[0]['lines']) === 1,
        'draft_hidden_and_quote_itemized');
    qa_assert(count($customer2Quotes) === 1 &&
        $customer2Quotes[0]['id'] === $other,
        'quote_isolated_by_request');
    qa_assert($customer1Quotes[0]['can_respond'] === false,
        'quote_gate_closed_without_approved_policies');

    $customer1Messages = $db->prepare(
        'SELECT m.body FROM dmo_conversations c JOIN dmo_messages m
         ON m.conversation_id=c.id WHERE c.request_id=:id AND m.is_internal=0');
    $customer1Messages->execute(['id' => $request1]);
    qa_assert($customer1Messages->fetchAll(PDO::FETCH_COLUMN) ===
        ['Visible customer message'], 'private_artist_note_excluded');

    $db->prepare("UPDATE dmo_quotes SET status='accepted',
        accepted_at=UTC_TIMESTAMP() WHERE id=:id")->execute(['id' => $sent]);
    $order = qa_write($db,
        "INSERT INTO dmo_orders (request_id, accepted_quote_id, status, payment_status)
         VALUES (:request,:quote,'awaiting_arrangement','unverified')",
        ['request' => $request1,'quote' => $sent]);
    $view1 = dmo_orders_customer_rows($db, $request1);
    $view2 = dmo_orders_customer_rows($db, $request2);
    qa_assert(count($view1) === 1 &&
        $view1[0]['id'] === $order &&
        $view1[0]['payment_status'] === 'unverified' &&
        $view1[0]['total_minor'] === 2600 &&
        $view2 === [], 'unpaid_order_isolation');
    qa_assert((int) $db->query('SELECT COUNT(*) FROM dmo_payments')->fetchColumn() === 0,
        'zero_payment_records');
    $db->rollBack();
    qa_assert((int) $db->query('SELECT COUNT(*) FROM dmo_users')->fetchColumn() === 0,
        'all_synthetic_fixtures_rolled_back');
    echo "DISPOSABLE_INTEGRATION=PASS\n";
} catch (Throwable $error) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "DISPOSABLE_INTEGRATION=FAIL (" .
        get_class($error) . ": " . $error->getMessage() . ")\n");
    exit(1);
}
