<?php
require_once __DIR__ . '/db.php';

/**
 * @return array{id:int,fullName:string,rcNumber:string,designation:string,frontSnapshot:string,createdAt:int}
 */
function add_saved_card(array $record, string $createdBy): array
{
    $stmt = db()->prepare(
        'INSERT INTO saved_cards (full_name, rc_number, designation, photo_data_url, front_snapshot, created_by, created_at)
         VALUES (:full_name, :rc_number, :designation, :photo_data_url, :front_snapshot, :created_by, NOW())'
    );

    $stmt->execute([
        'full_name' => $record['fullName'] ?? '',
        'rc_number' => $record['rcNumber'] ?? '',
        'designation' => $record['designation'] ?? '',
        'photo_data_url' => $record['photoDataUrl'] ?? null,
        'front_snapshot' => $record['frontSnapshot'],
        'created_by' => $createdBy,
    ]);

    $id = (int) db()->lastInsertId();

    return get_saved_card($id);
}

/**
 * @return array{id:int,fullName:string,rcNumber:string,designation:string,photoDataUrl:?string,frontSnapshot:string,createdAt:int}|null
 */
function get_saved_card(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM saved_cards WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row === false ? null : map_saved_card_row($row);
}

/**
 * All saved cards, newest first, without the (large) photo_data_url column —
 * the list view only needs the snapshot thumbnail.
 *
 * @return array<int, array{id:int,fullName:string,rcNumber:string,designation:string,frontSnapshot:string,createdAt:int}>
 */
function all_saved_cards(): array
{
    $rows = db()->query(
        'SELECT id, full_name, rc_number, designation, front_snapshot, created_by, created_at
         FROM saved_cards ORDER BY created_at DESC'
    )->fetchAll();

    return array_map(fn ($row) => map_saved_card_row($row), $rows);
}

function delete_saved_card(int $id): void
{
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
        'createdAt' => strtotime($row['created_at']) * 1000, // ms, to match the old IndexedDB record shape
    ];

    if (array_key_exists('photo_data_url', $row)) {
        $mapped['photoDataUrl'] = $row['photo_data_url'];
    }

    return $mapped;
}
