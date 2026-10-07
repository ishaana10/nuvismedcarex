<?php
/**
 * Reusable Pagination Helper Functions
 */

if (!function_exists('getPaginationParams')) {
    /**
     * Parses and validates pagination parameters from $_GET.
     *
     * @param int $defaultLimit Default items per page.
     * @param array $allowedLimits Allowed limits dropdown values.
     * @return array Array containing 'page', 'limit', and 'offset'.
     */
    function getPaginationParams(
        int $defaultLimit = 10,
        array $allowedLimits = [5, 10, 25, 50, 100],
        ?PDO $pdo = null,
        string $pageParam = 'page',
        string $limitParam = 'limit'
    ): array {
        if ($pdo !== null && !isset($_GET[$limitParam])) {
            try {
                $stmt = $pdo->prepare("SELECT setting_value FROM clinic_settings WHERE setting_key = 'default_pagination_limit'");
                $stmt->execute();
                $dbVal = $stmt->fetchColumn();
                if ($dbVal && in_array((int)$dbVal, $allowedLimits, true)) {
                    $defaultLimit = (int)$dbVal;
                }
            } catch (\Throwable $e) {
                // fallback to passed default
            }
        }

        $limit = isset($_GET[$limitParam]) ? (int)$_GET[$limitParam] : $defaultLimit;
        if (!in_array($limit, $allowedLimits, true)) {
            $limit = $defaultLimit;
        }

        $page = isset($_GET[$pageParam]) ? (int)$_GET[$pageParam] : 1;
        if ($page < 1) {
            $page = 1;
        }

        $offset = ($page - 1) * $limit;

        return [
            'page' => $page,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }
}

if (!function_exists('renderPagination')) {
    /**
     * Renders clean pagination controls with page links and items-per-page limit selection.
     *
     * @param int $totalItems Total record count.
     * @param int $currentPage Current page number.
     * @param int $limit Items per page limit.
     * @param string $baseUrl Base URL for pagination links (e.g. 'patients.php').
     * @param array $extraParams Additional GET query parameters to preserve (e.g. ['q' => 'search', 'tab' => 'invoices']).
     * @param array $allowedLimits Allowed items per page options.
     * @return string Rendered HTML string.
     */
    function renderPagination(
        int $totalItems,
        int $currentPage,
        int $limit,
        string $baseUrl,
        array $extraParams = [],
        array $allowedLimits = [5, 10, 25, 50, 100],
        string $pageParam = 'page',
        string $limitParam = 'limit'
    ): string {
        $totalPages = (int)ceil($totalItems / max($limit, 1));
        if ($totalPages < 1) {
            $totalPages = 1;
        }

        if ($currentPage > $totalPages) {
            $currentPage = $totalPages;
        }

        $startItem = $totalItems > 0 ? (($currentPage - 1) * $limit) + 1 : 0;
        $endItem = min($currentPage * $limit, $totalItems);

        $buildUrl = function(int $targetPage, int $targetLimit) use ($baseUrl, $extraParams, $pageParam, $limitParam) {
            $params = array_merge($extraParams, [
                $pageParam => $targetPage,
                $limitParam => $targetLimit,
            ]);
            return htmlspecialchars($baseUrl . '?' . http_build_query($params));
        };

        ob_start();
        ?>
        <div class="flex flex-col sm:flex-row items-center justify-between gap-4 py-3 px-4 bg-surface-container-low/40 rounded-2xl border border-outline-variant/30 text-xs text-on-surface-variant mt-4">
            <!-- Summary & Items Per Page Selector -->
            <div class="flex items-center gap-3">
                <span>
                    Showing <strong class="text-on-surface"><?= $startItem ?></strong> to <strong class="text-on-surface"><?= $endItem ?></strong> of <strong class="text-on-surface"><?= $totalItems ?></strong> records
                </span>

                <div class="flex items-center gap-1.5 ml-2">
                    <label for="pagination_limit_select" class="text-outline font-medium text-[11px]">Per page:</label>
                    <select id="pagination_limit_select" onchange="window.location.href=this.value;" class="bg-surface-container-lowest border border-outline-variant/40 rounded-lg pl-2.5 pr-7 py-1 text-xs font-bold text-primary focus:outline-none focus:ring-1 focus:ring-primary cursor-pointer min-w-[68px]">
                        <?php foreach ($allowedLimits as $opt): ?>
                            <option value="<?= $buildUrl(1, $opt) ?>" <?= $opt === $limit ? 'selected' : '' ?>>
                                <?= $opt ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Page Number Links & Controls -->
            <?php if ($totalPages > 1): ?>
                <div class="flex items-center gap-1">
                    <!-- Previous Button -->
                    <?php if ($currentPage > 1): ?>
                        <a href="<?= $buildUrl($currentPage - 1, $limit) ?>" class="px-2.5 py-1.5 rounded-lg bg-surface-container-lowest border border-outline-variant/30 hover:bg-surface-container-high font-semibold transition flex items-center gap-0.5" title="Previous Page">
                            <span class="material-symbols-outlined text-sm">chevron_left</span>
                            <span>Prev</span>
                        </a>
                    <?php else: ?>
                        <span class="px-2.5 py-1.5 rounded-lg bg-surface-container-low/50 text-outline border border-outline-variant/20 cursor-not-allowed font-semibold flex items-center gap-0.5">
                            <span class="material-symbols-outlined text-sm">chevron_left</span>
                            <span>Prev</span>
                        </span>
                    <?php endif; ?>

                    <!-- Page Numbers -->
                    <?php
                    $startPage = max(1, $currentPage - 2);
                    $endPage = min($totalPages, $currentPage + 2);

                    if ($startPage > 1) {
                        echo '<a href="' . $buildUrl(1, $limit) . '" class="px-3 py-1.5 rounded-lg bg-surface-container-lowest border border-outline-variant/30 hover:bg-surface-container-high font-semibold transition">1</a>';
                        if ($startPage > 2) {
                            echo '<span class="px-1 text-outline">...</span>';
                        }
                    }

                    for ($i = $startPage; $i <= $endPage; $i++) {
                        if ($i === $currentPage) {
                            echo '<span class="px-3 py-1.5 rounded-lg bg-primary text-white font-bold shadow-xs">' . $i . '</span>';
                        } else {
                            echo '<a href="' . $buildUrl($i, $limit) . '" class="px-3 py-1.5 rounded-lg bg-surface-container-lowest border border-outline-variant/30 hover:bg-surface-container-high font-semibold transition">' . $i . '</a>';
                        }
                    }

                    if ($endPage < $totalPages) {
                        if ($endPage < $totalPages - 1) {
                            echo '<span class="px-1 text-outline">...</span>';
                        }
                        echo '<a href="' . $buildUrl($totalPages, $limit) . '" class="px-3 py-1.5 rounded-lg bg-surface-container-lowest border border-outline-variant/30 hover:bg-surface-container-high font-semibold transition">' . $totalPages . '</a>';
                    }
                    ?>

                    <!-- Next Button -->
                    <?php if ($currentPage < $totalPages): ?>
                        <a href="<?= $buildUrl($currentPage + 1, $limit) ?>" class="px-2.5 py-1.5 rounded-lg bg-surface-container-lowest border border-outline-variant/30 hover:bg-surface-container-high font-semibold transition flex items-center gap-0.5" title="Next Page">
                            <span>Next</span>
                            <span class="material-symbols-outlined text-sm">chevron_right</span>
                        </a>
                    <?php else: ?>
                        <span class="px-2.5 py-1.5 rounded-lg bg-surface-container-low/50 text-outline border border-outline-variant/20 cursor-not-allowed font-semibold flex items-center gap-0.5">
                            <span>Next</span>
                            <span class="material-symbols-outlined text-sm">chevron_right</span>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
