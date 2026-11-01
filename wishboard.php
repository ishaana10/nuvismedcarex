<?php
/**
 * Feature Wish Board
 * Clinics submit feature wishes and track their status + timeline. Developers
 * see every clinic's wishes, set status and target dates, and reply in-thread.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/core/SessionBootstrap.php';

$pageTitle = "Feature Wish Board - NuvisMedcareX";
$activePage = "wishboard";
include __DIR__ . '/includes/header.php';

use ClinicFlow\Shared\Container;
use ClinicFlow\Shared\TenantContext;
use ClinicFlow\Services\WishBoardService;

$wishService = Container::getInstance()->get(WishBoardService::class);
$tenantId = TenantContext::getTenantId();
$role = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'Doctor';
$isDeveloper = ($role === 'Developer');
$csrf = htmlspecialchars(getCsrfToken());

$statusFilter = $_GET['status'] ?? '';
$wishes = $isDeveloper
    ? $wishService->listWishes(null, $statusFilter)
    : $wishService->listWishes($tenantId, $statusFilter);
$counts = $wishService->statusCounts($isDeveloper ? null : $tenantId);
$total = array_sum($counts);

$statusBadge = [
    'submitted'   => 'bg-slate-100 text-slate-700 border-slate-200',
    'under_review' => 'bg-indigo-100 text-indigo-800 border-indigo-200',
    'planned'     => 'bg-blue-100 text-blue-800 border-blue-200',
    'in_progress' => 'bg-amber-100 text-amber-900 border-amber-200',
    'shipped'     => 'bg-emerald-100 text-emerald-800 border-emerald-200',
    'declined'    => 'bg-rose-100 text-rose-800 border-rose-200',
];
$priorityBadge = [
    'low'    => 'text-slate-500',
    'medium' => 'text-blue-600',
    'high'   => 'text-rose-600',
];

function nx_wish_dt($v): string {
    if (empty($v)) return '';
    $ts = strtotime((string)$v);
    return $ts ? date('d M Y', $ts) : '';
}
?>

<div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <h1 class="text-2xl font-bold text-slate-900 flex items-center gap-2">
            <span class="material-symbols-outlined text-blue-700 text-2xl">lightbulb</span>
            <span>Feature Wish Board</span>
        </h1>
        <p class="text-xs text-slate-500 font-medium mt-0.5">
            <?= $isDeveloper
                ? 'Every clinic\'s feature requests. Set a status and a target timeline, and reply to keep clinics in the loop.'
                : 'Tell the Nuvis team what would make the system better. Track where each idea stands and when it\'s expected.' ?>
        </p>
    </div>
    <button type="button" onclick="openModal('modal-new-wish')" class="btn-primary self-start md:self-auto">
        <span class="material-symbols-outlined text-[20px]">add</span>
        <span><?= $isDeveloper ? 'Log a wish' : 'Submit a wish' ?></span>
    </button>
</div>

<!-- Status summary -->
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
    <?php
    $order = WishBoardService::STATUSES;
    foreach ($order as $st):
        $active = $statusFilter === $st;
    ?>
        <a href="wishboard.php<?= $active ? '' : '?status=' . urlencode($st) ?>"
           class="rounded-2xl border p-3 text-center transition hover:shadow-sm <?= $active ? 'ring-2 ring-blue-500/50 ' : '' ?><?= $statusBadge[$st] ?>">
            <div class="text-xl font-bold leading-none"><?= (int)($counts[$st] ?? 0) ?></div>
            <div class="text-[11px] font-semibold mt-1"><?= htmlspecialchars(WishBoardService::STATUS_LABELS[$st]) ?></div>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($statusFilter): ?>
    <div class="mb-4 text-xs text-slate-500">
        Filtered by <span class="font-semibold"><?= htmlspecialchars(WishBoardService::STATUS_LABELS[$statusFilter] ?? $statusFilter) ?></span>.
        <a href="wishboard.php" class="text-blue-700 font-semibold hover:underline ml-1">Clear</a>
    </div>
<?php endif; ?>

<?php if (empty($wishes)): ?>
    <div class="nx-empty rounded-2xl border border-slate-200 bg-white py-16">
        <span class="material-symbols-outlined text-4xl text-slate-300">lightbulb</span>
        <p class="mt-2">No wishes here yet. <?= $isDeveloper ? 'Clinic requests will appear as they come in.' : 'Be the first to suggest something!' ?></p>
    </div>
<?php else: ?>
    <div class="space-y-4">
        <?php foreach ($wishes as $w):
            $comments = $wishService->listComments($w['id']);
            $sb = $statusBadge[$w['status']] ?? $statusBadge['submitted'];
        ?>
            <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
                <div class="p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <h3 class="font-bold text-slate-900 text-base"><?= htmlspecialchars($w['title']) ?></h3>
                                <span class="text-[10px] uppercase tracking-wider font-bold px-2 py-0.5 rounded-full border <?= $sb ?>"><?= htmlspecialchars(WishBoardService::STATUS_LABELS[$w['status']] ?? $w['status']) ?></span>
                            </div>
                            <div class="flex items-center gap-3 text-[11px] text-slate-500 mt-1 flex-wrap">
                                <span class="inline-flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">sell</span><?= htmlspecialchars(ucfirst($w['category'] ?? 'general')) ?></span>
                                <span class="inline-flex items-center gap-1 font-semibold <?= $priorityBadge[$w['clinic_priority']] ?? '' ?>"><span class="material-symbols-outlined text-[14px]">flag</span><?= htmlspecialchars(ucfirst($w['clinic_priority'] ?? 'medium')) ?> priority</span>
                                <?php if ($isDeveloper && !empty($w['tenant_name'])): ?>
                                    <span class="inline-flex items-center gap-1 text-blue-700 font-semibold"><span class="material-symbols-outlined text-[14px]">domain</span><?= htmlspecialchars($w['tenant_name']) ?></span>
                                <?php endif; ?>
                                <span class="inline-flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">person</span><?= htmlspecialchars($w['created_by_name'] ?: 'Clinic user') ?></span>
                                <span class="inline-flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">schedule</span><?= nx_wish_dt($w['created_at']) ?></span>
                            </div>
                        </div>
                        <!-- Votes -->
                        <form action="actions/wishboard_actions.php" method="POST" class="shrink-0 text-center">
                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                            <input type="hidden" name="action" value="vote_wish">
                            <input type="hidden" name="wish_id" value="<?= htmlspecialchars($w['id']) ?>">
                            <button type="submit" class="flex flex-col items-center justify-center w-14 h-14 rounded-xl border border-slate-200 hover:border-blue-400 hover:bg-blue-50 transition" title="Upvote this wish">
                                <span class="material-symbols-outlined text-[18px] text-blue-600">keyboard_arrow_up</span>
                                <span class="text-sm font-bold text-slate-900 leading-none"><?= (int)$w['vote_count'] ?></span>
                            </button>
                        </form>
                    </div>

                    <?php if (!empty($w['description'])): ?>
                        <p class="text-sm text-slate-700 leading-relaxed mt-3"><?= nl2br(htmlspecialchars($w['description'])) ?></p>
                    <?php endif; ?>

                    <!-- Timeline -->
                    <div class="mt-4 flex items-center gap-2 text-[11px] flex-wrap">
                        <?php
                        $steps = ['submitted' => 'Submitted', 'under_review' => 'Reviewing', 'planned' => 'Planned', 'in_progress' => 'Building', 'shipped' => 'Shipped'];
                        $stIndex = array_search($w['status'], array_keys($steps), true);
                        $declined = $w['status'] === 'declined';
                        $i = 0;
                        foreach ($steps as $key => $label):
                            $done = ($stIndex !== false && $i <= $stIndex) && !$declined;
                        ?>
                            <?php if ($i > 0): ?><span class="w-4 h-px <?= $done ? 'bg-blue-400' : 'bg-slate-200' ?>"></span><?php endif; ?>
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full <?= $done ? 'bg-blue-50 text-blue-700 font-semibold' : 'bg-slate-50 text-slate-400' ?>">
                                <span class="material-symbols-outlined text-[13px]"><?= $done ? 'check_circle' : 'radio_button_unchecked' ?></span><?= $label ?>
                            </span>
                        <?php $i++; endforeach; ?>
                        <?php if ($declined): ?>
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-rose-50 text-rose-700 font-semibold"><span class="material-symbols-outlined text-[13px]">cancel</span>Declined</span>
                        <?php endif; ?>
                        <?php if (!empty($w['target_date'])): ?>
                            <span class="inline-flex items-center gap-1 ml-auto text-slate-600 font-semibold"><span class="material-symbols-outlined text-[14px]">event</span>Target: <?= nx_wish_dt($w['target_date']) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($w['dev_notes'])): ?>
                        <div class="mt-3 rounded-xl bg-indigo-50 border border-indigo-100 px-3 py-2 text-[13px] text-indigo-900">
                            <span class="font-bold">Dev note:</span> <?= nl2br(htmlspecialchars($w['dev_notes'])) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($isDeveloper): ?>
                        <!-- Developer triage -->
                        <form action="actions/wishboard_actions.php" method="POST" class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3 grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                            <input type="hidden" name="action" value="update_wish">
                            <input type="hidden" name="wish_id" value="<?= htmlspecialchars($w['id']) ?>">
                            <div>
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <?php foreach (WishBoardService::STATUSES as $st): ?>
                                        <option value="<?= $st ?>" <?= $w['status'] === $st ? 'selected' : '' ?>><?= htmlspecialchars(WishBoardService::STATUS_LABELS[$st]) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="form-label">Target date</label>
                                <input type="date" name="target_date" value="<?= htmlspecialchars($w['target_date'] ?? '') ?>" class="form-input">
                            </div>
                            <div class="sm:col-span-2">
                                <label class="form-label">Dev note (shown to clinic)</label>
                                <input type="text" name="dev_notes" value="<?= htmlspecialchars($w['dev_notes'] ?? '') ?>" placeholder="e.g. Scheduled for the November release" class="form-input">
                            </div>
                            <div class="sm:col-span-4 flex justify-end">
                                <button type="submit" class="btn-primary !py-2"><span class="material-symbols-outlined text-[18px]">save</span>Update</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Thread -->
                <div class="border-t border-slate-100 bg-slate-50/60 px-5 py-4">
                    <div class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3 flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-[16px]">forum</span>Discussion (<?= count($comments) ?>)
                    </div>
                    <?php if (!empty($comments)): ?>
                        <div class="space-y-3 mb-3">
                            <?php foreach ($comments as $c): ?>
                                <div class="flex gap-2.5">
                                    <span class="shrink-0 w-7 h-7 rounded-full flex items-center justify-center text-[11px] font-bold <?= !empty($c['is_developer']) ? 'bg-blue-600 text-white' : 'bg-slate-200 text-slate-700' ?>">
                                        <?= htmlspecialchars(mb_strtoupper(mb_substr($c['author_name'] ?: '?', 0, 1))) ?>
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2">
                                            <span class="text-[13px] font-semibold text-slate-900"><?= htmlspecialchars($c['author_name'] ?: 'User') ?></span>
                                            <?php if (!empty($c['is_developer'])): ?>
                                                <span class="text-[10px] uppercase font-bold px-1.5 py-0.5 rounded bg-blue-100 text-blue-700">Developer</span>
                                            <?php endif; ?>
                                            <span class="text-[11px] text-slate-400"><?= nx_wish_dt($c['created_at']) ?></span>
                                        </div>
                                        <p class="text-[13px] text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($c['body'])) ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <form action="actions/wishboard_actions.php" method="POST" class="flex items-center gap-2">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="add_comment">
                        <input type="hidden" name="wish_id" value="<?= htmlspecialchars($w['id']) ?>">
                        <input type="text" name="body" required placeholder="Write a reply…" class="form-input flex-1">
                        <button type="submit" class="btn-secondary shrink-0"><span class="material-symbols-outlined text-[18px]">send</span></button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- New wish modal -->
<div id="modal-new-wish" class="fixed inset-0 z-[80] bg-slate-950/50 backdrop-blur-xs flex items-center justify-center hidden p-4" data-nx-modal>
    <div class="bg-white rounded-2xl border border-slate-200 shadow-pop max-w-lg w-full overflow-hidden" role="dialog" aria-modal="true">
        <div class="px-6 pt-5 pb-4 flex items-start justify-between">
            <div>
                <h3 class="font-semibold text-base text-slate-900">Submit a feature wish</h3>
                <p class="text-xs text-slate-500 mt-0.5">Describe what you'd like and why it would help your clinic.</p>
            </div>
            <button type="button" onclick="closeModal('modal-new-wish')" class="nx-icon-btn -mr-2 -mt-1" aria-label="Close"><span class="material-symbols-outlined">close</span></button>
        </div>
        <form action="actions/wishboard_actions.php" method="POST" class="px-6 pb-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="create_wish">
            <div>
                <label class="form-label" for="wish-title">Title</label>
                <input id="wish-title" type="text" name="title" required maxlength="255" placeholder="Short summary of the idea" class="form-input">
            </div>
            <div>
                <label class="form-label" for="wish-desc">Details</label>
                <textarea id="wish-desc" name="description" rows="4" placeholder="What should it do? What problem does it solve?" class="form-textarea"></textarea>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label" for="wish-cat">Area</label>
                    <select id="wish-cat" name="category" class="form-select">
                        <?php foreach (WishBoardService::CATEGORIES as $cat): ?>
                            <option value="<?= $cat ?>"><?= htmlspecialchars(ucfirst($cat)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="wish-pri">Priority for you</label>
                    <select id="wish-pri" name="clinic_priority" class="form-select">
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                    </select>
                </div>
            </div>
            <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" onclick="closeModal('modal-new-wish')" class="btn-secondary">Cancel</button>
                <button type="submit" class="btn-primary">Submit wish</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
