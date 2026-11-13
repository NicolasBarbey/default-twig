<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BackOfficeDefaultTwigBundle\Tests\Twig;

use BackOfficeDefaultTwigBundle\Twig\DataTableExtension;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\Column;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Any column may keep its title for screen readers only, out of sight, whatever its kind.
 */
final class ColumnLabelHiddenTest extends TestCase
{
    /**
     * @return iterable<string, array{callable(DataTableExtension, bool): Column}>
     */
    public static function columns(): iterable
    {
        yield 'text' => [static fn (DataTableExtension $columns, bool $hidden): Column => $columns->textColumn('key', 'Title', labelHidden: $hidden)];
        yield 'html' => [static fn (DataTableExtension $columns, bool $hidden): Column => $columns->htmlColumn('key', 'Title', labelHidden: $hidden)];
        yield 'toggle' => [static fn (DataTableExtension $columns, bool $hidden): Column => $columns->toggleColumn('key', 'Title', null, labelHidden: $hidden)];
        yield 'badge' => [static fn (DataTableExtension $columns, bool $hidden): Column => $columns->badgeColumn('key', 'Title', labelHidden: $hidden)];
        yield 'position' => [static fn (DataTableExtension $columns, bool $hidden): Column => $columns->positionColumn('key', 'Title', labelHidden: $hidden)];
        yield 'actions' => [static fn (DataTableExtension $columns, bool $hidden): Column => $columns->actionsColumn('key', 'Title', labelHidden: $hidden)];
        yield 'radio' => [static fn (DataTableExtension $columns, bool $hidden): Column => $columns->radioColumn('key', 'Title', 'name', labelHidden: $hidden)];
    }

    /**
     * @param callable(DataTableExtension, bool): Column $build
     */
    #[DataProvider('columns')]
    public function testTheTitleIsHiddenOnlyWhenAsked(callable $build): void
    {
        self::assertTrue($build(new DataTableExtension(), true)->options['label_hidden'] ?? false);
        self::assertArrayNotHasKey('label_hidden', $build(new DataTableExtension(), false)->options);
    }
}
