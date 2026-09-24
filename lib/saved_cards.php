<?php
require_once __DIR__ . '/db.php';

/**
 * One saved_cards row per RC number (latest details + snapshot) with an
 * audit log (card_print_log) of every save / print / reprint. Cards with no
 * RC number can't be de-duplicated, so each of those stays its own record.
 */

/**
 * Creates the card for this RC number — or, if one exists, updates it to the
 * latest details — and appends an audit event ('saved' or 'printed').
 */
function save_card_with_event(array $record, string $action, string $by): array
{
    if (!in_array($action, ['saved', 'printed'], true)) {
        throw new InvalidArgumentException('Invalid action.');
    }

    $pdo = db();
    $rc = trim((string) ($record['rcNumber'] ?? ''));
    $fullName = (string) ($record['fullName'] ?? '');
    $designation = (string) ($record['designation'] ?? '');

    // Two people printing the same brand-new RC at the same instant would both
    // see "no row yet" and insert duplicates; serialise per RC number.
    $lock = null;
    if ($rc !== '') {
        $lock = 'officecard_rc_' . md5(mb_strtolower($rc));
        $pdo->prepare('SELECT GET_LOCK(:n, 5)')->execute(['n' => $lock]);
    }

    try {
        $pdo->beginTransaction();

        $id = null;
        if ($rc !== '') {
            $stmt = $pdo->prepare('SELECT id FROM saved_cards WHERE rc_number = :rc ORDER BY id LIMIT 1 FOR UPDATE');
            $stmt->execute(['rc' => $rc]);
            $found = $stmt->fetchColumn();
            $id = $found === false ? null : (int) $found;
        }

        $fields = [
            'full_name' => $fullName,
            'designation' => $designation,
            'photo_data_url' => $record['photoDataUrl'] ?? null,
            'front_snapshot' => $record['frontSnapshot'],
        ];

        if ($id !== null) {
            $stmt = $pdo->prepare(
                'UPDATE saved_cards SET full_name = :full_name, designation = :designation,
                        photo_data_url = :photo_data_url, front_snapshot = :front_snapshot
                 WHERE id = :id'
            );
            $stmt->execute($fields + ['id' => $id]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO saved_cards (full_name, rc_number, designation, photo_data_url, front_snapshot, created_by, created_at)
                 VALUES (:full_name, :rc_number, :designation, :photo_data_url, :front_snapshot, :created_by, NOW())'
            );
            $stmt->execute($fields + ['rc_number' => $rc, 'created_by' => $by]);
            $id = (int) $pdo->lastInsertId();
        }

        $pdo->prepare(
            'INSERT INTO card_print_log (card_id, action, performed_by, full_name, designation, performed_at)
             VALUES (:card_id, :action, :by, :full_name, :designation, NOW())'
        )->execute([
            'card_id' => $id,
            'action' => $action,
            'by' => $by,
            'full_name' => $fullName,
            'designation' => $designation,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        if ($lock !== null) {
            $pdo->prepare('SELECT RELEASE_LOCK(:n)')->execute(['n' => $lock]);
        }
    }

    return get_saved_card($id);
}

/** Appends an audit event for an existing card (used for reprints). */
function log_card_event(int $id, string $action, string $by): bool
{
    if ($action !== 'reprinted') {
        throw new InvalidArgumentException('Invalid action.');
    }

    $stmt = db()->prepare(
        'INSERT INTO card_print_log (card_id, action, performed_by, full_name, designation, performed_at)
         SELECT id, :action, :by, full_name, designation, NOW() FROM saved_cards WHERE id = :id'
    );
    $stmt->execute(['action' => $action, 'by' => $by, 'id' => $id]);

    return $stmt->rowCount() > 0;
}

/**
 * @return array{id:int,fullName:string,rcNumber:string,designation:string,photoDataUrl:?string,frontSnapshot:string,createdBy:string,createdByName:string,createdAt:int}|null
 */
function get_saved_card(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT sc.*, u.name AS created_by_name
         FROM saved_cards sc
         LEFT JOIN users u ON u.username COLLATE utf8mb4_unicode_ci = sc.created_by
         WHERE sc.id = :id'
    );
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row === false ? null : map_saved_card_row($row);
}

/**
 * One entry per card, most recently active first, without the (large)
 * photo_data_url column — the list only needs the snapshot thumbnail. Adds
 * how many times it has been printed and its latest audit event.
 */
function all_saved_cards(): array
{
    $rows = db()->query(
        "SELECT sc.id, sc.full_name, sc.rc_number, sc.designation, sc.front_snapshot,
                sc.created_by, sc.created_at,
                COALESCE(agg.print_count, 0) AS print_count,
                COALESCE(agg.event_count, 0) AS event_count,
                l.action AS last_action, l.performed_by AS last_by, l.performed_at AS last_at,
                u.name AS last_by_name
         FROM saved_cards sc
         LEFT JOIN (
             SELECT card_id, MAX(id) AS last_id, COUNT(*) AS event_count,
                    SUM(action IN ('printed','reprinted')) AS print_count
             FROM card_print_log GROUP BY card_id
         ) agg ON agg.card_id = sc.id
         LEFT JOIN card_print_log l ON l.id = agg.last_id
         LEFT JOIN users u ON u.username COLLATE utf8mb4_unicode_ci = l.performed_by
         ORDER BY COALESCE(l.performed_at, sc.created_at) DESC, sc.id DESC"
    )->fetchAll();

    return array_map(function ($row) {
        $card = map_saved_card_row($row);
        $card['printCount'] = (int) $row['print_count'];
        $card['eventCount'] = (int) $row['event_count'];
        $card['lastAction'] = $row['last_action'] ?? 'saved';
        $card['lastBy'] = $row['last_by'] ?? $row['created_by'];
        $card['lastByName'] = $row['last_by_name'] ?? $row['last_by'] ?? $row['created_by'];
        $card['lastAt'] = strtotime($row['last_at'] ?? $row['created_at']) * 1000;

        return $card;
    }, $rows);
}

/**
 * Audit log for one card, newest first.
 *
 * @return array<int, array{id:int,action:string,performedBy:string,performedByName:string,fullName:string,designation:string,performedAt:int}>
 */
function get_card_history(int $id): array
{
    $stmt = db()->prepare(
        'SELECT l.id, l.action, l.performed_by, l.full_name, l.designation, l.performed_at, u.name AS performed_by_name
         FROM card_print_log l
         LEFT JOIN users u ON u.username COLLATE utf8mb4_unicode_ci = l.performed_by
         WHERE l.card_id = :id
         ORDER BY l.id DESC'
    );
    $stmt->execute(['id' => $id]);

    return array_map(fn ($row) => [
        'id' => (int) $row['id'],
        'action' => $row['action'],
        'performedBy' => $row['performed_by'],
        'performedByName' => $row['performed_by_name'] ?? $row['performed_by'],
        'fullName' => $row['full_name'],
        'designation' => $row['designation'],
        'performedAt' => strtotime($row['performed_at']) * 1000,
    ], $stmt->fetchAll());
}

/**
 * Tiny fingerprint (card count + newest audit event) so clients can poll for
 * changes — new cards, reprints, deletions — without downloading every image.
 *
 * @return array{count:int,latestEventId:int}
 */
function saved_cards_summary(): array
{
    $row = db()->query(
        'SELECT (SELECT COUNT(*) FROM saved_cards) AS c,
                COALESCE((SELECT MAX(id) FROM card_print_log), 0) AS e'
    )->fetch();

    return ['count' => (int) $row['c'], 'latestEventId' => (int) $row['e']];
}

function delete_saved_card(int $id): void
{
    // card_print_log rows go with it (ON DELETE CASCADE).
    $stmt = db()->prepare('DELETE FROM saved_cards WHERE id = :id');
    $stmt->execute(['id' => $id]);
}

function map_saved_card_row(array $row): array
{
    $mapped = [
        'id' => (int) $row['id'],
        'fullName' => $row['full_name'],
        'rcNumber' => $row['rc_number'],
        'designation' => $row['designation'],
        'frontSnapshot' => $row['front_snapshot'],
        'createdBy' => $row['created_by'],
        'createdByName' => $row['created_by_name'] ?? $row['created_by'],
        'createdAt' => strtotime($row['created_at']) * 1000, // ms
    ];

    if (array_key_exists('photo_data_url', $row)) {
        $mapped['photoDataUrl'] = $row['photo_data_url'];
    }

    return $mapped;
}
