<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/pagination.php';

class PaginationTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
    }

    public function testGetPaginationParamsDefaults(): void
    {
        $_GET = [];
        $params = getPaginationParams();

        $this::assertEquals(1, $params['page']);
        $this::assertEquals(10, $params['limit']);
        $this::assertEquals(0, $params['offset']);
    }

    public function testGetPaginationParamsCustomValues(): void
    {
        $_GET = [
            'page' => '3',
            'limit' => '25'
        ];

        $params = getPaginationParams(10, [5, 10, 25, 50, 100]);

        $this::assertEquals(3, $params['page']);
        $this::assertEquals(25, $params['limit']);
        $this::assertEquals(50, $params['offset']);
    }

    public function testGetPaginationParamsInvalidLimitFallback(): void
    {
        $_GET = [
            'page' => '2',
            'limit' => '999' // Invalid limit not in allowed array
        ];

        $params = getPaginationParams(10, [5, 10, 25, 50]);

        $this::assertEquals(2, $params['page']);
        $this::assertEquals(10, $params['limit']);
        $this::assertEquals(10, $params['offset']);
    }

    public function testCalculatePaginationStats(): void
    {
        $stats = calculatePaginationStats(totalItems: 45, currentPage: 2, limit: 10);
        $this::assertEquals(5, $stats['totalPages']);
        $this::assertEquals(2, $stats['currentPage']);
        $this::assertEquals(11, $stats['startItem']);
        $this::assertEquals(20, $stats['endItem']);

        // Out of bounds page handling
        $statsOutOfBounds = calculatePaginationStats(totalItems: 45, currentPage: 10, limit: 10);
        $this::assertEquals(5, $statsOutOfBounds['currentPage']);

        // Zero items handling
        $statsZero = calculatePaginationStats(totalItems: 0, currentPage: 1, limit: 10);
        $this::assertEquals(1, $statsZero['totalPages']);
        $this::assertEquals(0, $statsZero['startItem']);
        $this::assertEquals(0, $statsZero['endItem']);
    }

    public function testBuildPaginationUrl(): void
    {
        $url = buildPaginationUrl('patients.php', ['q' => 'John'], 2, 10);
        $this::assertEquals('patients.php?q=John&amp;page=2&amp;limit=10', $url);
    }

    public function testRenderPaginationSummary(): void
    {
        $urlBuilder = fn(int $p, int $l) => "patients.php?page={$p}&limit={$l}";
        $html = renderPaginationSummary(11, 20, 45, 10, [5, 10, 25], $urlBuilder);

        $this::assertStringContainsString('Showing <strong class="text-on-surface">11</strong> to <strong class="text-on-surface">20</strong> of <strong class="text-on-surface">45</strong> records', $html);
        $this::assertStringContainsString('patients.php?page=1&limit=5', $html);
    }

    public function testRenderPaginationControls(): void
    {
        $urlBuilder = fn(int $p, int $l) => "patients.php?page={$p}&limit={$l}";

        // Single page should yield empty controls
        $this::assertEquals('', renderPaginationControls(1, 1, 10, $urlBuilder));

        // Multiple pages
        $html = renderPaginationControls(2, 5, 10, $urlBuilder);
        $this::assertStringContainsString('patients.php?page=1&limit=10', $html);
        $this::assertStringContainsString('patients.php?page=3&limit=10', $html);
        $this::assertStringContainsString('Next', $html);
        $this::assertStringContainsString('Prev', $html);
    }

    public function testRenderPaginationOutput(): void
    {
        $html = renderPagination(
            totalItems: 45,
            currentPage: 2,
            limit: 10,
            baseUrl: 'patients.php',
            extraParams: ['q' => 'John']
        );

        $this::assertStringContainsString('Showing <strong class="text-on-surface">11</strong> to <strong class="text-on-surface">20</strong> of <strong class="text-on-surface">45</strong> records', $html);
        $this::assertStringContainsString('patients.php?q=John&amp;page=1&amp;limit=10', $html);
        $this::assertStringContainsString('patients.php?q=John&amp;page=3&amp;limit=10', $html);
        $this::assertStringContainsString('Per page:', $html);
    }
}
