<?php
/**
 * A place for someone to sign with a finger or a mouse. The drawing is written
 * into a hidden field as a PNG data URI when the form is submitted.
 *
 * Set before including: $spName (field name), and optionally $spId, $spLabel,
 * $spHint and $spValue (an existing signature to show).
 */
$spName = $spName ?? 'signature';
$spId = $spId ?? ('sig-' . preg_replace('/[^a-z0-9]+/i', '-', $spName));
$spLabel = $spLabel ?? 'Signature';
$spHint = $spHint ?? 'Sign in the box with a finger or the mouse.';
$spValue = (string) ($spValue ?? '');
?>
<div class="nx-sigpad" id="<?= htmlspecialchars($spId) ?>" data-sigpad>
    <div class="flex items-center justify-between gap-2 mb-1">
        <span class="block font-bold text-outline text-xs"><?= htmlspecialchars($spLabel) ?></span>
        <button type="button" data-sig-clear class="text-[11px] font-medium text-slate-500 hover:text-red-600">Clear</button>
    </div>
    <?php if ($spValue !== ''): ?>
        <div class="mb-2 p-2 rounded-xl border border-emerald-200 bg-emerald-50/60 flex items-center gap-3" data-sig-existing>
            <img src="<?= htmlspecialchars($spValue) ?>" alt="Signature already on file" class="h-12 bg-white rounded border border-emerald-200">
            <span class="text-[11px] text-emerald-800">Already signed. Sign again below to replace it.</span>
        </div>
    <?php endif; ?>
    <div class="relative rounded-xl border border-dashed border-outline-variant/60 bg-white overflow-hidden" style="touch-action: none;">
        <canvas data-sig-canvas height="120" class="w-full block cursor-crosshair" style="height:120px"></canvas>
        <span data-sig-placeholder class="absolute inset-0 flex items-center justify-center text-[11px] text-slate-400 pointer-events-none">
            <?= htmlspecialchars($spHint) ?>
        </span>
    </div>
    <input type="hidden" name="<?= htmlspecialchars($spName) ?>" data-sig-value value="">
</div>
<?php if (empty($GLOBALS['nxSignaturePadScript'])): $GLOBALS['nxSignaturePadScript'] = true; ?>
<script>
(function () {
    function setup(pad) {
        if (pad.dataset.sigReady) return;
        pad.dataset.sigReady = '1';
        var canvas = pad.querySelector('[data-sig-canvas]');
        var field = pad.querySelector('[data-sig-value]');
        var hint = pad.querySelector('[data-sig-placeholder]');
        var clear = pad.querySelector('[data-sig-clear]');
        if (!canvas || !field) return;
        var ctx = canvas.getContext('2d');
        var drawing = false, dirty = false, last = null;

        // Match the backing store to the element so the line is not blurred,
        // and redraw nothing — a resize clears the pad, which is honest.
        function size() {
            var ratio = window.devicePixelRatio || 1;
            var rect = canvas.getBoundingClientRect();
            if (!rect.width) return;
            canvas.width = Math.round(rect.width * ratio);
            canvas.height = Math.round(rect.height * ratio);
            ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
            ctx.lineWidth = 2;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.strokeStyle = '#0f172a';
        }

        function point(e) {
            var rect = canvas.getBoundingClientRect();
            var t = e.touches ? e.touches[0] : e;
            return { x: t.clientX - rect.left, y: t.clientY - rect.top };
        }
        function start(e) {
            e.preventDefault();
            drawing = true;
            last = point(e);
            if (hint) hint.style.display = 'none';
        }
        function move(e) {
            if (!drawing) return;
            e.preventDefault();
            var p = point(e);
            ctx.beginPath();
            ctx.moveTo(last.x, last.y);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            last = p;
            dirty = true;
        }
        function stop() {
            if (!drawing) return;
            drawing = false;
            if (dirty) {
                try { field.value = canvas.toDataURL('image/png'); } catch (err) { field.value = ''; }
            }
        }

        canvas.addEventListener('mousedown', start);
        canvas.addEventListener('mousemove', move);
        window.addEventListener('mouseup', stop);
        canvas.addEventListener('touchstart', start, { passive: false });
        canvas.addEventListener('touchmove', move, { passive: false });
        canvas.addEventListener('touchend', stop);

        if (clear) {
            clear.addEventListener('click', function () {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                field.value = '';
                dirty = false;
                if (hint) hint.style.display = '';
            });
        }

        size();
        // A pad inside a dialog has no width until the dialog opens
        if (window.ResizeObserver) {
            var seen = canvas.getBoundingClientRect().width;
            new ResizeObserver(function () {
                var w = canvas.getBoundingClientRect().width;
                if (w && Math.abs(w - seen) > 1 && !dirty) { seen = w; size(); }
            }).observe(canvas);
        }
    }

    function setupAll() { document.querySelectorAll('[data-sigpad]').forEach(setup); }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupAll);
    } else {
        setupAll();
    }
    window.nxSetupSignaturePads = setupAll;
})();
</script>
<?php endif; ?>
