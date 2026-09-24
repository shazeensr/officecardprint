<?php
/**
 * Data migration for the "one card per RC number + audit log" model.
 * Safe to run on every deploy — it only touches cards that still need it:
 *
 *  - every card with no audit event yet gets one legacy 'printed' event
 *    (who/when it was originally saved), and
 *  - cards sharing an RC number are merged into the newest one, with the
 *    older cards' events carried over, then the older rows deleted.
 *
 * Cards created by the current code always have an event and can never
 * share an RC number, so after the first run this is a no-op.
 *
 * Usage: php deploy/migrate.php
 */
require_once __DIR__ . '/../lib/env.php';
load_env(__DIR__ . '/../.env');
require_once __DIR__ . '/../lib/db.php';

$pdo = db();
$pdo->beginTransaction();

try {
    $cards = $pdo->query(
        'SELECT id, rc_number, full_name, designation, created_by, created_at FROM saved_cards ORDER BY created_at, id'
    )->fetchAll();

    $hasEvents = array_flip($pdo->query('SELECT DISTINCT card_id FROM card_print_log')->fetchAll(PDO::FETCH_COLUMN));

    $groups = [];
    foreach ($cards as $card) {
        $rc = trim($card['rc_number']);
        // No RC number -> nothing to merge on; each such card is its own group.
        $key = $rc === '' ? 'blank-' . $card['id'] : mb_strtolower($rc);
        $groups[$key][] = $card;
    }

    $insertEvent = $pdo->prepare(
        "INSERT INTO card_print_log (card_id, action, performed_by, full_name, designation, performed_at)
         VALUES (:card_id, 'printed', :by, :full_name, :designation, :at)"
    );
    $moveEvents = $pdo->prepare('UPDATE card_print_log SET card_id = :to WHERE card_id = :from');
    $deleteCard = $pdo->prepare('DELETE FROM saved_cards WHERE id = :id');

    $legacyEvents = 0;
    $mergedCards = 0;

    foreach ($groups as $rows) {
        $canonical = end($rows); // newest (query is ordered oldest -> newest)

        foreach ($rows as $card) {
            $isCanonical = $card['id'] === $canonical['id'];

            if (isset($hasEvents[$card['id']])) {
                if (!$isCanonical) {
                    $moveEvents->execute(['to' => $canonical['id'], 'from' => $card['id']]);
                }
            } else {
                $insertEvent->execute([
                    'card_id' => $canonical['id'],
                    'by' => $card['created_by'],
                    'full_name' => $card['full_name'],
                    'designation' => $card['designation'],
                    'at' => $card['created_at'],
                ]);
                $legacyEvents++;
            }

            if (!$isCanonical) {
                $deleteCard->execute(['id' => $card['id']]);
                $mergedCards++;
            }
        }
    }

    $pdo->commit();
    echo "migrate: {$legacyEvents} legacy event(s) added, {$mergedCards} duplicate card(s) merged\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'migrate failed: ' . $e->getMessage() . "\n");
    exit(1);
}
