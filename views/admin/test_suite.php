<style>
    .test-hero { display: flex; justify-content: space-between; align-items: flex-end; gap: 1rem; margin-bottom: 1.25rem; flex-wrap: wrap; }
    .test-tools { display: flex; gap: .5rem; align-items: center; }
    .test-progressbar { height: .5rem; background: var(--filter-bg); border-radius: 999px; overflow: hidden; width: 100%; max-width: 360px; }
    .test-progressbar span { display: block; height: 100%; width: 0; background: linear-gradient(90deg, var(--purple-600), var(--pink-500)); transition: width .4s ease; }
    .test-summary { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.25rem; color: var(--muted-text-color); font-size: .9rem; }
    .test-summary b { margin-right: .25rem; }
    .status-badge.ts-passed { background: #166534; color: #fff; }
    .status-badge.ts-failed { background: #991b1b; color: #fff; }
    .status-badge.ts-running { background: #7c3aed; color: #fff; }
    .status-badge.ts-pending { background: #e5e7eb; color: #1f2937; }
    .detail-cell { color: var(--muted-text-color); font-size: .82rem; overflow-wrap: anywhere; }
    .test-group-head { display: flex; align-items: center; gap: .5rem; padding: .6rem .9rem; background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--card-radius); }
    .test-group-head .g-count { margin-left: auto; font-size: .82rem; color: var(--muted-text-color); }
    .check-col { width: 2.2rem; text-align: center; }
    .ts-none { margin-top: 1rem; }
    .test-runs { margin-top: 1.5rem; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
</style>

<div class="test-hero">
    <div>
        <h1 class="section-title">Test suite</h1>
        <p class="muted">Run self-contained front-end and back-end checks against the whole site. Tests are read-only and safe to repeat.</p>
    </div>
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

<div id="ts-groups">

<?php foreach ($groups as $group => $tests): $total = count($tests); ?>
    <div class="test-group-head" data-group="<?= e($group) ?>">
        <input type="checkbox" class="ts-grp-cbx" data-group="<?= e($group) ?>" title="toggle group">
        <strong><?= e($group) ?></strong>
        <span class="g-count"><span class="ts-grp-pass" data-group="<?= e($group) ?>">0</span>/<?= $total ?> passed</span>
    </div>
    <div class="users-table-wrap">
        <table class="users-table ts-table" data-group="<?= e($group) ?>">
            <thead>
                <tr>
                    <th class="check-col"><input type="checkbox" class="ts-grp-cbx" data-group="<?= e($group) ?>"></th>
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
<?php endforeach; ?>

</div>

<!-- Past runs -->
<div class="test-runs">
    <h2 class="section-title">Recent runs</h2>
    <div class="users-table-wrap">
        <table class="users-table" id="ts-runs-table">
            <thead>
                <tr><th>Run</th><th>Started</th><th>Result</th><th>Progress</th></tr>
            </thead>
            <tbody>
            <?php foreach ($runs as $r): ?>
                <tr data-run="<?= e($r['id']) ?>">
                    <td class="mono"><?= e($r['id']) ?></td>
                    <td><?= e($r['started_at'] ?? '') ?></td>
                    <td><span class="status-badge <?= ($r['status'] ?? '') === 'complete' ? ('ts-' . ($r['failed'] > 0 ? 'failed' : 'passed')) : 'ts-running' ?>"><?= e($r['status'] ?? '') ?></span></td>
                    <td class="detail-cell"><?= (int) ($r['passed'] ?? 0) ?> / <?= (int) ($r['total'] ?? 0) ?> passed, <?= (int) ($r['failed'] ?? 0) ?> failed</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
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
        state.tests.forEach(function (t) {
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
            startPolling(res.run);
            // refresh the recent-runs table cheaply on completion
        });
    }

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