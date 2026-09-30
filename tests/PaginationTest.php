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
