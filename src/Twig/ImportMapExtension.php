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

namespace BackOfficeDefaultTwigBundle\Twig;

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\AssetMapper\MappedAsset;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Renders the importmap of the back-office pages.
 *
 * The application importmap.php belongs to the front-office theme, so the
 * back-office cannot register entries in it. This extension renders a
 * page-local <script type="importmap"> instead, built from the asset map:
 * the bare module names the theme JavaScript imports, plus every relative
 * import of the module graph keyed by its undigested public path — the same
 * entries importmap() would emit — so the digested files produced by
 * asset-map:compile stay resolvable in production.
 */
final class ImportMapExtension extends AbstractExtension
{
    private const ENTRYPOINT = 'backoffice/app.js';

    /**
     * Bare import name => logical path in the asset map.
     */
    private const BARE_IMPORTS = [
        '@hotwired/stimulus' => 'backoffice/vendor/stimulus.js',
        'bootstrap' => 'backoffice-bootstrap/bootstrap.esm.min.js',
        '@popperjs/core' => 'backoffice/vendor/popper.js',
        'htmx.org' => 'backoffice/vendor/htmx.esm.js',
        'chart.js' => 'backoffice/vendor/chart.js',
        '@kurkle/color' => 'backoffice/vendor/color.esm.js',
        'qrcode-generator' => 'backoffice/vendor/qrcode.mjs',
    ];

    /**
     * Relative imports the AssetMapper compiler fails to detect, mapped by hand.
     * chart.js imports its helpers chunk with a named-import list containing "$",
     * which Symfony's import parser does not match, so the chunk never reaches
     * the import graph walked below. The entry keys itself by its undigested
     * public path, exactly as a detected relative import would.
     */
    private const UNDETECTED_RELATIVE_IMPORTS = [
        'backoffice/vendor/chunks/helpers.dataset.js',
    ];

    public function __construct(
        private readonly AssetMapperInterface $assetMapper,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('bo_importmap', $this->render(...), ['is_safe' => ['html']]),
        ];
    }

    public function render(): string
    {
        $imports = [];
        $preloads = [];
        $seen = [];

        $entrypoint = $this->requireAsset(self::ENTRYPOINT, 'the back-office entrypoint');
        $preloads[] = $entrypoint->publicPath;
        $this->collectRelativeImports($entrypoint, $imports, $preloads, $seen);

        foreach (self::BARE_IMPORTS as $name => $logicalPath) {
            $asset = $this->requireAsset($logicalPath, \sprintf('the "%s"', $name));
            $imports[$name] = $asset->publicPath;
            $this->collectRelativeImports($asset, $imports, $preloads, $seen);
        }

        foreach (self::UNDETECTED_RELATIVE_IMPORTS as $logicalPath) {
            $asset = $this->requireAsset($logicalPath, \sprintf('the "%s"', $logicalPath));
            $imports[$asset->publicPathWithoutDigest] = $asset->publicPath;
            $this->collectRelativeImports($asset, $imports, $preloads, $seen);
        }

        $json = json_encode(
            ['imports' => $imports],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_PRETTY_PRINT,
        );

        $html = '<script type="importmap">'.$json.'</script>'.\PHP_EOL;
        foreach ($preloads as $path) {
            $html .= \sprintf('<link rel="modulepreload" href="%s">', htmlspecialchars($path, \ENT_QUOTES)).\PHP_EOL;
        }

        return $html;
    }

    /**
     * Walks the JavaScript import graph of an asset and maps every relative
     * import (rewritten by AssetMapper to an undigested public path) to the
     * digested public path of its target.
     *
     * @param array<string, string>   $imports
     * @param list<string>            $preloads
     * @param array<string, bool>     $seen
     */
    private function collectRelativeImports(MappedAsset $asset, array &$imports, array &$preloads, array &$seen): void
    {
        if (isset($seen[$asset->logicalPath])) {
            return;
        }
        $seen[$asset->logicalPath] = true;

        foreach ($asset->getJavaScriptImports() as $import) {
            // Bare imports are pinned explicitly in BARE_IMPORTS.
            if (!$import->addImplicitlyToImportMap) {
                continue;
            }

            $imported = $this->assetMapper->getAsset($import->assetLogicalPath);
            if (!$imported instanceof MappedAsset) {
                continue;
            }

            if (!isset($imports[$import->importName])) {
                $imports[$import->importName] = $imported->publicPath;

                if (!$import->isLazy) {
                    $preloads[] = $imported->publicPath;
                }
            }

            $this->collectRelativeImports($imported, $imports, $preloads, $seen);
        }
    }

    private function requireAsset(string $logicalPath, string $description): MappedAsset
    {
        $asset = $this->assetMapper->getAsset($logicalPath);

        if (!$asset instanceof MappedAsset) {
            throw new \RuntimeException(\sprintf('For the back-office importmap, %s vendor asset is missing from the asset map (logical path "%s"): install the theme Composer dependencies and clear the cache.', $description, $logicalPath));
        }

        return $asset;
    }
}
