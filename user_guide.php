<?php
/**
 * User guide: a complete, searchable manual for every signed-in person.
 * Open to all roles (no module permission needed). The words live in
 * includes/user_guide_content.php.
 */
use ClinicFlow\Services\AccessControl;

$pageTitle = "User guide - NuvisMedcareX";
$activePage = "guide";
include __DIR__ . '/includes/header.php';

$sections = require __DIR__ . '/includes/user_guide_content.php';
$role = nxCurrentRole();
$ac = nxAccess();
$roleList = $ac->roles(false);
if (!in_array($role, $roleList, true) && $ac->isCustomRole($role) === false) {
    $roleList[] = $role;                       // e.g. Developer sees their own row
}
$levelLabel = AccessControl::LEVEL_LABELS;

/** Roles table, built from the clinic's real settings so it is never out of date. */
$rolesHtml = (function () use ($ac, $roleList, $role, $levelLabel): string {
    $h  = '<p>Each role has a level for every area of the app: <strong>No access</strong>, <strong>View</strong> (look only), <strong>Edit</strong> (add and change) or <strong>Full</strong> (also delete). The table shows what <em>this clinic</em> has set; your own role is highlighted.</p>';
    $h .= '<div class="g-scroll"><table class="g-table g-roles"><thead><tr><th>Area</th>';
    foreach ($roleList as $r) {
        $h .= '<th' . ($r === $role ? ' class="is-me"' : '') . '>' . htmlspecialchars($r) . ($r === $role ? '<span>you</span>' : '') . '</th>';
    }
    $h .= '</tr></thead><tbody>';
    foreach (AccessControl::MODULES as $key => $m) {
        if ($key === 'developer' && $role !== 'Developer') continue;
        $h .= '<tr><td><strong>' . htmlspecialchars($m['label']) . '</strong><div class="g-sub">' . htmlspecialchars($m['help']) . '</div></td>';
        foreach ($roleList as $r) {
            $lv = $ac->level($r, $key);
            $h .= '<td class="lv-' . $lv . ($r === $role ? ' is-me' : '') . '">' . htmlspecialchars($levelLabel[$lv] ?? '') . '</td>';
        }
        $h .= '</tr>';
    }
    $h .= '</tbody></table></div>';
    $h .= '<h4>What the built-in roles are for</h4><ul>';
    foreach ($roleList as $r) {
        $h .= '<li><strong>' . htmlspecialchars($r) . ':</strong> ' . htmlspecialchars($ac->describe($r)) . '</li>';
    }
    $h .= '</ul><p>Administrators can change these levels, or create their own roles, under <strong>Administration → Access &amp; roles</strong>.</p>';
    return $h;
})();

foreach ($sections as &$s) {
    if ($s['body'] === '__ROLES_TABLE__') $s['body'] = $rolesHtml;
}
unset($s);

// Which areas can this person open? Shown as a short summary at the top.
$myAreas = [];
foreach (AccessControl::MODULES as $key => $m) {
    if ($key === 'developer' && $role !== 'Developer') continue;
    $myAreas[] = [$m['label'], $ac->level($role, $key)];
}
?>

<style>
    .g-wrap{display:grid;grid-template-columns:minmax(0,1fr);gap:24px;align-items:start}
    @media (min-width:1024px){.g-wrap{grid-template-columns:260px minmax(0,1fr)}}
    .g-toc{position:sticky;top:84px;max-height:calc(100vh - 110px);overflow:auto;padding:6px 0}
    .g-toc a{display:flex;align-items:center;gap:10px;padding:7px 12px;border-radius:10px;font-size:13px;color:rgb(71 85 105);text-decoration:none}
    .g-toc a:hover{background:rgb(241 245 249);color:rgb(15 23 42)}
    .g-toc a.on{background:rgb(219 234 254);color:rgb(30 64 175);font-weight:600}
    .g-toc .material-symbols-outlined{font-size:18px}
    .g-card{background:#fff;border:1px solid rgb(226 232 240);border-radius:16px;padding:22px 24px;scroll-margin-top:84px}
    .g-card+.g-card{margin-top:16px}
    .g-home{margin-top:18px;padding-top:12px;border-top:1px solid rgb(241 245 249);text-align:right}
    .g-home a{display:inline-flex;align-items:center;gap:4px;font-size:13px;font-weight:600;color:rgb(30 64 175);text-decoration:none;padding:6px 10px;border-radius:10px}
    .g-home a:hover{background:rgb(239 246 255)}
    .g-home .material-symbols-outlined{font-size:18px}
    .g-head{display:flex;align-items:flex-start;gap:12px;margin-bottom:12px}
    .g-ico{flex:none;width:40px;height:40px;border-radius:12px;background:rgb(219 234 254);color:rgb(30 64 175);display:flex;align-items:center;justify-content:center}
    .g-card h3{font-size:19px;line-height:1.25;font-weight:600;color:rgb(15 23 42);margin:0}
    .g-card .g-sum{font-size:13px;color:rgb(100 116 139);margin-top:2px}
    .g-chip{display:inline-block;font-size:11px;font-weight:600;padding:2px 8px;border-radius:999px;background:rgb(241 245 249);color:rgb(71 85 105);margin:6px 6px 0 0}
    .g-chip.warn{background:rgb(254 243 199);color:rgb(146 64 14)}
    .g-body{font-size:14px;line-height:1.7;color:rgb(51 65 85)}
    .g-body h4{font-size:14px;font-weight:700;color:rgb(15 23 42);margin:20px 0 6px}
    .g-body p{margin:8px 0}
    .g-body ul,.g-body ol{margin:8px 0 8px 22px}
    .g-body ul{list-style:disc}.g-body ol{list-style:decimal}
    .g-body li{margin:4px 0}
    .g-body a{color:rgb(29 78 216);text-decoration:underline;text-underline-offset:2px}
    .g-body kbd{display:inline-block;min-width:22px;text-align:center;font:600 11px/1 ui-monospace,monospace;padding:4px 6px;border:1px solid rgb(203 213 225);border-bottom-width:2px;border-radius:6px;background:rgb(248 250 252);color:rgb(51 65 85)}
    .g-body code{font:12.5px ui-monospace,monospace;background:rgb(241 245 249);padding:1px 6px;border-radius:6px}
    .g-tip,.g-note,.g-warn{margin:12px 0;padding:10px 14px;border-radius:12px;font-size:13px;line-height:1.55;border:1px solid}
    .g-tip{background:rgb(236 253 245);border-color:rgb(167 243 208);color:rgb(6 78 59)}
    .g-note{background:rgb(239 246 255);border-color:rgb(191 219 254);color:rgb(30 58 138)}
    .g-warn{background:rgb(255 251 235);border-color:rgb(253 230 138);color:rgb(120 53 15)}
    .g-scroll{overflow-x:auto;margin:10px 0}
    .g-table{width:100%;border-collapse:collapse;font-size:13px}
    .g-table th,.g-table td{border:1px solid rgb(226 232 240);padding:8px 10px;text-align:left;vertical-align:top}
    .g-table th{background:rgb(248 250 252);font-weight:600;color:rgb(51 65 85)}
    .g-sub{font-size:12px;color:rgb(100 116 139);font-weight:400}
    .g-roles td,.g-roles th{text-align:center}.g-roles td:first-child,.g-roles th:first-child{text-align:left}
    .g-roles th span{display:block;font-size:10px;font-weight:700;color:rgb(30 64 175);text-transform:uppercase}
    .g-roles .is-me{background:rgb(239 246 255)}
    .g-roles .lv-0{color:rgb(148 163 184)}.g-roles .lv-1{color:rgb(71 85 105)}
    .g-roles .lv-2{color:rgb(29 78 216);font-weight:600}.g-roles .lv-3{color:rgb(5 122 85);font-weight:700}
    .g-faq dt{font-weight:600;color:rgb(15 23 42);margin-top:12px}
    .g-faq dd{margin:2px 0 0}
    .g-me{display:flex;flex-wrap:wrap;gap:8px}
    .g-me span{font-size:12px;border:1px solid rgb(226 232 240);border-radius:999px;padding:3px 10px;background:#fff}
    .g-me .l0{color:rgb(148 163 184);background:rgb(248 250 252)}
    .g-me .l2,.g-me .l3{border-color:rgb(191 219 254);background:rgb(239 246 255);color:rgb(30 64 175)}
    mark.g-hit{background:rgb(254 240 138);border-radius:3px;padding:0 1px}
    @media print{
        .g-toc,.g-tools,.g-home,.nx-bottomnav,.g-me-card{display:none!important}
        .g-wrap{display:block}.g-card{break-inside:avoid-page;border:none;padding:10px 0}
        .g-card[hidden]{display:block!important}
    }
</style>

<div id="guide-top" class="mb-5 flex flex-wrap items-start justify-between gap-3" style="scroll-margin-top:84px">
    <div>
        <h1 class="nx-page-title">User guide</h1>
        <p class="nx-page-sub">How to use Nuvis Medcare X, step by step. Search it, or pick a topic on the left.</p>
    </div>
    <div class="g-tools flex items-center gap-2">
        <button type="button" class="btn-secondary" data-on-click="window.print()"><span class="material-symbols-outlined text-[18px]">print</span>Print or save as PDF</button>
    </div>
</div>

<div class="g-tools mb-5 max-w-xl">
    <div class="relative">
        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-[20px] pointer-events-none">search</span>
        <input id="guide-search" type="search" class="form-input !pl-10" placeholder="Search the guide" autocomplete="off" aria-label="Search the user guide">
    </div>
    <div id="guide-count" class="text-[12px] text-slate-500 mt-1 min-h-[18px]" aria-live="polite"></div>
</div>

<div class="g-wrap">
    <!-- Topics -->
    <aside>
        <details class="lg:hidden rounded-2xl border border-slate-200 bg-white px-3 py-2 mb-1">
            <summary class="cursor-pointer text-sm font-semibold text-slate-700 py-1">Topics</summary>
            <nav class="g-toc !static !max-h-none" aria-label="Guide topics (phone)">
                <?php foreach ($sections as $s): ?>
                    <a href="#<?= $s['id'] ?>" data-toc="<?= $s['id'] ?>"><span class="material-symbols-outlined"><?= $s['icon'] ?></span><?= htmlspecialchars($s['title']) ?></a>
                <?php endforeach; ?>
            </nav>
        </details>
        <nav class="g-toc hidden lg:block" aria-label="Guide topics">
            <?php foreach ($sections as $s): ?>
                <a href="#<?= $s['id'] ?>" data-toc="<?= $s['id'] ?>"><span class="material-symbols-outlined"><?= $s['icon'] ?></span><?= htmlspecialchars($s['title']) ?></a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <!-- Content -->
    <main class="min-w-0">
        <section class="g-card g-me-card" aria-labelledby="g-me-h">
            <div class="g-head">
                <div class="g-ico"><span class="material-symbols-outlined">badge</span></div>
                <div>
                    <h3 id="g-me-h">What you can do here</h3>
                    <div class="g-sum">You are signed in as <strong><?= htmlspecialchars($role) ?></strong>. Topics about areas you cannot open are marked, but you can still read them.</div>
                </div>
            </div>
            <div class="g-me">
                <?php foreach ($myAreas as [$label, $lv]): ?>
                    <span class="l<?= $lv ?>"><?= htmlspecialchars($label) ?>: <?= htmlspecialchars($levelLabel[$lv] ?? '') ?></span>
                <?php endforeach; ?>
            </div>
        </section>

        <div id="guide-sections" class="mt-4">
        <?php foreach ($sections as $s):
            $mod = $s['module'];
            $locked = $mod && !nxCan($mod, 'view'); ?>
            <section class="g-card" id="<?= $s['id'] ?>" data-guide-section>
                <div class="g-head">
                    <div class="g-ico"><span class="material-symbols-outlined"><?= $s['icon'] ?></span></div>
                    <div class="min-w-0">
                        <h3><?= htmlspecialchars($s['title']) ?></h3>
                        <div class="g-sum"><?= htmlspecialchars($s['summary']) ?></div>
                        <div>
                            <?php foreach ($s['who'] as $w): ?><span class="g-chip"><?= htmlspecialchars($w) ?></span><?php endforeach; ?>
                            <?php if ($locked): ?><span class="g-chip warn">Not available to your role</span><?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="g-body"><?= $s['body'] ?></div>
                <div class="g-home"><a href="#guide-top" data-guide-home><span class="material-symbols-outlined" aria-hidden="true">keyboard_arrow_up</span>Home: back to topics and search</a></div>
            </section>
        <?php endforeach; ?>
        </div>

        <p id="guide-none" class="hidden text-center text-slate-500 py-10">Nothing matches that search. Try a shorter word, or ask your administrator.</p>
        <p class="text-center text-[12px] text-slate-400 mt-8">Still stuck? Ask your clinic administrator, or put a request on the <a class="underline" href="wishboard.php">Wish board</a>.</p>
    </main>
</div>

<script nonce="<?= nxCspNonce() ?>">
(function () {
    var input = document.getElementById('guide-search');
    var cards = [].slice.call(document.querySelectorAll('[data-guide-section]'));
    var toc = [].slice.call(document.querySelectorAll('[data-toc]'));
    var count = document.getElementById('guide-count');
    var none = document.getElementById('guide-none');
    var text = cards.map(function (c) { return c.textContent.toLowerCase(); });
    var original = cards.map(function (c) { return c.querySelector('.g-body').innerHTML; });

    function clearMarks(i) { cards[i].querySelector('.g-body').innerHTML = original[i]; }
    function mark(i, q) {
        var root = cards[i].querySelector('.g-body');
        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
        var nodes = [], n;
        while ((n = walker.nextNode())) { if (n.nodeValue.toLowerCase().indexOf(q) > -1 && !/^(SCRIPT|STYLE)$/.test(n.parentNode.nodeName)) nodes.push(n); }
        nodes.forEach(function (node) {
            var v = node.nodeValue, lower = v.toLowerCase(), out = document.createDocumentFragment(), pos = 0, at;
            while ((at = lower.indexOf(q, pos)) > -1) {
                out.appendChild(document.createTextNode(v.slice(pos, at)));
                var m = document.createElement('mark'); m.className = 'g-hit'; m.textContent = v.slice(at, at + q.length); out.appendChild(m);
                pos = at + q.length;
            }
            out.appendChild(document.createTextNode(v.slice(pos)));
            node.parentNode.replaceChild(out, node);
        });
    }

    function run() {
        var q = input.value.trim().toLowerCase(), shown = 0;
        cards.forEach(function (c, i) {
            clearMarks(i);
            var ok = !q || text[i].indexOf(q) > -1;
            c.hidden = !ok;
            if (ok) shown++;
            if (ok && q.length > 1) mark(i, q);
        });
        toc.forEach(function (a) {
            var c = document.getElementById(a.getAttribute('data-toc'));
            a.style.display = (c && c.hidden) ? 'none' : '';
        });
        none.classList.toggle('hidden', shown > 0);
        count.textContent = q ? (shown + (shown === 1 ? ' topic matches' : ' topics match') + ' "' + input.value.trim() + '"') : '';
    }
    var t;
    input.addEventListener('input', function () { clearTimeout(t); t = setTimeout(run, 120); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && document.activeElement === input && input.value) { input.value = ''; run(); }
    });

    // Highlight the topic being read
    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) {
                if (!en.isIntersecting) return;
                toc.forEach(function (a) { a.classList.toggle('on', a.getAttribute('data-toc') === en.target.id); });
            });
        }, { rootMargin: '-80px 0px -65% 0px' });
        cards.forEach(function (c) { io.observe(c); });
    }
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
