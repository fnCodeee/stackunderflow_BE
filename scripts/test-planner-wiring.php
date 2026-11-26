<?php

declare(strict_types=1);

/**
 * scripts/test-planner-wiring.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Endpoint/model test untuk planner wiring (2026-11-26): field baru task
 * (start_date, icon, blocks_json, pencilled), job role per anggota,
 * geser follower ikut menggeser start_date, dan daftar planner saya.
 *
 * Cara pakai (dari root repo backend, setelah migrasi 20261126 diterapkan):
 *   php scripts/test-planner-wiring.php
 *
 * Membuat 2 user + 1 collab test lalu membersihkannya lagi (finally),
 * jadi tidak ada data test yang tertinggal di DB dev. Keluar 0 bila
 * semua assertion lolos, 1 bila ada yang gagal.
 * ─────────────────────────────────────────────────────────────────────────────
 */

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/models/PlannerModel.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $cond): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "PASS: $label" . PHP_EOL;
    } else {
        $failed++;
        echo "FAIL: $label" . PHP_EOL;
    }
}

function uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return implode('-', [
        substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4),
        substr($hex, 16, 4), substr($hex, 20, 12),
    ]);
}

$suffix = substr(bin2hex(random_bytes(3)), 0, 6);
$ownerId = uuid();
$memberId = uuid();
$collabId = uuid();
$taskAId = null;
$taskBId = null;

$db = getDB();

try {
    // ── Setup: 2 user test + 1 collab test + member + planner aktif ──
    $hash = password_hash('wiring-test', PASSWORD_BCRYPT);
    $stmt = $db->prepare("INSERT INTO users (id, username, password, email, is_active, created_at) VALUES (:id, :u, :p, :e, 1, NOW())");
    $stmt->execute([':id' => $ownerId, ':u' => "wiring-owner-$suffix", ':p' => $hash, ':e' => "wiring-owner-$suffix@example.test"]);
    $stmt->execute([':id' => $memberId, ':u' => "wiring-member-$suffix", ':p' => $hash, ':e' => "wiring-member-$suffix@example.test"]);
    $stmt = $db->prepare("INSERT INTO collaborations (id, user_id, title, description, required_member, communication_url, status) VALUES (:id, :uid, 'Wiring test', 'collab test wiring planner', 1, NULL, 'open')");
    $stmt->execute([':id' => $collabId, ':uid' => $ownerId]);
    $stmt = $db->prepare("INSERT INTO user_collaboration (id, user_id, collaboration_id) VALUES (:id, :uid, :cid)");
    $stmt->execute([':id' => uuid(), ':uid' => $memberId, ':cid' => $collabId]);
    PlannerModel::setEnabled($collabId, true);
    check('planner enabled', PlannerModel::isEnabled($collabId));

    // ── 1. Task dengan field baru round-trip ──
    $blocks = [
        ['id' => 'b1', 'type' => 'text', 'text' => 'Catatan wiring', 'level' => 0],
        ['id' => 'b2', 'type' => 'todo', 'text' => 'Langkah pertama', 'checked' => false, 'level' => 0],
    ];
    $taskA = PlannerModel::createTask([
        'collab_id' => $collabId,
        'section_id' => null,
        'title' => 'Tugas leader',
        'assignee_id' => $ownerId,
        'created_by' => $ownerId,
        'due_date' => '2026-12-10',
        'start_date' => '2026-12-08',
        'icon' => 'edit',
        'blocks_json' => json_encode($blocks),
        'pencilled' => 1,
        'follows_task_id' => null,
        'gap_days' => null,
    ]);
    $taskAId = (string) $taskA['id'];
    $freshA = PlannerModel::findTask($taskAId);
    check('start_date tersimpan', ($freshA['start_date'] ?? null) === '2026-12-08');
    check('due_date tersimpan', ($freshA['due_date'] ?? null) === '2026-12-10');
    check('icon tersimpan', ($freshA['icon'] ?? null) === 'edit');
    check('pencilled tersimpan', (int) ($freshA['pencilled'] ?? 0) === 1);
    $decoded = json_decode((string) ($freshA['blocks_json'] ?? ''), true);
    check('blocks_json round-trip', is_array($decoded) && count($decoded) === 2 && ($decoded[0]['text'] ?? '') === 'Catatan wiring');

    // ── 2. Follower: gap dihitung dari due leader ──
    $taskB = PlannerModel::createTask([
        'collab_id' => $collabId,
        'section_id' => null,
        'title' => 'Tugas follower',
        'assignee_id' => $memberId,
        'created_by' => $ownerId,
        'due_date' => '2026-12-12',
        'start_date' => '2026-12-11',
        'icon' => null,
        'blocks_json' => null,
        'pencilled' => 0,
        'follows_task_id' => $taskAId,
        'gap_days' => PlannerModel::computeGap('2026-12-12', '2026-12-10'),
    ]);
    $taskBId = (string) $taskB['id'];
    $freshB = PlannerModel::findTask($taskBId);
    check('gap follower = 2 hari', (int) ($freshB['gap_days'] ?? -1) === 2);

    // ── 3. Geser leader +3 hari: follower ikut, due + start, gap tetap ──
    PlannerModel::setDueDate($taskAId, '2026-12-13');
    PlannerModel::setStartDate($taskAId, '2026-12-11');
    $shifted = PlannerModel::shiftWithFollowers($taskAId, 3);
    $movedB = PlannerModel::findTask($taskBId);
    check('follower ikut bergeser', in_array($taskBId, $shifted, true));
    check('follower due +3 hari', ($movedB['due_date'] ?? null) === '2026-12-15');
    check('follower start +3 hari', ($movedB['start_date'] ?? null) === '2026-12-14');
    check('gap follower tetap 2 hari', (int) ($movedB['gap_days'] ?? -1) === 2);

    // ── 4. Job role per anggota ──
    PlannerModel::setJobRole($collabId, $memberId, 'Backend');
    $roles = PlannerModel::jobRolesMap($collabId);
    check('job role tersimpan', ($roles[$memberId] ?? null) === 'Backend');
    $members = PlannerModel::members($collabId, $ownerId);
    $memberRow = null;
    foreach ($members as $m) {
        if ((string) $m['id'] === $memberId) {
            $memberRow = $m;
        }
    }
    check('members() membawa job_role', $memberRow !== null && ($memberRow['job_role'] ?? null) === 'Backend');
    // Owner juga anggota user_collaboration; members() tidak boleh
    // mengembalikannya dua kali (dulu UNION ALL menduplikasi).
    $ownerCount = 0;
    foreach ($members as $m) {
        if ((string) $m['id'] === $ownerId) {
            $ownerCount++;
        }
    }
    check('members() owner hanya sekali', $ownerCount === 1);
    PlannerModel::setJobRole($collabId, $memberId, '');
    check('job role bisa dihapus', (PlannerModel::jobRolesMap($collabId)[$memberId] ?? null) === null);

    // ── 5. Daftar planner saya ──
    $mine = PlannerModel::myPlanners($memberId);
    $found = null;
    foreach ($mine as $row) {
        if ((string) $row['id'] === $collabId) {
            $found = $row;
        }
    }
    check('myPlanners memuat collab test', $found !== null);
    check('myPlanners openCount = 2', $found !== null && (int) ($found['open_count'] ?? -1) === 2);
} finally {
    // ── Cleanup: hapus semua baris test ──
    if ($taskAId !== null || $taskBId !== null) {
        $ids = array_values(array_filter([$taskAId, $taskBId]));
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("DELETE FROM collab_tasks WHERE id IN ($in)");
        $stmt->execute($ids);
    }
    $db->prepare("DELETE FROM collab_planner_job_roles WHERE collab_id = ?")->execute([$collabId]);
    $db->prepare("DELETE FROM collab_planner_roles WHERE collab_id = ?")->execute([$collabId]);
    $db->prepare("DELETE FROM user_collaboration WHERE collaboration_id = ?")->execute([$collabId]);
    $db->prepare("DELETE FROM collaborations WHERE id = ?")->execute([$collabId]);
    $db->prepare("DELETE FROM users WHERE id IN (?, ?)")->execute([$ownerId, $memberId]);
}

echo PHP_EOL . "Hasil: $passed lolos, $failed gagal." . PHP_EOL;
exit($failed === 0 ? 0 : 1);
