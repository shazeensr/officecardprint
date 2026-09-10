<?php
require_once __DIR__ . '/env.php';

class HrDirectoryException extends RuntimeException {}

/**
 * Looks up an employee by RC number in the internal HR directory
 * (NTLM-authenticated intranet ASP app). Returns null if there's no match.
 *
 * @return array{rc:string,name:string,designation:string}|null
 */
function lookup_hr_by_rc(string $rc): ?array
{
    $rc = trim($rc);
    if ($rc === '' || !ctype_digit($rc)) {
        return null;
    }

    $url = env('HR_DIRECTORY_URL');
    $user = env('HR_DIRECTORY_USER');
    $pass = env('HR_DIRECTORY_PASSWORD');

    if (!$url || !$user || $pass === null || $pass === '') {
        throw new HrDirectoryException('HR directory is not configured. Check your .env file.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['s' => $rc, 'Find' => 'Find']),
        CURLOPT_HTTPAUTH => CURLAUTH_NTLM,
        CURLOPT_USERPWD => "{$user}:{$pass}",
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 8,
    ]);

    $html = curl_exec($ch);
    if ($html === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new HrDirectoryException("Could not reach the HR directory: {$err}");
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) {
        throw new HrDirectoryException("HR directory returned HTTP {$status}.");
    }

    return parse_hr_directory_result($html, $rc);
}

/**
 * @return array{rc:string,name:string,designation:string}|null
 */
function parse_hr_directory_result(string $html, string $rc): ?array
{
    if (!preg_match("/<table[^>]*class='gridtbl'[^>]*>(.*?)<\/table>/is", $html, $tableMatch)) {
        return null;
    }

    if (!preg_match_all('/<tr>(.*?)<\/tr>/is', $tableMatch[1], $rowMatches)) {
        return null;
    }

    foreach ($rowMatches[1] as $row) {
        if (stripos($row, '<th') !== false) {
            continue; // header row
        }

        if (!preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $row, $cellMatches)) {
            continue;
        }

        $cells = array_map(
            fn ($c) => trim(html_entity_decode(strip_tags($c), ENT_QUOTES)),
            $cellMatches[1]
        );

        // Columns: #, RCN, Name, Post, Section, ptELE, MTELE, EXTNO, Task
        if (count($cells) < 4) {
            continue;
        }

        [, $rcn, $name, $post] = $cells;
        if ($rcn === $rc) {
            return [
                'rc' => $rcn,
                'name' => strip_honorific($name),
                'designation' => strip_grade_suffix($post),
            ];
        }
    }

    return null;
}

/**
 * Strips a leading honorific (Mr., Mrs., Ms., Miss, Dr., etc.) from a name
 * as returned by the HR directory, e.g. "Mr. Ahmed Shazeen" -> "Ahmed Shazeen".
 */
function strip_honorific(string $name): string
{
    return preg_replace('/^(mr|mrs|ms|miss|mx|dr)\.*\s+/i', '', trim($name));
}

/**
 * Strips a trailing grade code from a designation as returned by the HR
 * directory, e.g. "Deputy Immigration Officer 4 (2)" -> "Deputy Immigration
 * Officer". Grade codes are always a run of digits/parentheses at the end.
 */
function strip_grade_suffix(string $designation): string
{
    return trim(preg_replace('/\s+[\d()]+(?:\s+[\d()]+)*\s*$/', '', trim($designation)));
}
