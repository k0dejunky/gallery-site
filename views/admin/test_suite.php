$title = 'Test Suite';

<div class="test-hero">
    <div class="test-tools">
        <button type="button" class="btn" id="ts-check-none">None</button>
        <button type="button" class="btn" id="ts-check-all">Select all</button>
        <button type="button" class="btn" id="ts-run-selected" disabled>Run selected</button>
        <button type="button" class="btn" id="ts-run-all">Run all <span id="ts-total-count"></span></button>
    </div>
</div>

<div id="ts-summary" class="test-summary">
    <span><b id="ts-attached">Idle</b></span>
    <span class="test-progressbar"><span id="ts-bar"></span></span>
    <span><b id="ts-passed">0</b> passed</span>
    <span><b id="ts-failed">0</b> failed</span>
    <span><b id="ts-pending">0</b> pending</span>
</div>

<div id="ts-viewing" class="ts-viewing" hidden></div>

<!-- Past runs (collapsible, shown above the tests) -->
<details class="sys-card test-runs" id="ts-runs-panel" open>
    <summary>
        <h2 class="section-title u-inline">Recent runs</h2>
        <span class="collapsible-meta muted" id="ts-runs-count"></span>
    </summary>
    <p class="muted u-mt-05">Click a run to view its full per-test results.</p>
    <div class="users-table-wrap">
        <table class="users-table" id="ts-runs-table">
            <thead>
                <tr><th>Run</th><th>Started</th><th>Result</th><th>Progress</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($runs as $r): ?>
                <tr data-run="<?= e($r['id']) ?>">
                    <td class="mono"><?= e($r['id']) ?></td>
                    <td><?= !empty($r['started_at']) ? e(tzdate('Y-m-d H:i', $r['started_at'])) : '' ?></td>
                    <td><span class="status-badge <?= ($r['status'] ?? '') === 'complete' ? ('ts-' . ($r['failed'] > 0 ? 'failed' : 'passed')) : 'ts-running' ?>"><?= e($r['status'] ?? '') ?></span></td>
                    <td class="detail-cell"><?= (int) ($r['passed'] ?? 0) ?> / <?= (int) ($r['total'] ?? 0) ?> passed, <?= (int) ($r['failed'] ?? 0) ?> failed</td>
                    <td><button type="button" class="btn btn-sm" data-view-run="<?= e($r['id']) ?>">View</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</details>

<div id="ts-groups">

<?php foreach ($groups as $group => $tests): $total = count($tests); ?>
    <details class="sys-card ts-group" data-group="<?= e($group) ?>" open>
        <summary>
            <input type="checkbox" class="ts-grp-cbx" data-group="<?= e($group) ?>" title="toggle group" data-stop-prop>
            <strong><?= e($group) ?></strong>
            <span class="g-count"><span class="ts-grp-pass" data-group="<?= e($group) ?>">0</span>/<?= $total ?> passed</span>
        </summary>
        <div class="users-table-wrap">
            <table class="users-table" data-group="<?= e($group) ?>">
                <thead>
                    <tr>
                        <th class="check-col"><input type="checkbox" class="ts-grp-cbx" data-group="<?= e($group) ?>" data-stop-prop></th>
                        <th>Test</th>
                        <th>Status</th>
                        <th>Result</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tests as $t): ?>
                    <tr data-id="<?= e($t['id']) ?>" data-group="<?= e($group) ?>">
                        <td class="check-col"><input type="checkbox" class="ts-cbx" value="<?= e($t['id']) ?>" checked></td>
                        <td><?= e($t['name']) ?></td>
                        <td><span class="status-badge ts-pending">pending</span></td>
                        <td class="detail-cell"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
<?php endforeach; ?>

</div>

<script nonce="<?= csp_nonce() ?>">
(function () {
    var token = <?= json_encode(\App\Core\Csrf::token()) ?>;
    var statusUrl = <?= json_encode(url('/admin/test-suite/status')) ?>;
    var runUrl    = <?= json_encode(url('/admin/test-suite/run')) ?>;
    var activeRun = <?= json_encode($running) ?>;

    var els = {
        runSelected: document.getElementById('ts-run-selected'),
        runAll:      document.getElementById('ts-run-all'),
        checkNone:   document.getElementById('ts-check-none'),
        checkAll:    document.getElementById('ts-check-all'),
        passed:      document.getElementById('ts-passed'),
        failed:      document.getElementById('ts-failed'),
        pending:     document.getElementById('ts-pending'),
        bar:         document.getElementById('ts-bar'),
        attached:    document.getElementById('ts-attached'),
        total:       document.getElementById('ts-total-count'),
    };

    var rowsById = {};
    document.querySelectorAll('#ts-groups tr[data-id]').forEach(function (tr) {
        rowsById[tr.dataset.id] = tr;
    });
    els.total.textContent = Object.keys(rowsById).length + ' tests';
    // All tests are checked by default, so "Run selected" is usable at load.
    els.runSelected.disabled = document.querySelectorAll('.ts-cbx:checked').length === 0;

    // Show how many past runs exist in the collapsible panel's summary line.
    var runsCountEl = document.getElementById('ts-runs-count');
    if (runsCountEl) {
        runsCountEl.textContent = document.querySelectorAll('#ts-runs-table tbody tr').length + ' stored run(s)';
    }

    function selectedIds() {
        var out = [];
        document.querySelectorAll('.ts-cbx:checked').forEach(function (c) { out.push(c.value); });
        return out;
    }

    function setRunning(on) {
        els.runSelected.disabled = on;
        els.runAll.disabled = on;
    }

    function groupPassCount() {
        var map = {};
        document.querySelectorAll('#ts-groups tr[data-id]').forEach(function (tr) {
            var g = tr.dataset.group;
            if (!(g in map)) map[g] = 0;
            if (tr.classList.contains('row-passed')) map[g]++;
        });
        return map;
    }

    function updateGroupPass() {
        var map = groupPassCount();
        document.querySelectorAll('.ts-grp-pass').forEach(function (el) {
            el.textContent = map[el.dataset.group] || 0;
        });
    }

    function applyStatus(id, status, detail) {
        var tr = rowsById[id];
        if (!tr) return;
        tr.classList.remove('row-passed', 'row-failed');
        var badge = tr.querySelector('.status-badge');
        badge.className = 'status-badge ts-' + status;
        badge.textContent = status;
        var detailEl = tr.querySelector('.detail-cell');
        if (detail !== undefined && detail !== null && detail !== '') {
            detailEl.textContent = detail;
        }
        if (status === 'passed') tr.classList.add('row-passed');
        if (status === 'failed') tr.classList.add('row-failed');
        // keep group header checkbox and per-group passed count fresh
        if (status === 'passed' || status === 'failed') updateGroupPass();
    }

    function renderRun(state) {
        if (!state || !state.tests) return;
        // The stored run state maps test id => result (an object, not an
        // array). Normalise to a list so the same render path works whether
        // the payload arrives as an object or an array.
        var tests = Array.isArray(state.tests)
            ? state.tests
            : Object.keys(state.tests).map(function (id) { return state.tests[id]; });
        tests.forEach(function (t) {
            if (!t) return;
            applyStatus(t.id, t.status || 'pending', t.detail || '');
        });
        var status = state.status || 'pending';
        els.attached.textContent = status === 'complete'
            ? (state.failed > 0 ? 'Completed · failures found' : 'Completed · all passed')
            : 'Running… (' + (state.ran || 0) + '/' + (state.total || 0) + ')';
        els.attached.className = status === 'complete'
            ? (state.failed > 0 ? 'ts-failed' : '')
            : '';
        els.passed.textContent = state.passed || 0;
        els.failed.textContent = state.failed || 0;
        els.pending.textContent = (state.total || 0) - (state.passed || 0) - (state.failed || 0);
        var pct = state.total ? Math.round(((state.passed || 0) + (state.failed || 0)) / state.total * 100) : 0;
        els.bar.style.width = pct + '%';
        if (status === 'complete') {
            setRunning(false);
            activeRun = null;
            window.clearInterval(pollTimer);
        }
    }

    var pollTimer = null;
    function startPolling(runId) {
        activeRun = runId;
        setRunning(true);
        window.clearInterval(pollTimer);
        pollTimer = window.setInterval(function () { fetchStatus(); }, 650);
        fetchStatus();
    }

    function fetchStatus() {
        if (!activeRun) return;
        fetch(statusUrl + '?run=' + encodeURIComponent(activeRun), {
            headers: { 'Accept': 'application/json' }
        }).then(function (r) { return r.json(); }).then(renderRun)
          .catch(function () { /* transient - retry on next tick */ });
    }

    function launch(ids) {
        var body = new URLSearchParams();
        body.set('_token', token);
        (ids || []).forEach(function (id) { body.append('tests[]', id); });
        return fetch(runUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (!res.ok) { alert(res.error || 'Could not start test run'); return; }
            clearViewedRun();
            startPolling(res.run);
            // refresh the recent-runs table cheaply on completion
        });
    }

    var viewingEl = document.getElementById('ts-viewing');

    function highlightRun(runId) {
        document.querySelectorAll('#ts-runs-table tbody tr').forEach(function (tr) {
            tr.classList.toggle('ts-run-selected', tr.dataset.run === runId);
        });
    }

    function clearViewedRun() {
        if (viewingEl) viewingEl.hidden = true;
        highlightRun(null);
    }

    // Load and display the full results of a past run without re-running it.
    function viewRun(runId) {
        if (!runId) return;
        fetch(statusUrl + '?run=' + encodeURIComponent(runId), {
            headers: { 'Accept': 'application/json' }
        }).then(function (r) { return r.json(); }).then(function (state) {
            if (!state || state.ok !== true || state.status === 'pending' || state.status === 'running') {
                // A still-running run is shown live below; don't freeze it here.
                if (state && state.status === 'running') { startPolling(runId); return; }
                alert('Could not load run results.');
                return;
            }
            renderRun(state);
            if (viewingEl) {
                viewingEl.textContent = 'Viewing results from run ' + runId + ' (click a run in Recent runs to switch, or start a new run to go live).';
                viewingEl.hidden = false;
            }
            highlightRun(runId);
        }).catch(function () { alert('Could not load run results.'); });
    }

    // Clicking a row or its View button loads that past run's results.
    document.querySelectorAll('#ts-runs-table tbody tr').forEach(function (tr) {
        tr.addEventListener('click', function (e) {
            if (e.target.closest('button')) return; // button has its own handler
            if (activeRun) return;
            viewRun(tr.dataset.run);
        });
    });
    document.querySelectorAll('[data-view-run]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (activeRun) return;
            viewRun(btn.dataset.viewRun);
        });
    });

    els.runAll.addEventListener('click', function () { if (!activeRun) launch(null); });
    els.runSelected.addEventListener('click', function () {
        var ids = selectedIds();
        if (!ids.length) { alert('No tests selected'); return; }
        if (activeRun) return;
        launch(ids);
    });
    els.checkAll.addEventListener('click', function () {
        document.querySelectorAll('.ts-cbx').forEach(function (c) { c.checked = true; });
        els.runSelected.disabled = false;
    });
    els.checkNone.addEventListener('click', function () {
        document.querySelectorAll('.ts-cbx').forEach(function (c) { c.checked = false; });
        els.runSelected.disabled = true;
    });
    document.querySelectorAll('.ts-cbx').forEach(function (c) {
        c.addEventListener('change', function () {
            els.runSelected.disabled = document.querySelectorAll('.ts-cbx:checked').length === 0;
        });
    });
    document.querySelectorAll('.ts-grp-cbx').forEach(function (c) {
        c.addEventListener('change', function () {
            document.querySelectorAll('#ts-groups tr[data-group="' + c.dataset.group + '"] .ts-cbx').forEach(function (x) { x.checked = c.checked; });
            els.runSelected.disabled = document.querySelectorAll('.ts-cbx:checked').length === 0;
        });
    });

    if (activeRun) startPolling(activeRun);
})();
</script>