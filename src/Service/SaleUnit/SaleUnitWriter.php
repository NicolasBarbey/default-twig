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

namespace BackOfficeDefaultTwigBundle\Service\SaleUnit;

use BackOfficeDefaultTwigBundle\Repository\SaleUnitRepository;
use Propel\Runtime\Propel;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\SaleUnit\Exception\SaleUnitStillAssignedException;
use Thelia\Model\Map\SaleUnitTableMap;
use Thelia\Model\SaleUnit;

/**
 * Writes the sale units. Every write goes through the model, so the core's listener
 * forgets its cached answer to "does the shop sell from sale units" on each of them.
 */
final readonly class SaleUnitWriter
{
    private const CODE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/';
    private const HOST_PATTERN = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/';

    public function __construct(
        private SaleUnitRepository $saleUnits,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @throws SaleUnitRefusedException when the code or the address cannot be used
     */
    public function create(string $code, ?string $url, string $locale, string $title, ?string $description, bool $active): SaleUnit
    {
        $saleUnit = (new SaleUnit())->setPosition($this->saleUnits->lastPosition() + 1);

        return $this->write($saleUnit, $code, $url, $locale, $title, $description, $active);
    }

    /**
     * @throws SaleUnitRefusedException when the code or the address cannot be used
     */
    public function update(SaleUnit $saleUnit, string $code, ?string $url, string $locale, string $title, ?string $description, bool $active): SaleUnit
    {
        return $this->write($saleUnit, $code, $url, $locale, $title, $description, $active);
    }

    public function toggleActive(SaleUnit $saleUnit): void
    {
        $saleUnit->setActive(!$saleUnit->getActive())->save();
    }

    /**
     * The prices and stocks of the unit go with it. An order placed on it keeps it: the
     * database refuses the deletion, and the merchant is told to turn the unit off instead. The same goes
     * for a unit some administrator is restricted to: it would leave that administrator with no unit,
     * which reads as every unit.
     *
     * @throws SaleUnitRefusedException when orders were placed on the unit, or administrators are restricted to it
     */
    public function delete(SaleUnit $saleUnit): void
    {
        $orders = $this->saleUnits->countOrders((int) $saleUnit->getId());
        if ($orders > 0) {
            throw new SaleUnitRefusedException($this->translator->trans('This sale unit cannot be deleted: %count% order(s) were placed on it. Turn it off instead.', ['%count%' => $orders]));
        }

        try {
            $saleUnit->delete();
        } catch (SaleUnitStillAssignedException) { // @phpstan-ignore catch.neverThrown (thrown by the model's preDelete hook)
            throw new SaleUnitRefusedException($this->translator->trans('This sale unit cannot be deleted: some administrators are restricted to it. Detach them from it first, or turn it off.'));
        }
    }

    /**
     * Moves a unit to a 1-based position and renumbers the others, so positions stay dense.
     */
    public function moveTo(SaleUnit $saleUnit, int $position): void
    {
        $siblings = $this->saleUnits->findSiblingsOf($saleUnit);
        $position = max(1, min($position, \count($siblings) + 1));
        array_splice($siblings, $position - 1, 0, [$saleUnit]);

        $connection = Propel::getConnection(SaleUnitTableMap::DATABASE_NAME);
        $connection->beginTransaction();

        try {
            foreach ($siblings as $index => $sibling) {
                $sibling->setPosition($index + 1)->save($connection);
            }

            $connection->commit();
        } catch (\Throwable $throwable) {
            $connection->rollBack();

            throw $throwable;
        }
    }

    /**
     * What the domain resolver compares with the host of a request: a bare host name,
     * lower case, without scheme, port, path or trailing dot. A merchant pasting the
     * address from the browser bar gets the host out of it.
     *
     * @throws SaleUnitRefusedException when what remains is not a host name
     */
    public function normalizeHost(?string $url): ?string
    {
        $url = trim((string) $url);
        if ('' === $url) {
            return null;
        }

        $host = parse_url(str_contains($url, '://') ? $url : 'http://'.$url, \PHP_URL_HOST);
        $host = \is_string($host) ? rtrim(strtolower($host), '.') : '';

        if ('' === $host || \strlen($host) > 255 || 1 !== preg_match(self::HOST_PATTERN, $host)) {
            throw new SaleUnitRefusedException($this->translator->trans('The site address must be a domain name, such as shop.example.com.'));
        }

        return $host;
    }

    private function write(SaleUnit $saleUnit, string $code, ?string $url, string $locale, string $title, ?string $description, bool $active): SaleUnit
    {
        $code = trim($code);
        $exceptId = $saleUnit->isNew() ? null : (int) $saleUnit->getId();

        if (1 !== preg_match(self::CODE_PATTERN, $code)) {
            throw new SaleUnitRefusedException($this->translator->trans('The code is made of letters, digits, dots, dashes and underscores, 64 characters at most.'));
        }

        if ($this->saleUnits->isCodeTaken($code, $exceptId)) {
            throw new SaleUnitRefusedException($this->translator->trans('Another sale unit already uses the code %code%.', ['%code%' => $code]));
        }

        $host = $this->normalizeHost($url);
        if (null !== $host && $this->saleUnits->isUrlTaken($host, $exceptId)) {
            throw new SaleUnitRefusedException($this->translator->trans('Another sale unit already answers on %host%.', ['%host%' => $host]));
        }

        $description = trim((string) $description);

        $saleUnit
            ->setCode($code)
            ->setUrl($host)
            ->setActive($active)
            ->setLocale($locale)
            ->setTitle(trim($title))
            ->setDescription('' === $description ? null : $description)
            ->save();

        return $saleUnit;
    }
}
