<?php
declare(strict_types=1);

require_once __DIR__ . '/booking_engine.php';
requireRole(['customer']);

$db = bdb();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$_SESSION['tm_booking_csrf'] ??= bin2hex(random_bytes(32));
$error = '';
$success = '';
$turf = null;

try {
    $turf = tmTurf($db, $id);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $turf) {
    try {
        if (!hash_equals($_SESSION['tm_booking_csrf'], (string) ($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Session expired. Refresh the page.');
        }
        $result = tmBook(
            $db,
            $id,
            (string) ($_POST['date'] ?? ''),
            (string) ($_POST['start'] ?? ''),
            (string) ($_POST['end'] ?? ''),
            (string) ($_POST['phone'] ?? '')
        );
        $success = 'Booking #' . $result['group_id'] . ' submitted for approval. Total: Rs. ' . number_format($result['quote']['total'], 2);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

bookingHead('Book Turf');
?>
<main class="wrap booking-detail-page">
    <a class="btn btn-ghost booking-back" href="turfs.php">← Explore Turfs</a>

    <?php if (!$turf): ?>
        <div class="alert" role="alert"><?= esc($error) ?></div>
    <?php else: ?>
        <div class="booking-detail-grid">
            <section class="booking-venue-card" aria-label="Turf information">
                <img class="booking-venue-photo"
                     src="../assets/images/<?= esc(sportImage((string) $turf['sport'])) ?>"
                     alt="<?= esc($turf['name']) ?>">
                <div class="booking-venue-body">
                    <span class="chip"><?= esc(ucfirst((string) $turf['sport'])) ?></span>
                    <h1><?= esc($turf['name']) ?></h1>
                    <p class="booking-location">📍 <?= esc($turf['location'] ?: $turf['facility']) ?></p>
                    <div class="booking-venue-divider"></div>
                    <h2>About this turf</h2>
                    <p class="muted"><?= esc($turf['description'] ?: 'Play your next game at this sports venue.') ?></p>
                    <div class="booking-base-rate">
                        <span class="booking-rate-icon">◇</span>
                        <div>
                            <small>Base hourly rate</small>
                            <strong>Rs. <?= number_format((float) $turf['price'], 2) ?> / hour</strong>
                            <p>Final price depends on the selected date and applicable pricing rules.</p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="booking-form-card" aria-label="Book this turf">
                <div class="booking-form-heading">
                    <span class="booking-heading-icon" aria-hidden="true">▦</span>
                    <div>
                        <h2>Book this Turf</h2>
                        <p>Choose a date and booking method. See availability and price before you book.</p>
                    </div>
                </div>

                <?php if ($error): ?>
                    <div class="alert" role="alert"><?= esc($error) ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="success" role="status"><?= esc($success) ?></div>
                    <a class="btn" href="my-bookings.php">View My Bookings</a>
                <?php endif; ?>

                <form id="booking-form" method="post">
                    <input type="hidden" name="csrf" value="<?= esc($_SESSION['tm_booking_csrf']) ?>">
                    <input type="hidden" name="start" id="booking-start" required>
                    <input type="hidden" name="end" id="booking-end" required>

                    <label class="booking-date-label" for="booking-date">Booking date</label>
                    <input id="booking-date" name="date" type="date" min="<?= date('Y-m-d') ?>" required>

                    <div class="booking-methods" role="tablist" aria-label="Booking method">
                        <button type="button" id="quick-tab" class="booking-method active" role="tab" aria-controls="quick-panel" aria-selected="true">⚡ Quick Booking <small>Select up to 12 consecutive hours</small></button>
                        <button type="button" id="custom-tab" class="booking-method" role="tab" aria-controls="custom-panel" aria-selected="false">◷ Custom Booking <small>Select your own times</small></button>
                    </div>

                    <div id="quick-panel" class="booking-method-panel" role="tabpanel" aria-labelledby="quick-tab">
                        <div class="booking-slots-header">
                            <h3>Available Time Slots</h3>
                            <div class="booking-legend">
                                <span><i class="legend-green"></i> Available</span>
                                <span><i class="legend-orange"></i> Pending</span>
                                <span><i class="legend-red"></i> Booked</span>
                            </div>
                        </div>
                        <div id="booking-slots" class="booking-slots" aria-live="polite">
                            <p class="booking-slots-hint">Select a date above to display live availability for this turf.</p>
                        </div>
                        <p class="booking-slots-note">Choose an available hour, then another to extend your booking. You can select 1–12 consecutive hours. For half-hour or other custom times, use Custom Booking.</p>
                    </div>

                    <div id="custom-panel" class="booking-method-panel" role="tabpanel" aria-labelledby="custom-tab" hidden>
                        <p class="booking-custom-intro">Choose your preferred start and end times. The system will check for conflicting bookings before submitting.</p>
                        <div class="two">
                            <div>
                                <label for="custom-start">Start time</label>
                                <input id="custom-start" type="time" step="60">
                            </div>
                            <div>
                                <label for="custom-end">End time</label>
                                <input id="custom-end" type="time" step="60">
                            </div>
                        </div>
                        <p class="booking-slots-note">For an overnight booking, choose an end time earlier than the start time.</p>
                    </div>

                    <div id="booking-selection" class="booking-selection" hidden></div>

                    <div id="quote" class="quote booking-price-box" aria-live="polite">
                        Choose a date and time to see the exact price.
                    </div>

                    <label class="booking-phone-label" for="booking-phone">Contact phone</label>
                    <input id="booking-phone" name="phone" type="tel" maxlength="30" placeholder="03xx-xxxxxxx" required autocomplete="tel">

                    <button id="book-btn" class="btn wide booking-submit" type="submit" disabled>✓ Confirm Booking Request</button>
                    <p class="booking-request-note">Your request will be pending until the owner or staff confirms it.</p>
                </form>

                <div class="action-row booking-secondary-actions">
                    <a class="btn btn-outline" href="turfs.php">Explore Turfs</a>
                    <a class="btn btn-outline" href="my-bookings.php">View My Bookings</a>
                </div>
            </section>
        </div>

        <script>
        (() => {
            const turfId = <?= json_encode((int) $id) ?>;
            const form = document.getElementById('booking-form');
            const dateInput = document.getElementById('booking-date');
            const startHidden = document.getElementById('booking-start');
            const endHidden = document.getElementById('booking-end');
            const startCustom = document.getElementById('custom-start');
            const endCustom = document.getElementById('custom-end');
            const quickTab = document.getElementById('quick-tab');
            const customTab = document.getElementById('custom-tab');
            const quickPanel = document.getElementById('quick-panel');
            const customPanel = document.getElementById('custom-panel');
            const slotsEl = document.getElementById('booking-slots');
            const quoteEl = document.getElementById('quote');
            const selectionEl = document.getElementById('booking-selection');
            const bookBtn = document.getElementById('book-btn');
            let mode = 'quick';
            let quoteSequence = 0;
            let slotsSequence = 0;
            let selectedRange = null;
            let availableSlots = [];

            const money = amount => 'Rs. ' + Number(amount).toLocaleString('en-PK', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });

            function clearPrice(message = 'Choose a date and time to see the exact price.') {
                quoteSequence++;
                bookBtn.disabled = true;
                quoteEl.textContent = message;
                selectionEl.hidden = true;
                selectionEl.textContent = '';
            }

            function updateTimes() {
                if (mode === 'quick' && selectedRange) {
                    startHidden.value = availableSlots[selectedRange[0]].start;
                    endHidden.value = availableSlots[selectedRange[1]].end;
                } else if (mode === 'custom') {
                    startHidden.value = startCustom.value;
                    endHidden.value = endCustom.value;
                } else {
                    startHidden.value = '';
                    endHidden.value = '';
                }
            }

            async function checkQuote() {
                updateTimes();
                const sequence = ++quoteSequence;
                bookBtn.disabled = true;
                selectionEl.hidden = true;
                if (!dateInput.value || !startHidden.value || !endHidden.value) {
                    quoteEl.textContent = 'Choose a date and time to see the exact price.';
                    return;
                }
                const hours = mode === 'quick' && selectedRange
                    ? selectedRange[1] - selectedRange[0] + 1
                    : null;
                selectionEl.textContent = `${dateInput.value}  •  ${startHidden.value} – ${endHidden.value}`
                    + (hours ? `  •  ${hours} hour${hours === 1 ? '' : 's'}` : '');
                selectionEl.hidden = false;
                quoteEl.textContent = 'Checking availability and calculating your price…';
                try {
                    const fd = new FormData(form);
                    fd.append('turf_id', turfId);
                    const response = await fetch('booking-quote.php', {
                        method: 'POST', body: fd, credentials: 'same-origin', cache: 'no-store'
                    });
                    const data = await response.json();
                    if (sequence !== quoteSequence) return;
                    if (!data.ok) throw new Error(data.error || 'Unable to check this booking.');
                    quoteEl.replaceChildren();
                    const total = document.createElement('strong');
                    total.textContent = 'Total: ' + money(data.quote.total);
                    quoteEl.append(total);
                    for (const item of data.quote.items) {
                        const line = document.createElement('div');
                        line.className = 'quote-line';
                        line.textContent = `${item.start} → ${item.end} | ${money(item.rate)}/hour | ${money(item.amount)} (${item.rule})`;
                        quoteEl.append(line);
                    }
                    bookBtn.disabled = false;
                } catch (error) {
                    if (sequence !== quoteSequence) return;
                    quoteEl.textContent = error.message;
                    bookBtn.disabled = true;
                }
            }

            function paintRange() {
                slotsEl.querySelectorAll('.booking-slot').forEach(button => {
                    const index = Number(button.dataset.index);
                    const selected = selectedRange !== null
                        && index >= selectedRange[0] && index <= selectedRange[1];
                    button.classList.toggle('selected', selected);
                    button.setAttribute('aria-pressed', String(selected));
                });
            }

            function resetSelectedSlot() {
                selectedRange = null;
                paintRange();
                updateTimes();
                clearPrice();
            }

            function selectQuickSlot(index) {
                const slot = availableSlots[index];
                if (!slot || slot.status !== 'available') return;

                if (selectedRange === null) {
                    selectedRange = [index, index];
                } else {
                    const [left, right] = selectedRange;
                    if (index >= left && index <= right) {
                        // Selecting an hour inside the range starts a fresh selection.
                        selectedRange = index === left && index === right ? null : [index, index];
                    } else {
                        const nextLeft = Math.min(left, index);
                        const nextRight = Math.max(right, index);
                        if (nextRight - nextLeft + 1 > 12) {
                            clearPrice('A booking can be at most 12 hours. Select a shorter range.');
                            selectedRange = null;
                        } else {
                            // Every intervening slot must be available and contiguous.
                            let contiguous = true;
                            for (let i = nextLeft; i <= nextRight; i++) {
                                if (availableSlots[i]?.status !== 'available') {
                                    contiguous = false;
                                    break;
                                }
                                if (i > nextLeft && availableSlots[i - 1].end !== availableSlots[i].start) {
                                    contiguous = false;
                                    break;
                                }
                            }
                            if (contiguous) {
                                selectedRange = [nextLeft, nextRight];
                            } else {
                                selectedRange = null;
                                clearPrice('Those hours are not consecutive or an hour between them is unavailable. Select another range.');
                            }
                        }
                    }
                }
                paintRange();
                if (selectedRange !== null) checkQuote();
                else {
                    updateTimes();
                    // Retain any helpful error from the rejected range.
                    if (!quoteEl.textContent.includes('not consecutive') && !quoteEl.textContent.includes('at most 12')) clearPrice();
                }
            }

            async function fetchSlots() {
                const sequence = ++slotsSequence;
                resetSelectedSlot();
                availableSlots = [];
                slotsEl.textContent = dateInput.value ? 'Loading available time slots…' : 'Select a date above to display live availability for this turf.';
                if (!dateInput.value) return;
                try {
                    const url = `turf-availability.php?turf_id=${encodeURIComponent(turfId)}&date=${encodeURIComponent(dateInput.value)}`;
                    const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
                    const data = await response.json();
                    if (sequence !== slotsSequence || dateInput.value !== new URL(url, location.href).searchParams.get('date')) return;
                    if (!data.ok) throw new Error(data.error || 'Could not load available slots.');
                    slotsEl.replaceChildren();
                    availableSlots = data.slots;
                    if (!data.slots.length) {
                        const notice = document.createElement('p');
                        notice.className = 'booking-slots-hint';
                        notice.textContent = data.message || 'No one-hour slots for this date. Try another date or Custom Booking.';
                        slotsEl.append(notice);
                        return;
                    }
                    for (const [index, slot] of data.slots.entries()) {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = `booking-slot ${slot.status}`;
                        button.dataset.index = String(index);
                        button.disabled = slot.status !== 'available';
                        button.setAttribute('aria-pressed', 'false');
                        const span = document.createElement('strong');
                        span.textContent = slot.label;
                        const badge = document.createElement('span');
                        badge.textContent = ({ available: 'Available', pending: 'Pending', booked: 'Booked', past: 'Past' })[slot.status] || slot.status;
                        button.append(span, badge);
                        if (slot.status === 'available') {
                            button.addEventListener('click', () => selectQuickSlot(index));
                        }
                        slotsEl.append(button);
                    }
                } catch (error) {
                    if (sequence !== slotsSequence) return;
                    slotsEl.textContent = 'Could not load time slots: ' + error.message + '. Check that turf-availability.php is installed and you are logged in.';
                }
            }

            function setMode(nextMode) {
                if (mode === nextMode) return;
                mode = nextMode;
                const quick = mode === 'quick';
                quickTab.classList.toggle('active', quick);
                customTab.classList.toggle('active', !quick);
                quickTab.setAttribute('aria-selected', String(quick));
                customTab.setAttribute('aria-selected', String(!quick));
                quickPanel.hidden = !quick;
                customPanel.hidden = quick;
                selectedRange = null;
                paintRange();
                updateTimes();
                clearPrice();
                if (!quick) checkQuote();
            }

            quickTab.addEventListener('click', () => setMode('quick'));
            customTab.addEventListener('click', () => setMode('custom'));
            dateInput.addEventListener('change', () => {
                fetchSlots();
                if (mode === 'custom') checkQuote();
            });
            startCustom.addEventListener('change', () => { if (mode === 'custom') checkQuote(); });
            endCustom.addEventListener('change', () => { if (mode === 'custom') checkQuote(); });
            form.addEventListener('submit', event => {
                if (bookBtn.disabled || !startHidden.value || !endHidden.value) event.preventDefault();
                else bookBtn.disabled = true; // prevent repeated clicks
            });
        })();
        </script>
    <?php endif; ?>
</main>
<?php bookingFoot(); ?>
