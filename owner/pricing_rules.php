<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

$errors = [];
$days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$defaultSlots = [
    ['name' => 'Morning', 'start' => '06:00', 'end' => '12:00'],
    ['name' => 'Afternoon', 'start' => '12:00', 'end' => '18:00'],
    ['name' => 'Evening / Maghrib', 'start' => '18:00', 'end' => '20:00'],
    ['name' => 'Night Peak', 'start' => '20:00', 'end' => '23:00'],
    ['name' => 'Late Night', 'start' => '23:00', 'end' => '03:00'],
    ['name' => 'Before Sunrise', 'start' => '03:00', 'end' => '06:00'],
];

$turfsStmt = $pdo->prepare(
    'SELECT t.id, t.name, t.hourly_price, f.name AS facility_name
     FROM turfs t JOIN facilities f ON f.id = t.facility_id
     WHERE f.owner_id = ? AND f.deleted_at IS NULL AND t.deleted_at IS NULL
     ORDER BY f.name, t.name'
);
$turfsStmt->execute([$ownerId]);
$turfs = $turfsStmt->fetchAll(PDO::FETCH_ASSOC);
$turfIds = array_map(static fn($t) => (int)$t['id'], $turfs);
$selectedTurf = (int)($_POST['turf_id'] ?? $_GET['turf_id'] ?? ($turfIds[0] ?? 0));
if (!in_array($selectedTurf, $turfIds, true)) $selectedTurf = $turfIds[0] ?? 0;

// Existing rules are grouped by a common name. Each saved schedule row generates
// individual day-based rules, with overnight slots split at midnight.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tm_verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $postedTurf = filter_var($_POST['turf_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
    if (!in_array($postedTurf, $turfIds, true)) {
        $errors[] = 'Select one of your available turfs.';
    } elseif ($action === 'save_schedule') {
        $weekend = array_values(array_unique(array_map('intval', (array)($_POST['weekend_days'] ?? []))));
        $rawWeekend = (array)($_POST['weekend_days'] ?? []);
        if (!$rawWeekend || count($weekend) !== count($rawWeekend) || array_diff($weekend, range(0, 6))) {
            $errors[] = 'Choose valid weekend days.';
        }
        $names = (array)($_POST['slot_name'] ?? []);
        $starts = (array)($_POST['start_time'] ?? []);
        $ends = (array)($_POST['end_time'] ?? []);
        $weekdayPrices = (array)($_POST['weekday_price'] ?? []);
        $weekendPrices = (array)($_POST['weekend_price'] ?? []);
        $count = count($names);
        if ($count < 1 || $count > 20 || count($starts) !== $count || count($ends) !== $count || count($weekdayPrices) !== $count || count($weekendPrices) !== $count) {
            $errors[] = 'Submit between 1 and 20 complete time slots.';
        }
        $slots = [];
        if (!$errors) {
            for ($i = 0; $i < $count; $i++) {
                $name = trim((string)$names[$i]);
                $start = (string)$starts[$i];
                $end = (string)$ends[$i];
                $wp = trim((string)$weekdayPrices[$i]);
                $ep = trim((string)$weekendPrices[$i]);
                if ($name === '' || mb_strlen($name) > 60 || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end) || $start === $end) {
                    $errors[] = 'Slot ' . ($i + 1) . ': provide a name and different valid start/end times.';
                    continue;
                }
                foreach ([$wp, $ep] as $price) {
                    if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $price) || (float)$price > 99999999.99) {
                        $errors[] = 'Slot ' . ($i + 1) . ': prices must be between 0 and 99,999,999.99.';
                    }
                }
                $slots[] = compact('name', 'start', 'end', 'wp', 'ep');
            }
        }
        // Reject overlapping time slots, including slots that cross midnight.
        if (!$errors) {
            $occupied = array_fill(0, 1440, false);
            foreach ($slots as $slot) {
                [$sh, $sm] = array_map('intval', explode(':', $slot['start']));
                [$eh, $em] = array_map('intval', explode(':', $slot['end']));
                $a = $sh * 60 + $sm;
                $b = $eh * 60 + $em;
                $ranges = $b > $a ? [[$a, $b]] : [[$a, 1440], [0, $b]];
                foreach ($ranges as [$from, $to]) {
                    for ($minute = $from; $minute < $to; $minute++) {
                        if ($occupied[$minute]) {
                            $errors[] = 'Time slots must not overlap.';
                            break 3;
                        }
                        $occupied[$minute] = true;
                    }
                }
            }
        }
        if (!$errors) {
            try {
                $pdo->beginTransaction();
                // Replace only rules created by this schedule page. Manual rules are preserved.
                $delete = $pdo->prepare("DELETE FROM pricing_rules WHERE turf_id = ? AND name LIKE 'Schedule: %'");
                $delete->execute([$postedTurf]);
                $insert = $pdo->prepare('INSERT INTO pricing_rules (turf_id, name, day_of_week, start_time, end_time, price_per_hour, valid_from, valid_to, is_active) VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, 1)');
                foreach ($slots as $slot) {
                    for ($day = 0; $day <= 6; $day++) {
                        $price = in_array($day, $weekend, true) ? $slot['ep'] : $slot['wp'];
                        $label = 'Schedule: ' . $slot['name'];
                        if ($slot['end'] > $slot['start']) {
                            $insert->execute([$postedTurf, $label, $day, $slot['start'], $slot['end'], $price]);
                        } else {
                            $insert->execute([$postedTurf, $label, $day, $slot['start'], '23:59:59', $price]);
                            $nextDay = ($day + 1) % 7;
                            $insert->execute([$postedTurf, $label, $nextDay, '00:00:00', $slot['end'], $price]);
                        }
                    }
                }
                $pdo->commit();
                tm_flash('success', 'Pricing schedule saved successfully.');
                tm_redirect('pricing_rules.php?turf_id=' . $postedTurf);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('TurfMate pricing schedule: ' . $e->getMessage());
                $errors[] = 'Unable to save pricing schedule.';
            }
        }
    } elseif (in_array($action, ['toggle', 'remove'], true)) {
        $ruleId = filter_var($_POST['rule_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        if ($ruleId < 1) {
            $errors[] = 'Invalid rule.';
        } else {
            $sql = $action === 'toggle'
                ? 'UPDATE pricing_rules SET is_active = IF(is_active = 1, 0, 1) WHERE id = ? AND turf_id = ?'
                : 'DELETE FROM pricing_rules WHERE id = ? AND turf_id = ?';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$ruleId, $postedTurf]);
            tm_flash($stmt->rowCount() ? 'success' : 'error', $stmt->rowCount() ? 'Pricing rule updated.' : 'Rule not found.');
            tm_redirect('pricing_rules.php?turf_id=' . $postedTurf);
        }
    } else {
        $errors[] = 'Invalid action.';
    }
}

$rules = [];
if ($selectedTurf) {
    $stmt = $pdo->prepare('SELECT * FROM pricing_rules WHERE turf_id = ? ORDER BY day_of_week, start_time, id');
    $stmt->execute([$selectedTurf]);
    $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$weekendDefaults = [0, 5, 6];
tm_page_header('Advanced Pricing', 'turfs', $owner);
?>
<div class="intro-row">
    <div><h2>Advanced Pricing Rules</h2><p>Set weekday, weekend, and peak-hour prices for each turf.</p></div>
    <a class="btn btn-secondary" href="turfs.php">← Back to Turfs</a>
</div>
<?php if ($errors): ?>
<div class="alert alert-error"><strong>Please fix:</strong><ul><?php foreach ($errors as $error): ?><li><?= tm_e($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if (!$turfs): ?>
<section class="panel"><div class="empty-state"><strong>No available turfs</strong><p>Add a turf before configuring pricing.</p><a class="btn btn-primary" href="turfs.php?new=1">Add Turf</a></div></section>
<?php else: ?>
<section class="panel">
    <div class="panel-heading"><h2>Select Turf</h2></div>
    <form method="get" action="pricing_rules.php" class="form-grid">
        <label class="full">Turf
            <select name="turf_id" onchange="this.form.submit()">
                <?php foreach ($turfs as $turf): ?>
                    <option value="<?= (int)$turf['id'] ?>" <?= $selectedTurf === (int)$turf['id'] ? 'selected' : '' ?>><?= tm_e($turf['facility_name'] . ' — ' . $turf['name']) ?> (base: <?= tm_money($turf['hourly_price']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>
</section>
<section class="panel">
    <div class="panel-heading"><div><h2>Set Pricing Schedule</h2><p>Saving replaces previous schedule-generated rules for this turf. Other manual rules remain unchanged.</p></div></div>
    <form method="post" action="pricing_rules.php?turf_id=<?= $selectedTurf ?>">
        <input type="hidden" name="csrf" value="<?= tm_e(tm_csrf()) ?>">
        <input type="hidden" name="action" value="save_schedule">
        <input type="hidden" name="turf_id" value="<?= $selectedTurf ?>">
        <div style="padding:20px 24px">
            <strong style="font-size:13px">Weekend days</strong>
            <div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:12px">
                <?php foreach ($days as $dayNumber => $dayName): ?>
                <label style="font-size:13px"><input type="checkbox" name="weekend_days[]" value="<?= $dayNumber ?>" <?= in_array($dayNumber, $weekendDefaults, true) ? 'checked' : '' ?>> <?= tm_e($dayName) ?></label>
                <?php endforeach; ?>
            </div>
            <p class="subline">Default weekend: Friday, Saturday, Sunday. Unchecked days use weekday prices.</p>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Time Slot</th><th>Start</th><th>End</th><th>Weekday Price (PKR/hr)</th><th>Weekend Price (PKR/hr)</th></tr></thead>
                <tbody>
                    <?php foreach ($defaultSlots as $slot): ?>
                    <tr>
                        <td><input name="slot_name[]" maxlength="60" required value="<?= tm_e($slot['name']) ?>" style="width:155px;padding:9px;border:1px solid #d6dbe1;border-radius:8px"></td>
                        <td><input type="time" name="start_time[]" required value="<?= $slot['start'] ?>"></td>
                        <td><input type="time" name="end_time[]" required value="<?= $slot['end'] ?>"></td>
                        <td><input type="number" name="weekday_price[]" min="0" max="99999999.99" step="0.01" required value="1500" style="width:130px"></td>
                        <td><input type="number" name="weekend_price[]" min="0" max="99999999.99" step="0.01" required value="2000" style="width:130px"></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="padding:20px 24px"><button class="btn btn-primary" type="submit" onclick="return confirm('Save and replace the previous schedule for this turf?')">Save Pricing Schedule</button></div>
    </form>
</section>
<section class="panel">
    <div class="panel-heading"><div><h2>Saved Pricing Rules <span class="count-tag"><?= count($rules) ?></span></h2><p>Each time slot is stored as individual day rules. Overnight slots create two segments.</p></div></div>
    <?php if (!$rules): ?>
        <div class="empty-state"><strong>No pricing rules yet</strong><p>Save a schedule to generate rules.</p></div>
    <?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><th>Rule</th><th>Day</th><th>Time</th><th>Price/hour</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rules as $rule): ?>
                <tr>
                    <td><?= tm_e($rule['name']) ?></td>
                    <td><?= $rule['day_of_week'] === null ? 'Any day' : tm_e($days[(int)$rule['day_of_week']] ?? 'Unknown') ?></td>
                    <td><?= tm_e(substr($rule['start_time'], 0, 5) . ' – ' . substr($rule['end_time'], 0, 5)) ?></td>
                    <td><?= tm_money($rule['price_per_hour']) ?></td>
                    <td><span class="pill <?= (int)$rule['is_active'] ? 'status-active' : 'status-inactive' ?>"><?= (int)$rule['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td><div class="table-actions">
                        <form method="post" action="pricing_rules.php"><input type="hidden" name="csrf" value="<?= tm_e(tm_csrf()) ?>"><input type="hidden" name="turf_id" value="<?= $selectedTurf ?>"><input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>"><input type="hidden" name="action" value="toggle"><button class="btn btn-small btn-outline" type="submit"><?= (int)$rule['is_active'] ? 'Disable' : 'Enable' ?></button></form>
                        <form method="post" action="pricing_rules.php" onsubmit="return confirm('Permanently delete this pricing rule?')"><input type="hidden" name="csrf" value="<?= tm_e(tm_csrf()) ?>"><input type="hidden" name="turf_id" value="<?= $selectedTurf ?>"><input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>"><input type="hidden" name="action" value="remove"><button class="btn btn-small btn-outline" type="submit">Delete</button></form>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php tm_page_footer(); ?>
