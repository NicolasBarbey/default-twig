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

namespace BackOfficeDefaultTwigBundle\Tests\Http;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The store logo and banner uploaded from the store configuration screen, and the
 * admin route that serves them back.
 */
final class StoreMediaUploadTest extends WebIntegrationTestCase
{
    private const MEDIA_KEYS = ['logo_file', 'banner_file', 'favicon_file'];

    private const SVG_WITH_HANDLERS = '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" onload="alert(document.domain)">'
        .'<script>alert(1)</script><rect width="10" height="10" fill="red"/></svg>';

    private AdminSessionInjector $injector;

    /** @var list<string> */
    private array $files = [];

    /** @var list<string> */
    private array $directories = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped(
                'The Twig back-office is not the active admin template of the test shop: its routes are not registered.',
            );
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        $admin = (new FixtureFactory($this->getPropelConnection()))->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);

        // Saving a new file removes the previous one from the disk: keep the shop files out of reach.
        foreach (self::MEDIA_KEYS as $key) {
            ConfigQuery::write($key, '', false);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->injector->clear();

            foreach (self::MEDIA_KEYS as $key) {
                $stored = ConfigQuery::read($key);
                if (\is_string($stored) && $stored !== '') {
                    $this->files[] = $this->storeDirectory().\DIRECTORY_SEPARATOR.$stored;
                }
            }
        }

        foreach ($this->files as $file) {
            @unlink($file);
        }

        foreach ($this->directories as $directory) {
            @rmdir($directory);
        }

        ConfigQuery::resetCache();
        parent::tearDown();
    }

    public function testAnSvgLogoIsStoredWithoutItsScriptsAndHandlers(): void
    {
        $this->saveStoreMedia(['logo_file' => $this->file('logo.svg', self::SVG_WITH_HANDLERS)]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode(), 'A drawable SVG logo is accepted.');

        $stored = (string) file_get_contents($this->storedPath('logo_file'));
        self::assertStringNotContainsStringIgnoringCase('onload', $stored);
        self::assertStringNotContainsStringIgnoringCase('<script', $stored);
        self::assertStringContainsString('<rect', $stored, 'The drawing itself is kept.');
    }

    public function testAnSvgBannerIsStoredWithoutItsScriptsAndHandlers(): void
    {
        $this->saveStoreMedia(['banner_file' => $this->file('banner.svg', self::SVG_WITH_HANDLERS)]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode(), 'A drawable SVG banner is accepted.');

        $stored = (string) file_get_contents($this->storedPath('banner_file'));
        self::assertStringNotContainsStringIgnoringCase('onload', $stored);
        self::assertStringNotContainsStringIgnoringCase('<script', $stored);
    }

    public function testAPngLogoIsStoredAsItIs(): void
    {
        $png = $this->png();

        $this->saveStoreMedia(['logo_file' => $this->file('logo.png', $png)]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame($png, file_get_contents($this->storedPath('logo_file')));
    }

    public function testALogoOfAnotherImageTypeIsRefused(): void
    {
        $bmp = sys_get_temp_dir().\DIRECTORY_SEPARATOR.uniqid('thelia_test_store_').'.bmp';
        imagebmp(imagecreatetruecolor(2, 2), $bmp);
        $this->files[] = $bmp;

        $this->saveStoreMedia(['logo_file' => $bmp]);

        self::assertNotSame(302, $this->client->getResponse()->getStatusCode(), 'Only the listed image types may become the store logo.');
        self::assertSame('', ConfigQuery::read('logo_file'));
    }

    public function testALogoNamedWithAnotherExtensionThanItsContentIsRefused(): void
    {
        $this->saveStoreMedia(['logo_file' => $this->file('logo.html', $this->png())]);

        self::assertNotSame(302, $this->client->getResponse()->getStatusCode(), 'The file name has to match what the file is.');
        self::assertSame('', ConfigQuery::read('logo_file'));
    }

    public function testAnSvgStoreMediaIsServedAsAnInertImage(): void
    {
        $this->storeFile('logo_file', 'logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="1" height="1"/></svg>');

        $this->client->request('GET', '/admin/store-media/logo');
        $response = $this->client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/svg+xml', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame("default-src 'none'; style-src 'unsafe-inline'; sandbox", $response->headers->get('Content-Security-Policy'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'), 'An admin-only file is not kept by shared caches.');
    }

    public function testARasterStoreMediaIsServedInline(): void
    {
        $this->storeFile('banner_file', 'banner.png', $this->png());

        $this->client->request('GET', '/admin/store-media/banner');
        $response = $this->client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertNull($response->headers->get('Content-Disposition'));
    }

    public function testAStoreMediaThatIsNotAnImageIsDownloaded(): void
    {
        $this->storeFile('logo_file', 'logo.html', '<html><body>text</body></html>');

        $this->client->request('GET', '/admin/store-media/logo');
        $response = $this->client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /**
     * @param array<string, string> $uploads form field => path of the file to upload
     */
    private function saveStoreMedia(array $uploads): void
    {
        $crawler = $this->client->request('GET', '/admin/configuration/store');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $form = $crawler->filter('[data-testid="config-store-save-stay"]')->form([
            'thelia_configuration_store[store_name]' => 'Test Store',
            'thelia_configuration_store[store_email]' => 'store@test.com',
            'thelia_configuration_store[store_notification_emails]' => 'store@test.com',
            'thelia_configuration_store[store_address1]' => '1 Main Street',
            'thelia_configuration_store[store_zipcode]' => '75001',
            'thelia_configuration_store[store_city]' => 'Paris',
        ]);

        foreach ($uploads as $field => $path) {
            $form['thelia_configuration_store['.$field.']']->upload($path);
        }

        $this->client->submit($form);
        ConfigQuery::resetCache();
    }

    private function storedPath(string $key): string
    {
        $stored = ConfigQuery::read($key);
        self::assertIsString($stored);
        self::assertNotSame('', $stored, \sprintf('The %s was stored.', $key));

        $path = $this->storeDirectory().\DIRECTORY_SEPARATOR.$stored;
        self::assertFileExists($path);

        return $path;
    }

    private function storeFile(string $key, string $name, string $content): void
    {
        $fileName = uniqid().'-'.$name;
        $path = $this->storeDirectory().\DIRECTORY_SEPARATOR.$fileName;
        file_put_contents($path, $content);
        $this->files[] = $path;

        ConfigQuery::write($key, $fileName, false);
    }

    private function file(string $name, string $content): string
    {
        $directory = sys_get_temp_dir().\DIRECTORY_SEPARATOR.uniqid('thelia_test_store_');
        mkdir($directory);
        $this->directories[] = $directory;
        $path = $directory.\DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, $content);
        $this->files[] = $path;

        return $path;
    }

    private function png(): string
    {
        ob_start();
        imagepng(imagecreatetruecolor(2, 2));

        return (string) ob_get_clean();
    }

    private function storeDirectory(): string
    {
        $configured = ConfigQuery::read('images_library_path');
        $base = \is_string($configured) && $configured !== ''
            ? THELIA_ROOT.$configured
            : THELIA_LOCAL_DIR.'media'.\DIRECTORY_SEPARATOR.'images';

        $directory = $base.\DIRECTORY_SEPARATOR.'store';
        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        return $directory;
    }
}
