<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$facilities = staffAssignments($db, $staffId);
$turfsQuery = $db->prepare(<<<'SQL'
    SELECT t.name, t.sport_type, t.hourly_price, t.capacity, t.status,
           f.name AS facility_name, t.id AS turf_id
    FROM turfs t
    JOIN facilities f ON f.id = t.facility_id
    JOIN staff_assignments sa ON sa.facility_id = f.id
    JOIN users owner ON owner.id = f.owner_id
    WHERE sa.staff_id = ? AND sa.status = 'active'
      AND f.deleted_at IS NULL AND f.status = 'active'
      AND f.approval_status = 'approved' AND owner.status = 'active'
      AND t.deleted_at IS NULL
    ORDER BY f.name, t.name
SQL);
$turfsQuery->execute([$staffId]);
$turfs = $turfsQuery->fetchAll(PDO::FETCH_ASSOC);

$rulesQuery = $db->prepare(<<<'SQL'
    SELECT p.name AS rule_name, p.day_of_week, p.start_time, p.end_time,
           p.price_per_hour, p.valid_from, p.valid_to, p.is_active,
           t.name AS turf_name, f.name AS facility_name
    FROM pricing_rules p
    JOIN turfs t ON t.id = p.turf_id
    JOIN facilities f ON f.id = t.facility_id
    JOIN staff_assignments sa ON sa.facility_id = f.id
    JOIN users owner ON owner.id = f.owner_id
    WHERE sa.staff_id = ? AND sa.status = 'active'
      AND f.deleted_at IS NULL AND f.status = 'active'
      AND f.approval_status = 'approved' AND owner.status = 'active'
      AND t.deleted_at IS NULL
    ORDER BY f.name, t.name, p.name
SQL);
$rulesQuery->execute([$staffId]);
$rules = $rulesQuery->fetchAll(PDO::FETCH_ASSOC);
staffLayoutStart('Facilities & Pricing', 'facilities');
?>
<section class="heading"><div><p class="eyebrow">READ ONLY</p><h1>Facilities, Turfs & Pricing</h1><p>Only your assigned, approved facilities are shown.</p></div></section>
<section class="card"><h2>Assigned facilities</h2><div class="table-scroll"><table><thead><tr><th>Facility</th><th>City</th></tr></thead><tbody>
<?php foreach ($facilities as $f): ?><tr><td><?= staffEscape($f['name']) ?></td><td><?= staffEscape($f['city']) ?></td></tr><?php endforeach; ?>
<?php if (!$facilities): ?><tr><td colspan="2">No approved facility assignments.</td></tr><?php endif; ?>
</tbody></table></div></section>
<section class="card"><h2>Turfs</h2><div class="table-scroll"><table><thead><tr><th>Facility</th><th>Turf</th><th>Sport</th><th>Base hourly price</th><th>Capacity</th><th>Status</th></tr></thead><tbody>
<?php foreach ($turfs as $t): ?><tr><td><?= staffEscape($t['facility_name']) ?></td><td><?= staffEscape($t['name']) ?></td><td><?= staffEscape($t['sport_type']) ?></td><td><?= staffEscape($t['hourly_price']) ?></td><td><?= staffEscape($t['capacity']) ?></td><td><?= staffEscape($t['status']) ?></td></tr><?php endforeach; ?>
<?php if (!$turfs): ?><tr><td colspan="6">No turfs found.</td></tr><?php endif; ?>
</tbody></table></div></section>
<section class="card"><h2>Pricing rules</h2><p class="muted">These are pricing rules, not final booking quotes.</p><div class="table-scroll"><table><thead><tr><th>Facility / Turf</th><th>Rule</th><th>Day (0=Sun)</th><th>Time</th><th>Price / hour</th><th>Validity</th><th>Active</th></tr></thead><tbody>
<?php foreach ($rules as $r): ?><tr><td><?= staffEscape($r['facility_name']) ?> / <?= staffEscape($r['turf_name']) ?></td><td><?= staffEscape($r['rule_name']) ?></td><td><?= $r['day_of_week'] === null ? 'Any' : staffEscape($r['day_of_week']) ?></td><td><?= staffEscape($r['start_time']) ?>–<?= staffEscape($r['end_time']) ?></td><td><?= staffEscape($r['price_per_hour']) ?></td><td><?= staffEscape($r['valid_from'] ?? 'Any') ?> – <?= staffEscape($r['valid_to'] ?? 'Any') ?></td><td><?= $r['is_active'] ? 'Yes' : 'No' ?></td></tr><?php endforeach; ?>
<?php if (!$rules): ?><tr><td colspan="7">No pricing rules found.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php staffLayoutEnd(); ?>
